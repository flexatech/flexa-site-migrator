<?php
namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Serialize-safe search-replace, used by the importer on the staging side.
 */
class Replace {

	/**
	 * Build search-replace pairs: each domain is mapped for both schemes (scheme preserved).
	 *   https://old  -> https://new
	 *   http://old   -> http://new
	 * We do NOT replace the bare domain (old.com) to avoid touching emails/other strings.
	 */
	public static function build_pairs( $old_url, $new_url, $old_home = '', $new_home = '', $old_path = '', $new_path = '' ) {
		$bare = function ( $u ) { return preg_replace( '#^https?://#i', '', rtrim( (string) $u, '/' ) ); };
		$pairs = array();
		$add = function ( $ob, $nb ) use ( &$pairs ) {
			if ( '' === $ob || $ob === $nb ) { return; }
			$pairs[] = array( 'https://' . $ob, 'https://' . $nb );
			$pairs[] = array( 'http://' . $ob, 'http://' . $nb );
		};

		$ob = $bare( $old_url );
		$nb = $bare( $new_url );
		$add( $ob, $nb );

		$ohb = $bare( $old_home );
		$nhb = $bare( '' !== $new_home ? $new_home : $new_url );
		if ( '' !== $ohb && $ohb !== $ob ) {
			$add( $ohb, $nhb );
		}

		// Absolute filesystem path (ABSPATH) — only when they differ.
		if ( '' !== $old_path && $old_path !== $new_path ) {
			$pairs[] = array( $old_path, $new_path );
		}
		return $pairs;
	}

	/** Recursively unserialize -> replace -> re-serialize. */
	public static function recursive( $from, $to, $data, $serialised = false ) {
		try {
			if ( is_string( $data ) && '' !== $data && ( $un = @unserialize( $data ) ) !== false ) {
				$data = self::recursive( $from, $to, $un, true );
			} elseif ( is_array( $data ) ) {
				$tmp = array();
				foreach ( $data as $k => $v ) {
					$tmp[ $k ] = self::recursive( $from, $to, $v, false );
				}
				$data = $tmp;
			} elseif ( is_object( $data ) ) {
				$tmp = clone $data;
				foreach ( get_object_vars( $data ) as $k => $v ) {
					$tmp->$k = self::recursive( $from, $to, $v, false );
				}
				$data = $tmp;
			} elseif ( is_string( $data ) ) {
				$data = str_replace( $from, $to, $data );
			}
			if ( $serialised ) {
				return serialize( $data );
			}
		} catch ( \Exception $e ) {}
		return $data;
	}

	/**
	 * Backtick-quote a MySQL identifier (table / column name) so it is safe to
	 * interpolate into a query. Names come from the schema (SHOW TABLES/COLUMNS),
	 * never from a request, but we escape any embedded backtick defensively.
	 */
	private static function esc_id( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/**
	 * Run search-replace across all tables of a mysqli connection.
	 * $pairs = [ [from, to], ... ]. Returns the number of updated cells.
	 */
	public static function run( $mysqli, array $pairs ) {
		$changed = 0;
		$tables  = array();
		$res     = mysqli_query( $mysqli, 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Keyset-paginated search-replace over large tables using WP's own mysqli handle ($wpdb->dbh); $wpdb->query buffers all rows and cannot stream.
		while ( $row = mysqli_fetch_row( $res ) ) { // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_row -- via WP's own mysqli handle ($wpdb->dbh).
			$tables[] = $row[0];
		}

		foreach ( $tables as $table ) {
			$cols = array();
			$pk   = null;
			$cres = mysqli_query( $mysqli, 'SHOW COLUMNS FROM ' . self::esc_id( $table ) ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Keyset-paginated search-replace over large tables using WP's own mysqli handle ($wpdb->dbh); $wpdb->query buffers all rows and cannot stream.
			while ( $c = mysqli_fetch_assoc( $cres ) ) { // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_assoc -- via WP's own mysqli handle ($wpdb->dbh).
				$cols[] = $c['Field'];
				if ( 'PRI' === $c['Key'] && null === $pk ) {
					$pk = $c['Field'];
				}
			}
			if ( ! $cols ) {
				continue;
			}

			$rres = mysqli_query( $mysqli, 'SELECT * FROM ' . self::esc_id( $table ), MYSQLI_USE_RESULT ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Keyset-paginated search-replace over large tables using WP's own mysqli handle ($wpdb->dbh); $wpdb->query buffers all rows and cannot stream.
			if ( ! $rres ) {
				continue;
			}
			$updates = array();
			while ( $row = mysqli_fetch_assoc( $rres ) ) { // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_assoc -- via WP's own mysqli handle ($wpdb->dbh).
				$new   = $row;
				$dirty = false;
				foreach ( $cols as $col ) {
					$val = $row[ $col ];
					if ( null === $val ) {
						continue;
					}
					$rep = $val;
					foreach ( $pairs as $p ) {
						if ( '' !== $p[0] && strpos( $rep, $p[0] ) !== false ) {
							$rep = self::recursive( $p[0], $p[1], $rep );
						}
					}
					if ( $rep !== $val ) {
						$new[ $col ] = $rep;
						$dirty = true;
					}
				}
				if ( $dirty ) {
					$updates[] = array( 'row' => $row, 'new' => $new );
				}
			}
			mysqli_free_result( $rres ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_free_result -- via WP's own mysqli handle ($wpdb->dbh).

			foreach ( $updates as $u ) {
				$sets = array();
				foreach ( $cols as $col ) {
					if ( $u['new'][ $col ] !== $u['row'][ $col ] ) {
						$sets[] = self::esc_id( $col ) . "='" . mysqli_real_escape_string( $mysqli, $u['new'][ $col ] ) . "'"; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string -- via WP's own mysqli handle ($wpdb->dbh).
					}
				}
				if ( ! $sets ) {
					continue;
				}
				if ( $pk && isset( $u['row'][ $pk ] ) ) {
					$where = self::esc_id( $pk ) . "='" . mysqli_real_escape_string( $mysqli, $u['row'][ $pk ] ) . "'"; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string -- via WP's own mysqli handle ($wpdb->dbh).
				} else {
					$conds = array();
					foreach ( $cols as $col ) {
						$conds[] = ( null === $u['row'][ $col ] )
							? self::esc_id( $col ) . ' IS NULL'
							: self::esc_id( $col ) . "='" . mysqli_real_escape_string( $mysqli, $u['row'][ $col ] ) . "'"; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string -- via WP's own mysqli handle ($wpdb->dbh).
					}
					$where = implode( ' AND ', $conds );
				}
				if ( @mysqli_query( $mysqli, 'UPDATE ' . self::esc_id( $table ) . ' SET ' . implode( ',', $sets ) . " WHERE $where LIMIT 1" ) ) { // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Keyset-paginated search-replace over large tables using WP's own mysqli handle ($wpdb->dbh); $wpdb->query buffers all rows and cannot stream.
					$changed++;
				}
			}
		}
		return $changed;
	}
}

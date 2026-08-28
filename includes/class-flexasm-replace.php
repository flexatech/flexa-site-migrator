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

	const ROWS_PER_PAGE = 500;

	/**
	 * Run search-replace across all tables via $wpdb, paginated so only one page
	 * of rows is in memory at a time (keyset on the primary key when there is a
	 * single-column one, LIMIT/OFFSET otherwise).
	 * $pairs = [ [from, to], ... ]. Returns the number of updated cells.
	 */
	public static function run( array $pairs ) {
		global $wpdb;
		$changed = 0;
		// Table/column identifiers come from the schema itself (SHOW TABLES /
		// SHOW COLUMNS), never from a request, and are bound with the %i
		// identifier placeholder (WP 6.2+). All values go through prepare().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Search-replace over the just-imported database; no higher-level API exists and caching does not apply.
		$tables = $wpdb->get_col( 'SHOW TABLES' );

		foreach ( $tables as $table ) {
			$cols = array();
			$pks  = array();
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A ) as $c ) {
				$cols[] = $c['Field'];
				if ( 'PRI' === $c['Key'] ) {
					$pks[] = $c['Field'];
				}
			}
			if ( ! $cols ) {
				continue;
			}
			$pk = ( 1 === count( $pks ) ) ? $pks[0] : null;

			$last_pk = null;
			$offset  = 0;
			do {
				$rows = self::page_rows( $wpdb, $table, $pk, $last_pk, $offset );
				$got  = count( $rows );

				foreach ( $rows as $row ) {
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
					if ( $dirty && self::update_row( $table, $cols, $pk, $row, $new ) ) {
						$changed++;
					}
				}

				if ( $pk && $got > 0 ) {
					$last_pk = $rows[ $got - 1 ][ $pk ];
				}
				$offset += $got;
			} while ( $got === self::ROWS_PER_PAGE );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $changed;
	}

	/**
	 * One page of rows for the search-replace walk: keyset pagination when the
	 * table has a single primary key (stable + fast), else LIMIT/OFFSET. Each
	 * branch hands a $wpdb->prepare() with a CONSTANT format string (identifiers
	 * bound with %i, values with %s/%d) straight to get_results — the query is
	 * never assembled from a variable.
	 */
	private static function page_rows( $wpdb, $table, $pk, $last_pk, $offset ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chunked search-replace over every table; no higher-level API applies.
		if ( $pk && null !== $last_pk ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d', $table, $pk, $last_pk, $pk, self::ROWS_PER_PAGE ), ARRAY_A );
		}
		if ( $pk ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY %i ASC LIMIT %d', $table, $pk, self::ROWS_PER_PAGE ), ARRAY_A );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, self::ROWS_PER_PAGE, $offset ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** UPDATE one changed row (by primary key, or by matching every original value when there is none). */
	private static function update_row( $table, $cols, $pk, $row, $new ) {
		global $wpdb;
		$sets = array();
		$args = array( $table );
		foreach ( $cols as $col ) {
			if ( $new[ $col ] !== $row[ $col ] ) {
				$sets[] = '%i = %s';
				$args[] = $col;
				$args[] = $new[ $col ];
			}
		}
		if ( ! $sets ) {
			return false;
		}
		if ( $pk && isset( $row[ $pk ] ) ) {
			$where  = '%i = %s';
			$args[] = $pk;
			$args[] = $row[ $pk ];
		} else {
			$conds = array();
			foreach ( $cols as $col ) {
				if ( null === $row[ $col ] ) {
					$conds[] = '%i IS NULL';
					$args[]  = $col;
				} else {
					$conds[] = '%i = %s';
					$args[]  = $col;
					$args[]  = $row[ $col ];
				}
			}
			$where = implode( ' AND ', $conds );
		}
		// The SET/WHERE fragments joined below are literal '%i = %s' / '%i IS NULL'
		// placeholder pairs only — every identifier and every value is bound by prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (bool) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET ' . implode( ', ', $sets ) . ' WHERE ' . $where . ' LIMIT 1',
				$args
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}

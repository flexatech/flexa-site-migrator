<?php
namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Importer that runs on STAGING via wp-admin.
 * - Scans available packages in <uploads>/flexasm-packages/.
 * - Extracts files in chunks (DB untouched yet -> auth still intact).
 * - Imports DB + search-replace in a SINGLE request (auth verified at request start).
 */
class Importer {

	const EXTRACT_BATCH = 300;

	/** Package storage dir relative to ABSPATH (as it appears in archive entry names). */
	private static function packages_rel_path() {
		$root = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/'; // ABSPATH is the install root the archive entries are relative to.
		$pkg  = rtrim( str_replace( '\\', '/', FLEXASM_PACKAGE_DIR ), '/' ) . '/';
		return ( 0 === strpos( $pkg, $root ) ) ? substr( $pkg, strlen( $root ) ) : $pkg;
	}

	/** Files to exclude when extracting onto staging. */
	private static function excluded( $name ) {
		$skip = array(
			'wp-content/plugins/flexa-site-migrator/', // don't overwrite ourselves while running
			self::packages_rel_path(), // never overwrite the package store we are extracting from
			'wp-config.php',
			// Production cache drop-ins can cause a fatal on staging (missing Redis/Memcached…).
			'wp-content/object-cache.php',
			'wp-content/advanced-cache.php',
			'wp-content/db.php',
		);
		foreach ( $skip as $s ) {
			if ( $name === $s || 0 === strpos( $name, $s ) ) {
				return true;
			}
		}
		return false;
	}

	/** List valid packages in flexasm-packages. */
	public static function list_packages() {
		$out = array();
		if ( ! is_dir( FLEXASM_PACKAGE_DIR ) ) {
			return $out;
		}
		foreach ( glob( FLEXASM_PACKAGE_DIR . '/*', GLOB_ONLYDIR ) as $dir ) {
			$id = basename( $dir );
			$has_archive = file_exists( "$dir/archive.zip" ) || glob( "$dir/archive-*.zip" );
			if ( file_exists( "$dir/manifest.json" ) && file_exists( "$dir/database.sql" ) && $has_archive ) {
				$m = json_decode( file_get_contents( "$dir/manifest.json" ), true );
				$size = (int) @filesize( "$dir/database.sql" );
				foreach ( glob( "$dir/archive*.zip" ) as $az ) {
					$size += (int) @filesize( $az );
				}
				$out[] = array(
					'id'       => $id,
					'site_url' => $m['site_url'] ?? '?',
					'created'  => $m['created'] ?? '',
					'size'     => size_format( $size ),
				);
			}
		}
		return $out;
	}

	private $dir;
	private $manifest;

	public function __construct( $id ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $id ) ) {
			throw new \Exception( esc_html__( 'Invalid package ID.', 'flexa-site-migrator' ) );
		}
		$this->dir = FLEXASM_PACKAGE_DIR . '/' . $id;
		$this->manifest = json_decode( @file_get_contents( $this->dir . '/manifest.json' ), true );
		if ( ! $this->manifest ) {
			throw new \Exception( esc_html__( 'Could not read the package manifest.', 'flexa-site-migrator' ) );
		}
	}

	/** Step 1: prepare. List the zip parts. */
	public function prepare() {
		// Archive part list (prefer the manifest, fall back to scanning the directory).
		$names = ! empty( $this->manifest['archives'] ) ? $this->manifest['archives'] : array();
		if ( ! $names ) {
			foreach ( array_merge(
				glob( $this->dir . '/archive.zip' ) ?: array(),
				glob( $this->dir . '/archive-*.zip' ) ?: array()
			) as $p ) {
				$names[] = basename( $p );
			}
		}
		sort( $names );

		$parts = array();
		$total = 0;
		foreach ( $names as $name ) {
			$path = $this->dir . '/' . basename( $name );
			if ( ! is_file( $path ) ) {
				/* translators: %s: archive part file name */
				throw new \Exception( esc_html( sprintf( __( 'Missing archive part: %s', 'flexa-site-migrator' ), basename( $name ) ) ) );
			}
			$zip = new \ZipArchive();
			if ( $zip->open( $path ) !== true ) {
				/* translators: %s: archive part file name */
				throw new \Exception( esc_html( sprintf( __( 'Could not open %s', 'flexa-site-migrator' ), basename( $name ) ) ) );
			}
			$entries = $zip->numFiles;
			$zip->close();
			$parts[] = array( 'name' => basename( $name ), 'entries' => $entries );
			$total  += $entries;
		}

		return array(
			'parts'       => $parts,
			'files_total' => $total,
			'old_url'     => rtrim( $this->manifest['site_url'], '/' ),
			'new_url'     => rtrim( get_site_url(), '/' ),
		);
	}

	/** Step 2: extract one chunk of entries from a SINGLE archive part. */
	public function extract( $part_name, $offset ) {
		$part_name = basename( $part_name );
		if ( ! preg_match( '/^archive(-\d+)?\.zip$/', $part_name ) ) {
			throw new \Exception( esc_html__( 'Invalid archive part name.', 'flexa-site-migrator' ) );
		}
		$path = $this->dir . '/' . $part_name;
		if ( ! is_file( $path ) ) {
			/* translators: %s: archive part file name */
			throw new \Exception( esc_html( sprintf( __( 'Archive part not found: %s', 'flexa-site-migrator' ), $part_name ) ) );
		}

		$zip = new \ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			/* translators: %s: archive part file name */
			throw new \Exception( esc_html( sprintf( __( 'Could not open %s', 'flexa-site-migrator' ), $part_name ) ) );
		}
		$total = $zip->numFiles;
		$names = array();
		$end   = min( $offset + self::EXTRACT_BATCH, $total );

		for ( $i = $offset; $i < $end; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = $stat['name'];
			if ( self::excluded( $name ) ) {
				continue;
			}
			if ( substr( $name, -1 ) === '/' ) {
				wp_mkdir_p( ABSPATH . $name ); // ABSPATH is the WP install root; a full-site restore extracts files back into it. No WP function returns the install root.
				continue;
			}
			$names[] = $name;
		}
		if ( $names ) {
			$zip->extractTo( ABSPATH, $names ); // Extract into the WP install root (ABSPATH) — the destination of a full-site restore.
		}
		$zip->close();

		return array(
			'offset' => $end,
			'done'   => ( $end >= $total ),
			'total'  => $total,
		);
	}

	/**
	 * Step 3 (single request): import DB + search-replace + update prefix.
	 * NOT split into chunks, to avoid losing the login session after overwriting the users/options tables.
	 */
	public function deploy_database() {
		global $wpdb;
		// We override neither max_execution_time nor memory_limit here; the admin
		// System check reports both server limits so the user can raise them in
		// php.ini before a single-pass import.
		@ignore_user_abort( true );

		$prod_prefix = $this->manifest['prefix'];
		$stag_prefix = $wpdb->prefix;

		$old_url  = rtrim( $this->manifest['site_url'], '/' );
		$old_home = rtrim( $this->manifest['home_url'], '/' );
		$old_path = rtrim( str_replace( '\\', '/', $this->manifest['abspath'] ), '/' ) . '/';
		$new_url  = rtrim( get_site_url(), '/' );
		// ABSPATH is this install's root — the search-replace target for the source's recorded abspath. No WP function returns the install root.
		$new_path = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';

		// 1) Import SQL (keep the production prefix).
		$stmts = $this->import_sql( $this->dir . '/database.sql' );

		// 2) Make sure this plugin stays active after overwriting options (so the admin page still renders).
		$this->ensure_self_active( $prod_prefix );

		// 3) Safe search-replace (each domain mapped for both http and https).
		$pairs   = Replace::build_pairs( $old_url, $new_url, $old_home, get_home_url(), $old_path, $new_path );
		$changed = Replace::run( $pairs );

		// 4) If the prefixes differ -> update $table_prefix in the staging wp-config.php.
		$prefix_note = '';
		if ( $prod_prefix !== $stag_prefix ) {
			$ok = $this->update_config_prefix( $prod_prefix );
			$prefix_note = $ok
				/* translators: %s: table prefix */
				? sprintf( __( 'Changed the table prefix in wp-config to "%s".', 'flexa-site-migrator' ), $prod_prefix )
				/* translators: %1$s: staging table prefix; %2$s: production table prefix; %3$s: production table prefix to set manually */
				: sprintf( __( '⚠️ Prefixes differ (%1$s → %2$s) but wp-config.php could NOT be written. Please set $table_prefix = \'%3$s\'; manually.', 'flexa-site-migrator' ), $stag_prefix, $prod_prefix, $prod_prefix );
		}

		return array(
			'done'        => true,
			'statements'  => $stmts,
			'changed'     => $changed,
			'prefix_note' => $prefix_note,
			'new_url'     => $new_url,
		);
	}

	/** Import the SQL file (accumulate statements up to the trailing ';' at end of line). */
	private function import_sql( $file ) {
		global $wpdb;
		$fh = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- The dump is read line by line so only one statement is in memory at a time; WP_Filesystem buffers whole files.
		if ( ! $fh ) {
			throw new \Exception( esc_html__( 'Could not read database.sql.', 'flexa-site-migrator' ) );
		}

		// A failing statement must not abort the whole restore (matches mysqldump's
		// own --force behaviour); errors are suppressed for the import loop only.
		$suppress = $wpdb->suppress_errors();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Replaying a trusted dump created by this plugin; the statements ARE the data and have no user-input parameters to prepare.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );

		// Relax strict mode so legacy zero-date defaults (e.g. WooCommerce ActionScheduler's
		// "datetime NOT NULL DEFAULT '0000-00-00 00:00:00'") import on MySQL 5.7+/8.0.
		// Save the current mode and restore it afterwards (this is WP's shared connection).
		$prev_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' );
		$wpdb->query( "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'" );

		$buffer = '';
		$count  = 0;
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$trim = ltrim( $line );
			if ( '' === trim( $line ) || strpos( $trim, '--' ) === 0 ) {
				continue;
			}
			$buffer .= $line;
			if ( substr( rtrim( $line ), -1 ) === ';' ) {
				$wpdb->query( $buffer );
				$buffer = '';
				$count++;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- See fopen note.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
		$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $prev_mode ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- It is prepared.
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$wpdb->suppress_errors( $suppress );
		return $count;
	}

	/** Backtick-quote a MySQL identifier so it is safe to interpolate (prefix comes from the manifest, not a request). */
	private static function esc_id( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/** Add this plugin to active_plugins in the just-imported DB (using the production prefix). */
	private function ensure_self_active( $prefix ) {
		global $wpdb;
		$plugin = 'flexa-site-migrator/flexa-site-migrator.php';
		$table  = self::esc_id( $prefix . 'options' );
		// The prefix comes from the package manifest (not a request) and is backtick-escaped;
		// MySQL has no placeholder for identifiers. Values go through prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Options of the just-imported DB (foreign prefix), unreachable through the WP options API.
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", 'active_plugins' ) );
		if ( null === $raw ) {
			return;
		}
		$list = @unserialize( $raw );
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		if ( ! in_array( $plugin, $list, true ) ) {
			$list[] = $plugin;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET option_value = %s WHERE option_name = %s", serialize( $list ), 'active_plugins' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- active_plugins is stored serialized by WordPress core itself.
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** Update the $table_prefix line in the staging wp-config.php. */
	private function update_config_prefix( $prefix ) {
		// ABSPATH is the WP install root; core locates wp-config.php this same way and no WP function returns the root.
		$path = ABSPATH . 'wp-config.php';
		if ( ! wp_is_writable( $path ) ) {
			return false;
		}
		$src = file_get_contents( $path );
		$new = preg_replace(
			'/\$table_prefix\s*=\s*[\'"][^\'"]*[\'"]\s*;/',
			"\$table_prefix = '" . addslashes( $prefix ) . "';",
			$src,
			1
		);
		if ( $new && $new !== $src ) {
			return (bool) file_put_contents( $path, $new ); // phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Site restore writes into the WP install root by design; the destination is the site being migrated, not plugin data storage.
		}
		return false;
	}
}

<?php
namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Importer that runs on STAGING via wp-admin.
 * - Scans available packages in wp-content/flexasm-packages/.
 * - Extracts files in chunks (DB untouched yet -> auth still intact).
 * - Imports DB + search-replace in a SINGLE request (auth verified at request start).
 */
class Importer {

	const EXTRACT_BATCH = 300;

	/** Files to exclude when extracting onto staging. */
	private static function excluded( $name ) {
		$skip = array(
			'wp-content/plugins/flexa-site-migrator/', // don't overwrite ourselves while running
			'wp-content/flexasm-packages/',
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

	/** Step 1: prepare. List the zip parts + set up the runner for the DB part. */
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

		global $wpdb;
		$old_url  = rtrim( $this->manifest['site_url'], '/' );
		$old_home = rtrim( $this->manifest['home_url'], '/' );
		$old_path = rtrim( str_replace( '\\', '/', $this->manifest['abspath'] ), '/' ) . '/';
		$new_url  = rtrim( get_site_url(), '/' );
		// ABSPATH is this (staging) install's root — the search-replace target for the source's recorded abspath. No WP function returns the install root.
		$new_path = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';

		// Set up the runner (chunked DB) — hashed token + state, no DB password stored.
		$runner_url = null;
		$token      = bin2hex( random_bytes( 32 ) );
		$state = array(
			'abspath'     => str_replace( '\\', '/', ABSPATH ), // WP install root, recorded so the chunked runner can search-replace paths. No WP function returns it.
			'prod_prefix' => $this->manifest['prefix'],
			'stag_prefix' => $wpdb->prefix,
			'old_url'     => $old_url,
			'old_home'    => $old_home,
			'old_path'    => $old_path,
			'new_url'     => $new_url,
			'new_path'    => $new_path,
			'sql'         => 'database.sql',
			'sql_size'    => (int) @filesize( $this->dir . '/database.sql' ),
			'pairs'       => Replace::build_pairs( $old_url, $new_url, $old_home, get_home_url(), $old_path, $new_path ),
			'import'      => array( 'offset' => 0, 'done' => false, 'stmts' => 0 ),
			'replace'     => array( 'started' => false, 'changed' => 0 ),
		);

		$ok_state = false !== file_put_contents( $this->dir . '/flexasm-state.json', wp_json_encode( $state ) );
		$ok_hash  = false !== file_put_contents( $this->dir . '/flexasm-token.hash', hash( 'sha256', $token ) );
		$ok_run   = copy( FLEXASM_PATH . 'templates/runner.tpl', $this->dir . '/runner.php' );

		if ( $ok_state && $ok_hash && $ok_run ) {
			@file_put_contents(
				$this->dir . '/.htaccess',
				"<FilesMatch \"^(flexasm-state\\.json|flexasm-token\\.hash)$\">\n"
				. "  <IfModule mod_authz_core.c>Require all denied</IfModule>\n"
				. "  <IfModule !mod_authz_core.c>Order allow,deny\nDeny from all</IfModule>\n"
				. "</FilesMatch>\n"
			);
			$runner_url = FLEXASM_PACKAGE_URL . '/' . basename( $this->dir ) . '/runner.php';
		}

		return array(
			'parts'       => $parts,
			'files_total' => $total,
			'old_url'     => $old_url,
			'new_url'     => $new_url,
			'runner_url'  => $runner_url,
			'token'       => $token,
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

		$mysqli       = $wpdb->dbh;
		$prod_prefix  = $this->manifest['prefix'];
		$stag_prefix  = $wpdb->prefix;

		$old_url  = rtrim( $this->manifest['site_url'], '/' );
		$old_home = rtrim( $this->manifest['home_url'], '/' );
		$old_path = rtrim( str_replace( '\\', '/', $this->manifest['abspath'] ), '/' ) . '/';
		$new_url  = rtrim( get_site_url(), '/' );
		// ABSPATH is this install's root — the search-replace target for the source's recorded abspath. No WP function returns the install root.
		$new_path = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';

		// 1) Import SQL (keep the production prefix).
		$stmts = $this->import_sql( $mysqli, $this->dir . '/database.sql' );

		// 2) Make sure this plugin stays active after overwriting options (so the admin page still renders).
		$this->ensure_self_active( $mysqli, $prod_prefix );

		// 3) Safe search-replace (each domain mapped for both http and https).
		$pairs   = Replace::build_pairs( $old_url, $new_url, $old_home, get_home_url(), $old_path, $new_path );
		$changed = Replace::run( $mysqli, $pairs );

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
	private function import_sql( $mysqli, $file ) {
		$fh = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked stream I/O for multi-GB package files; WP_Filesystem buffers whole files and cannot seek.
		if ( ! $fh ) {
			throw new \Exception( esc_html__( 'Could not read database.sql.', 'flexa-site-migrator' ) );
		}
		mysqli_query( $mysqli, 'SET FOREIGN_KEY_CHECKS=0' ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Streams the bulk import via WP's own mysqli handle ($wpdb->dbh); $wpdb->query buffers all rows and cannot stream a multi-GB dump.

		// Relax strict mode so legacy zero-date defaults (e.g. WooCommerce ActionScheduler's
		// "datetime NOT NULL DEFAULT '0000-00-00 00:00:00'") import on MySQL 5.7+/8.0.
		// Save the current mode and restore it afterwards (this is WP's shared connection).
		$prev_mode = '';
		$mode_res  = mysqli_query( $mysqli, 'SELECT @@SESSION.sql_mode' ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Streams the bulk import via WP's own mysqli handle; see note above.
		if ( $mode_res ) {
			$row       = mysqli_fetch_row( $mode_res ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_row -- Reads the session sql_mode via WP's own mysqli handle so it can be restored after the streamed import; see note above.
			$prev_mode = is_array( $row ) ? (string) $row[0] : '';
		}
		mysqli_query( $mysqli, "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'" ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Streams the bulk import via WP's own mysqli handle; see note above.

		$buffer = '';
		$count  = 0;
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$trim = ltrim( $line );
			if ( '' === trim( $line ) || strpos( $trim, '--' ) === 0 ) {
				continue;
			}
			$buffer .= $line;
			if ( substr( rtrim( $line ), -1 ) === ';' ) {
				@mysqli_query( $mysqli, $buffer ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Streams the bulk import via WP's own mysqli handle ($wpdb->dbh); $wpdb->query buffers all rows and cannot stream a multi-GB dump.
				$buffer = '';
				$count++;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Chunked stream I/O; see fopen note.
		mysqli_query( $mysqli, 'SET FOREIGN_KEY_CHECKS=1' ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query -- Streams the bulk import via WP's own mysqli handle ($wpdb->dbh); $wpdb->query buffers all rows and cannot stream a multi-GB dump.
		mysqli_query( $mysqli, "SET SESSION sql_mode = '" . mysqli_real_escape_string( $mysqli, $prev_mode ) . "'" ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query, WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string -- Restore WP's original sql_mode on its shared connection; see note above.
		return $count;
	}

	/** Backtick-quote a MySQL identifier so it is safe to interpolate (prefix comes from the manifest, not a request). */
	private static function esc_id( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/** Add this plugin to active_plugins in the just-imported DB (using the production prefix). */
	private function ensure_self_active( $mysqli, $prefix ) {
		$plugin = 'flexa-site-migrator/flexa-site-migrator.php';
		$table  = self::esc_id( $prefix . 'options' );
		$res = @mysqli_query( $mysqli, "SELECT option_value FROM $table WHERE option_name='active_plugins' LIMIT 1" ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Streams the bulk import via WP's own mysqli handle ($wpdb->dbh); $wpdb->query buffers all rows and cannot stream a multi-GB dump.
		if ( ! $res ) {
			return;
		}
		$row = mysqli_fetch_assoc( $res ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_fetch_assoc -- Streaming fetch on WP's own mysqli handle; see query note.
		$list = $row ? @unserialize( $row['option_value'] ) : array();
		if ( ! is_array( $list ) ) {
			$list = array();
		}
		if ( ! in_array( $plugin, $list, true ) ) {
			$list[] = $plugin;
		}
		$val = mysqli_real_escape_string( $mysqli, serialize( $list ) ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string -- Escaping via WP's own mysqli handle ($wpdb->dbh).
		@mysqli_query( $mysqli, "UPDATE $table SET option_value='$val' WHERE option_name='active_plugins'" ); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_query, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Streams the bulk import via WP's own mysqli handle ($wpdb->dbh); $wpdb->query buffers all rows and cannot stream a multi-GB dump.
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

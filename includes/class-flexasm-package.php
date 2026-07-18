<?php
namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Orchestrates the package build process.
 * State is stored in state.json inside the package's working directory.
 */
class Package {

	private $id;
	private $dir;        // working directory: flexasm-packages/<id>
	private $state;

	public function __construct( $id = null ) {
		$this->id  = $id ?: gmdate( 'Ymd_His' ) . '_' . bin2hex( random_bytes( 8 ) );
		$this->dir = FLEXASM_PACKAGE_DIR . '/' . $this->id;
	}

	/**
	 * Create the storage directory and block ALL direct web access to it.
	 * Files are only ever served through admin-ajax (nonce + manage_options)
	 * or the hashed-token pull endpoint, both of which read from disk via PHP.
	 */
	public static function secure_storage_dir() {
		if ( ! is_dir( FLEXASM_PACKAGE_DIR ) ) {
			wp_mkdir_p( FLEXASM_PACKAGE_DIR );
		}
		$rules = "# Flexa Site Migrator package storage — no direct access.\n"
			. "# Files are served through WordPress (admin-ajax / token endpoint).\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";
		$ht    = FLEXASM_PACKAGE_DIR . '/.htaccess';
		if ( ! is_file( $ht ) || file_get_contents( $ht ) !== $rules ) {
			@file_put_contents( $ht, $rules );
		}
		if ( ! is_file( FLEXASM_PACKAGE_DIR . '/index.php' ) ) {
			@file_put_contents( FLEXASM_PACKAGE_DIR . '/index.php', "<?php // Silence is golden.\n" );
		}
	}

	public static function load( $id ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $id ) ) {
			throw new \Exception( esc_html__( 'Invalid package ID.', 'flexa-site-migrator' ) );
		}
		$pkg = new self( $id );
		$pkg->read_state();
		return $pkg;
	}

	private function state_file() { return $this->dir . '/state.json'; }
	private function sql_file()   { return $this->dir . '/database.sql'; }
	private function list_file()  { return $this->dir . '/.filelist'; }

	private function read_state() {
		$raw = @file_get_contents( $this->state_file() );
		if ( false === $raw ) {
			throw new \Exception( esc_html__( 'Package not found.', 'flexa-site-migrator' ) );
		}
		$this->state = json_decode( $raw, true );
	}

	private function save_state() {
		file_put_contents( $this->state_file(), wp_json_encode( $this->state ) );
	}

	/** Step 1: initialize. */
	public function init() {
		self::secure_storage_dir();
		wp_mkdir_p( $this->dir );
		file_put_contents( $this->dir . '/index.php', '<?php // Silence is golden.' );

		$db     = new Database( $this->sql_file() );
		$tables = $db->tables();

		$archive    = new Archive( $this->dir, $this->list_file() );
		$file_count = $archive->build_filelist();

		$this->state = array(
			'id'          => $this->id,
			'site_url'    => get_site_url(),
			'home_url'    => get_home_url(),
			'abspath'     => str_replace( '\\', '/', ABSPATH ), // WP install root, recorded in the manifest so the importer can search-replace absolute paths. No WP function returns it.
			'prefix'      => $GLOBALS['wpdb']->prefix,
			'tables'      => $tables,
			'file_total'  => $file_count,
			'archive'     => array( 'offset' => 0, 'part' => 1, 'part_bytes' => 0, 'parts' => array() ),
			'db'          => array( 'started' => false, 't' => 0, 'o' => 0, 'done' => false ),
			'created'     => gmdate( 'c' ),
		);
		$this->save_state();

		return array(
			'package'    => $this->id,
			'tables'     => count( $tables ),
			'file_total' => $file_count,
		);
	}

	/** Step 2: export the DB (one chunk per call). */
	public function step_database() {
		$db = new Database( $this->sql_file() );

		// On the first run, try mysqldump for speed.
		if ( empty( $this->state['db']['started'] ) && empty( $this->state['db']['tried_dump'] ) ) {
			$this->state['db']['tried_dump'] = true;
			if ( $db->try_mysqldump() ) {
				$this->state['db']['started'] = true;
				$this->state['db']['done']    = true;
				$this->save_state();
				return array( 'done' => true, 'progress' => 100, 'method' => 'mysqldump' );
			}
		}

		$res = $db->export_chunk( $this->state['tables'], $this->state['db'] );
		$this->state['db']         = $res['state'];
		$this->state['db']['done'] = $res['done'];
		$this->save_state();

		$total    = max( 1, count( $this->state['tables'] ) );
		$progress = min( 99, round( ( $res['state']['t'] / $total ) * 100 ) );

		return array(
			'done'     => $res['done'],
			'progress' => $res['done'] ? 100 : $progress,
			'method'   => 'php',
		);
	}

	/** Step 3: compress files (one chunk per call, auto-splitting into parts). */
	public function step_files() {
		$archive = new Archive( $this->dir, $this->list_file() );
		$res = $archive->zip_chunk( $this->state['archive'], (int) $this->state['file_total'] );

		$this->state['archive'] = $res['state'];
		$this->save_state();

		$total    = max( 1, (int) $this->state['file_total'] );
		$progress = min( 99, round( ( (int) $res['state']['offset'] / $total ) * 100 ) );

		return array(
			'done'     => $res['done'],
			'progress' => $res['done'] ? 100 : $progress,
			'parts'    => count( $res['state']['parts'] ),
		);
	}

	/** Step 4: create the manifest, then clean up. */
	public function finalize( $password = '', $allow_ips = array() ) {
		self::secure_storage_dir();
		$parts = isset( $this->state['archive']['parts'] ) ? $this->state['archive']['parts'] : array();
		sort( $parts );

		$manifest = array(
			'version'  => FLEXASM_VERSION,
			'created'  => gmdate( 'c' ),
			'site_url' => $this->state['site_url'],
			'home_url' => $this->state['home_url'],
			'abspath'  => $this->state['abspath'],
			'prefix'   => $this->state['prefix'],
			'db_file'  => 'database.sql',
			'archives' => $parts, // list of zip parts
		);
		file_put_contents( $this->dir . '/manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

		// installer.php is intentionally NOT written to disk: a runnable PHP file
		// must never live in the uploads tree. It is streamed straight from
		// templates/installer.tpl by the download endpoints instead.
		wp_delete_file( $this->list_file() );

		// Pull-by-link: hashed token + one-click link for staging.
		$pull_token = bin2hex( random_bytes( 32 ) );
		file_put_contents( $this->dir . '/pull-token.hash', hash( 'sha256', $pull_token ) );
		// Expires after 48h (the link exposes the customer DB -> must not live indefinitely).
		file_put_contents( $this->dir . '/pull-meta.json', wp_json_encode( array(
			'created'   => time(),
			'expires'   => time() + 48 * 3600,
			'allow_ips' => array_values( (array) $allow_ips ),
		) ) );
		$has_pass = ( '' !== (string) $password );
		if ( $has_pass ) {
			file_put_contents( $this->dir . '/pull-pass.hash', password_hash( (string) $password, PASSWORD_DEFAULT ) );
		}
		$pull_link = trailingslashit( home_url() ) . '?flexasm_pull=' . rawurlencode( $this->id ) . '&key=' . $pull_token;

		$archives = array();
		foreach ( $parts as $p ) {
			$archives[] = array( 'name' => $p, 'url' => self::file_download_url( $this->id, $p ) );
		}

		return array(
			'done'      => true,
			'pull_link' => $pull_link,
			'has_pass'  => $has_pass,
			'files'     => array(
				'package'   => self::package_download_url( $this->id ),
				'installer' => self::installer_download_url( $this->id ),
				'archives'  => $archives,
				'database'  => self::file_download_url( $this->id, 'database.sql' ),
				'manifest'  => self::file_download_url( $this->id, 'manifest.json' ),
			),
			'dir'       => $this->dir,
		);
	}

	/**
	 * installer.php is streamed straight from templates/installer.tpl through
	 * admin-ajax (it never exists on disk inside uploads).
	 */
	public static function installer_download_url( $id ) {
		return add_query_arg(
			array(
				'action'  => 'flexasm_installer',
				'package' => rawurlencode( $id ),
				'nonce'   => wp_create_nonce( 'flexasm_build' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Download URL for one package file (archive part / database.sql /
	 * manifest.json). Direct URLs into uploads are blocked by the storage
	 * .htaccess, so the bytes are streamed through admin-ajax instead.
	 */
	public static function file_download_url( $id, $name ) {
		return add_query_arg(
			array(
				'action'  => 'flexasm_file',
				'package' => rawurlencode( $id ),
				'file'    => rawurlencode( $name ),
				'nonce'   => wp_create_nonce( 'flexasm_build' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * URL that bundles the whole package (installer.php + archive parts +
	 * database.sql + manifest.json) into a single streamed .zip, so the user
	 * downloads once and unzips locally. Routed through admin-ajax because the
	 * package folder also contains a PHP file that direct URLs would block.
	 */
	public static function package_download_url( $id ) {
		return add_query_arg(
			array(
				'action'  => 'flexasm_package_zip',
				'package' => rawurlencode( $id ),
				'nonce'   => wp_create_nonce( 'flexasm_build' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/** Validate an id and return a package handle without requiring state.json. */
	public static function for_id( $id ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $id ) ) {
			throw new \Exception( esc_html__( 'Invalid package ID.', 'flexa-site-migrator' ) );
		}
		return new self( $id );
	}

	/**
	 * List finished packages on this (production) side, with manual-download URLs,
	 * so the admin page can re-display them after a reload.
	 */
	public static function list_all() {
		$out = array();
		if ( ! is_dir( FLEXASM_PACKAGE_DIR ) ) {
			return $out;
		}
		foreach ( glob( FLEXASM_PACKAGE_DIR . '/*', GLOB_ONLYDIR ) as $dir ) {
			$id = basename( $dir );
			if ( ! file_exists( "$dir/manifest.json" ) ) {
				// No manifest -> nothing downloadable. Either staging's post-pull
				// cleanup removed the archives/dump/manifest (finalize ran, so a
				// pull token exists) or the build never finished. Surface both so
				// the leftover directory is visible and deletable from the UI.
				if ( ! file_exists( "$dir/state.json" ) ) {
					continue;
				}
				$state = json_decode( @file_get_contents( "$dir/state.json" ), true );
				$size  = 0;
				foreach ( glob( "$dir/*" ) as $f ) {
					if ( is_file( $f ) ) {
						$size += (int) @filesize( $f );
					}
				}
				$out[] = array(
					'id'        => $id,
					'site_url'  => isset( $state['site_url'] ) ? $state['site_url'] : '',
					'created'   => isset( $state['created'] ) ? $state['created'] : '',
					'size'      => size_format( $size ),
					'files'     => array( 'archives' => array() ),
					'has_token' => false,
					'has_pass'  => false,
					'status'    => file_exists( "$dir/pull-token.hash" ) ? 'cleaned' : 'incomplete',
				);
				continue;
			}
			$m = json_decode( @file_get_contents( "$dir/manifest.json" ), true );

			$archives = array();
			$size     = (int) @filesize( "$dir/database.sql" );
			foreach ( glob( "$dir/archive*.zip" ) as $az ) {
				$archives[] = array( 'name' => basename( $az ), 'url' => self::file_download_url( $id, basename( $az ) ) );
				$size      += (int) @filesize( $az );
			}

			$files = array(
				'package'   => self::package_download_url( $id ),
				'archives'  => $archives,
				'installer' => self::installer_download_url( $id ),
			);
			if ( file_exists( "$dir/database.sql" ) ) { $files['database'] = self::file_download_url( $id, 'database.sql' ); }
			$files['manifest'] = self::file_download_url( $id, 'manifest.json' );

			$out[] = array(
				'id'        => $id,
				'site_url'  => isset( $m['site_url'] ) ? $m['site_url'] : '',
				'created'   => isset( $m['created'] ) ? $m['created'] : '',
				'size'      => size_format( $size ),
				'files'     => $files,
				'has_token' => file_exists( "$dir/pull-token.hash" ),
				'has_pass'  => file_exists( "$dir/pull-pass.hash" ),
				'status'    => 'ready',
			);
		}
		// Newest first (ids are timestamp-prefixed).
		usort( $out, function ( $a, $b ) {
			return strcmp( $b['id'], $a['id'] );
		} );
		return $out;
	}

	/**
	 * Mint a fresh pull token and return a new link. The raw token is shown only
	 * once (only its hash is stored), so a lost link cannot be recovered — it is
	 * regenerated, which invalidates any previously shared link for this package.
	 */
	public function regenerate_link() {
		if ( ! is_dir( $this->dir ) ) {
			throw new \Exception( esc_html__( 'Package not found.', 'flexa-site-migrator' ) );
		}
		// The archives/dump/manifest are deleted after a successful pull (see
		// Pull cleanup) — a fresh link to an emptied package would only mislead.
		if ( ! is_file( $this->dir . '/manifest.json' ) ) {
			throw new \Exception( esc_html__( 'The migration files of this package were removed from this server after a pull — create a new package.', 'flexa-site-migrator' ) );
		}
		$token = bin2hex( random_bytes( 32 ) );
		file_put_contents( $this->dir . '/pull-token.hash', hash( 'sha256', $token ) );

		$meta = json_decode( @file_get_contents( $this->dir . '/pull-meta.json' ), true );
		if ( ! is_array( $meta ) ) {
			$meta = array( 'allow_ips' => array() );
		}
		$meta['created'] = time();
		$meta['expires'] = time() + 48 * 3600;
		file_put_contents( $this->dir . '/pull-meta.json', wp_json_encode( $meta ) );

		return trailingslashit( home_url() ) . '?flexasm_pull=' . rawurlencode( $this->id ) . '&key=' . $token;
	}

	/** Delete this package directory (removes the sensitive DB dump + archives). */
	public function delete() {
		if ( ! is_dir( $this->dir ) ) {
			return;
		}
		foreach ( (array) glob( $this->dir . '/*' ) as $item ) {
			if ( is_file( $item ) ) {
				wp_delete_file( $item );
			}
		}
		// Hidden dot-files written into the package dir (index.php is caught above; .htaccess/.filelist are not).
		foreach ( array( '.htaccess', '.filelist' ) as $hidden ) {
			if ( is_file( $this->dir . '/' . $hidden ) ) {
				wp_delete_file( $this->dir . '/' . $hidden );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the now-empty package working directory; WP_Filesystem needs FS credentials unavailable in this AJAX context.
		@rmdir( $this->dir );
	}
}

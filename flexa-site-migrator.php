<?php
/**
 * Plugin Name: Flexa Site Migrator - WordPress Migration & Staging
 * Description: Creates a package (files + database + installer) to migrate WordPress from production to staging. Runs anywhere, no shell required.
 * Version:     1.0.6
 * Requires at least: 6.2
 * Requires PHP: 7.0
 * Author:      flexatech
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: flexa-site-migrator
 * Domain Path: /languages
 */

namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FLEXASM_VERSION', '1.0.6' );
define( 'FLEXASM_PATH', plugin_dir_path( __FILE__ ) );
define( 'FLEXASM_URL', plugin_dir_url( __FILE__ ) );

// Package storage directory: <uploads>/flexasm-packages (resolved via wp_upload_dir()).
// There is intentionally no URL constant: direct web access to this directory is
// blocked (.htaccess deny-all) and every download is streamed through WordPress.
define( 'FLEXASM_PACKAGE_DIR', wp_upload_dir()['basedir'] . '/flexasm-packages' );

require_once FLEXASM_PATH . 'includes/class-flexasm-database.php';
require_once FLEXASM_PATH . 'includes/class-flexasm-archive.php';
require_once FLEXASM_PATH . 'includes/class-flexasm-zipstream.php';
require_once FLEXASM_PATH . 'includes/class-flexasm-package.php';
require_once FLEXASM_PATH . 'includes/class-flexasm-replace.php';
require_once FLEXASM_PATH . 'includes/class-flexasm-importer.php';
require_once FLEXASM_PATH . 'includes/class-flexasm-pull.php';
require_once FLEXASM_PATH . 'includes/class-flexasm-health.php';

class Plugin {

	/** Admin page hooks (used to enqueue assets only on our screens). */
	private $page_hooks = array();

	public function __construct() {
		// Load bundled .mo translations (needed outside WP.org distribution — WP.org
		// language packs load automatically, files shipped in /languages do not).
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );

		// Hold WP's automatic updater while a package build is running: the build
		// spans many requests, and a core/plugin/theme update mid-build would tear
		// the archive between two versions (see Package::guard_source_unchanged()).
		add_filter( 'automatic_updater_disabled', array( $this, 'block_auto_updates_while_building' ) );

		// Build steps run via AJAX (split into chunks to avoid timeouts).
		add_action( 'wp_ajax_flexasm_build_init',     array( $this, 'ajax_init' ) );
		add_action( 'wp_ajax_flexasm_build_database', array( $this, 'ajax_database' ) );
		add_action( 'wp_ajax_flexasm_build_files',    array( $this, 'ajax_files' ) );
		add_action( 'wp_ajax_flexasm_build_finalize', array( $this, 'ajax_finalize' ) );

		// Manage packages already built on this site (re-shown after a reload).
		add_action( 'wp_ajax_flexasm_regen_link', array( $this, 'ajax_regen_link' ) );
		add_action( 'wp_ajax_flexasm_delete_pkg', array( $this, 'ajax_delete_pkg' ) );
		add_action( 'wp_ajax_flexasm_installer',  array( $this, 'ajax_installer' ) );
		add_action( 'wp_ajax_flexasm_file',       array( $this, 'ajax_file' ) );
		add_action( 'wp_ajax_flexasm_package_zip', array( $this, 'ajax_download_package' ) );

		// Import on the staging side.
		add_action( 'wp_ajax_flexasm_import_prepare', array( $this, 'ajax_import_prepare' ) );
		add_action( 'wp_ajax_flexasm_import_extract', array( $this, 'ajax_import_extract' ) );
		add_action( 'wp_ajax_flexasm_import_deploy',  array( $this, 'ajax_import_deploy' ) );

		// Pull-by-link.
		add_action( 'init', array( Pull::class, 'handle' ) ); // production serves the files
		add_action( 'wp_ajax_flexasm_pull_info',     array( $this, 'ajax_pull_info' ) );
		add_action( 'wp_ajax_flexasm_pull_download', array( $this, 'ajax_pull_download' ) );
		add_action( 'wp_ajax_flexasm_pull_test',     array( $this, 'ajax_pull_test' ) );
		add_action( 'wp_ajax_flexasm_pull_cleanup',  array( $this, 'ajax_pull_cleanup' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'flexa-site-migrator', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	public function menu() {
		add_menu_page(
			__( 'Flexa Site Migrator', 'flexa-site-migrator' ),
			__( 'Site Migrator', 'flexa-site-migrator' ),
			'manage_options',
			'flexa-site-migrator',
			array( $this, 'render_page' ),
			'dashicons-migrate'
		);
		$this->page_hooks[] = add_submenu_page(
			'flexa-site-migrator',
			__( 'Flexa Site Migrator', 'flexa-site-migrator' ),
			__( 'Export', 'flexa-site-migrator' ),
			'manage_options',
			'flexa-site-migrator',
			array( $this, 'render_page' )
		);
		$this->page_hooks[] = add_submenu_page(
			'flexa-site-migrator',
			__( 'Flexa Site Migrator – Import', 'flexa-site-migrator' ),
			__( 'Import', 'flexa-site-migrator' ),
			'manage_options',
			'flexasm-import',
			array( $this, 'render_import_page' )
		);
	}

	/** Disable automatic updates only while a build transient is alive (~15 min, refreshed per chunk). */
	public function block_auto_updates_while_building( $disabled ) {
		return $disabled || false !== get_transient( Package::BUILDING_TRANSIENT );
	}

	/** Quick links on the Plugins list row. */
	public function action_links( $links ) {
		$mine = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=flexa-site-migrator' ) ) . '">' . esc_html__( 'Backup', 'flexa-site-migrator' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=flexasm-import' ) ) . '">' . esc_html__( 'Restore', 'flexa-site-migrator' ) . '</a>',
		);
		return array_merge( $mine, $links );
	}

	public function assets( $hook ) {
		if ( ! in_array( $hook, $this->page_hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'flexasm-admin', FLEXASM_URL . 'assets/admin.css', array(), FLEXASM_VERSION );
		wp_enqueue_script( 'flexasm-admin', FLEXASM_URL . 'assets/admin.js', array( 'jquery', 'wp-i18n' ), FLEXASM_VERSION, true );
		wp_set_script_translations( 'flexasm-admin', 'flexa-site-migrator', FLEXASM_PATH . 'languages' );
		wp_localize_script( 'flexasm-admin', 'FLEXASM', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'flexasm_build' ),
		) );
	}

	public function render_page() {
		$packages = Package::list_all();
		require FLEXASM_PATH . 'templates/admin-page.php';
	}

	public function render_import_page() {
		$packages = Importer::list_packages();
		require FLEXASM_PATH . 'templates/import-page.php';
	}

	/** ----- AJAX handlers ----- */

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'flexasm_build', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'flexa-site-migrator' ) ), 403 );
		}
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Every AJAX handler below calls $this->guard() first, which runs check_ajax_referer( 'flexasm_build', 'nonce' ) and current_user_can( 'manage_options' ).

	/** Step 1: initialize the package, scan tables and the file list. */
	public function ajax_init() {
		$this->guard();
		try {
			$pkg   = new Package();
			$state = $pkg->init();
			wp_send_json_success( $state );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** Step 2: export the database in chunks. */
	public function ajax_database() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		try {
			$pkg   = Package::load( $id );
			$state = $pkg->step_database();
			wp_send_json_success( $state );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** Step 3: compress files in chunks. */
	public function ajax_files() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		try {
			$pkg   = Package::load( $id );
			$state = $pkg->step_files();
			wp_send_json_success( $state );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** Step 4: attach installer + manifest, finalize. */
	public function ajax_finalize() {
		$this->guard();
		$id  = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		$pwd = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Package protection password stored as a password_hash(); sanitizing would corrupt valid passwords.

		$allow = array();
		$raw   = sanitize_text_field( wp_unslash( $_POST['allow_ips'] ?? '' ) );
		if ( '' !== trim( $raw ) ) {
			foreach ( preg_split( '/[\s,]+/', $raw ) as $ip ) {
				$ip = trim( $ip );
				if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					$allow[] = $ip;
				}
			}
		}
		try {
			$pkg   = Package::load( $id );
			$state = $pkg->finalize( $pwd, $allow );
			wp_send_json_success( $state );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** Mint a fresh pull link for an existing package (the original is not recoverable). */
	public function ajax_regen_link() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		try {
			$link = Package::for_id( $id )->regenerate_link();
			wp_send_json_success( array( 'pull_link' => $link ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** Delete an existing package (removes its DB dump + archives from disk). */
	public function ajax_delete_pkg() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		try {
			Package::for_id( $id )->delete();
			wp_send_json_success( array( 'deleted' => true ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Stream installer.php as a forced download, straight from the plugin's own
	 * template. It is never written into uploads (a runnable PHP file must not
	 * live there); the user drops it next to the package files on the new host.
	 */
	public function ajax_installer() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_REQUEST['package'] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $id ) ) {
			wp_die( esc_html__( 'Invalid package.', 'flexa-site-migrator' ), '', array( 'response' => 400 ) );
		}
		$file = FLEXASM_PATH . 'templates/installer.tpl';
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="installer.php"' );
		header( 'Content-Length: ' . filesize( $file ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming the raw installer bytes as an octet-stream download; escaping/WP_Filesystem would corrupt the file.
		echo file_get_contents( $file );
		exit;
	}

	/**
	 * Stream one package file (archive part / database.sql / manifest.json) as a
	 * download. The storage directory denies direct web access, so this endpoint
	 * (nonce + manage_options, Range-aware) is the only way to fetch the files.
	 */
	public function ajax_file() {
		$this->guard();
		$id   = sanitize_text_field( wp_unslash( $_REQUEST['package'] ?? '' ) );
		$name = basename( sanitize_text_field( wp_unslash( $_REQUEST['file'] ?? '' ) ) );
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $id ) || ! preg_match( '/^(archive(-\d+)?\.zip|database\.sql|manifest\.json)$/', $name ) ) {
			wp_die( esc_html__( 'Invalid file.', 'flexa-site-migrator' ), '', array( 'response' => 400 ) );
		}
		$path = FLEXASM_PACKAGE_DIR . '/' . $id . '/' . $name;
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'flexa-site-migrator' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		Pull::serve_file( $path, $name );
	}

	/**
	 * Bundle a whole package (installer.php + archive parts + database.sql +
	 * manifest.json) into one streamed .zip so the user can download it once and
	 * unzip locally, instead of grabbing every file separately.
	 */
	public function ajax_download_package() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_REQUEST['package'] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $id ) ) {
			wp_die( esc_html__( 'Invalid package.', 'flexa-site-migrator' ), '', array( 'response' => 400 ) );
		}
		$dir = FLEXASM_PACKAGE_DIR . '/' . $id;
		if ( ! is_dir( $dir ) ) {
			wp_die( esc_html__( 'Package not found.', 'flexa-site-migrator' ), '', array( 'response' => 404 ) );
		}
		// Ship the migration files only; skip internal token/state/hidden files
		// (plus installer.php/runner.php leftovers from pre-1.0.3 packages).
		$skip    = array( 'flexasm-state.json', 'flexasm-token.hash', 'pull-token.hash', 'pull-pass.hash', 'pull-meta.json', '.htaccess', 'state.json', 'index.php', 'installer.php', 'runner.php' );
		$entries = array();
		foreach ( glob( $dir . '/*' ) as $f ) {
			if ( is_file( $f ) && ! in_array( basename( $f ), $skip, true ) ) {
				$entries[] = array( 'path' => $f, 'name' => basename( $f ) );
			}
		}
		// The archives/dump are deleted after a successful pull (Pull cleanup); a
		// stale admin page can still link here afterwards — refuse instead of
		// shipping a zip that would only contain the installer.
		if ( empty( $entries ) ) {
			wp_die( esc_html__( 'The migration files of this package were removed from this server after a pull — create a new package.', 'flexa-site-migrator' ), '', array( 'response' => 410 ) );
		}
		// The installer ships from the plugin template — it is never stored in uploads.
		$entries[] = array( 'path' => FLEXASM_PATH . 'templates/installer.tpl', 'name' => 'installer.php' );
		Zip_Stream::stream( $entries, 'flexa-site-migrator-' . $id . '.zip' );
		exit;
	}

	/** ----- Import (staging) ----- */

	public function ajax_import_prepare() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		try {
			$imp = new Importer( $id );
			wp_send_json_success( $imp->prepare() );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_import_extract() {
		$this->guard();
		$id     = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		$part   = sanitize_text_field( wp_unslash( $_POST['part'] ?? '' ) );
		$offset = absint( wp_unslash( $_POST['offset'] ?? 0 ) );
		try {
			$imp = new Importer( $id );
			wp_send_json_success( $imp->extract( $part, $offset ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_import_deploy() {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['package'] ?? '' ) );
		try {
			$imp = new Importer( $id );
			wp_send_json_success( $imp->deploy_database() );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/** ----- Pull-by-link (staging pulls from production) ----- */

	public function ajax_pull_info() {
		$this->guard();
		$link   = esc_url_raw( trim( wp_unslash( $_POST['link'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes the URL; WPCS classes it as an escaping (not sanitizing) function so it flags a false positive.
		$verify = empty( $_POST['insecure'] );
		$pwd    = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password forwarded to the source over a header and verified with password_verify(); sanitizing would corrupt valid passwords.
		try {
			wp_send_json_success( Pull::info( $link, $verify, $pwd ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_pull_test() {
		$this->guard();
		$link = esc_url_raw( trim( wp_unslash( $_POST['link'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes the URL; WPCS classes it as an escaping (not sanitizing) function so it flags a false positive.
		$pwd  = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password forwarded to the source over a header and verified with password_verify(); sanitizing would corrupt valid passwords.
		try {
			wp_send_json_success( Pull::test( $link, $pwd ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public function ajax_pull_cleanup() {
		$this->guard();
		$link   = esc_url_raw( trim( wp_unslash( $_POST['link'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes the URL; WPCS classes it as an escaping (not sanitizing) function so it flags a false positive.
		$verify = empty( $_POST['insecure'] );
		$pwd    = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password forwarded to the source over a header and verified with password_verify(); sanitizing would corrupt valid passwords.
		wp_send_json_success( array( 'cleaned' => Pull::cleanup( $link, $verify, $pwd ) ) );
	}

	public function ajax_pull_download() {
		$this->guard();
		$link   = esc_url_raw( trim( wp_unslash( $_POST['link'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitizes the URL; WPCS classes it as an escaping (not sanitizing) function so it flags a false positive.
		$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$offset = absint( wp_unslash( $_POST['offset'] ?? 0 ) );
		$total  = absint( wp_unslash( $_POST['total'] ?? 0 ) );
		$verify = empty( $_POST['insecure'] );
		$pwd    = (string) wp_unslash( $_POST['password'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password forwarded to the source over a header and verified with password_verify(); sanitizing would corrupt valid passwords.
		try {
			wp_send_json_success( Pull::download( $link, $name, $offset, $total, $verify, $pwd ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
}

new Plugin();

// Create the package directory and deny all direct web access to it on activation.
register_activation_hook( __FILE__, array( Package::class, 'secure_storage_dir' ) );

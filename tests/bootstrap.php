<?php
/**
 * PHPUnit bootstrap for Flexa Site Migrator unit tests.
 *
 * These are true unit tests: they exercise the plugin's pure logic (the ZIP
 * streamer, the serialize-safe search-replace, id validation and URL builders)
 * without loading WordPress or touching a database. The handful of WordPress
 * functions the tested classes call are stubbed below with just enough
 * behaviour for the assertions.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

// The include files bail unless ABSPATH is defined; the plugin constants point
// class code at a scratch directory the tests can write package fixtures into.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/flexasm-tests-abspath/' );
}
define( 'FLEXASM_VERSION', 'test' );
define( 'FLEXASM_PATH', dirname( __DIR__ ) . '/' );
define( 'FLEXASM_URL', 'https://example.test/wp-content/plugins/flexa-site-migrator/' );
define( 'FLEXASM_PACKAGE_DIR', sys_get_temp_dir() . '/flexasm-tests-packages' );

/* ---- Minimal WordPress function stubs (only what the tested code touches) ---- */

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return $text; }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { return $url; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return $text; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', (string) $str ) ); }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
if ( ! function_exists( 'size_format' ) ) {
	function size_format( $bytes, $decimals = 0 ) { return (string) $bytes; }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) { return 'nonce_' . $action; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Supports both add_query_arg( array $args, string $url ) and
	 * add_query_arg( string $key, string $value, string $url ).
	 */
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = isset( $args[1] ) ? $args[1] : '';
		} else {
			$params = array( $args[0] => $args[1] );
			$url    = isset( $args[2] ) ? $args[2] : '';
		}
		$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $sep . http_build_query( $params );
	}
}

/* ---- Code under test ---- */

require_once FLEXASM_PATH . 'includes/class-flexasm-zipstream.php';
require_once FLEXASM_PATH . 'includes/class-flexasm-replace.php';
require_once FLEXASM_PATH . 'includes/class-flexasm-package.php';
require_once FLEXASM_PATH . 'includes/class-flexasm-pull.php';

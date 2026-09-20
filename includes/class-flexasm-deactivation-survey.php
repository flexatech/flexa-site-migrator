<?php
namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Wires the reusable Deactivation Intelligence client SDK into Flexa Site Migrator.
 *
 * The SDK is a thin, best-effort feedback client that runs only on the Plugins
 * screen. When the user deactivates the plugin it shows a short optional survey
 * asking why, sends the result to the central platform, then lets the
 * deactivation proceed. It never blocks or delays deactivation.
 *
 * The bundled SDK lives outside the plugin namespace, so it is required
 * explicitly rather than through the class-file includes above.
 */
final class Deactivation_Survey {

	/** Central platform base URL (no trailing slash). */
	const API_URL = 'https://product-intelligence.flexacommerce.com';

	public static function boot() {
		// Let a site turn the survey off entirely (e.g. privacy-conscious hosts).
		if ( ! apply_filters( 'flexa_site_migrator/deactivation_survey/enabled', true ) ) {
			return;
		}

		$sdk = FLEXASM_PATH . 'libraries/deactivation-intelligence/src/class-deactivation-intelligence.php';
		if ( ! is_readable( $sdk ) ) {
			return;
		}
		require_once $sdk;

		if ( ! class_exists( \Deactivation_Intelligence::class ) ) {
			return;
		}

		\Deactivation_Intelligence::init(
			apply_filters(
				'flexa_site_migrator/deactivation_survey/config',
				array(
					'product'     => 'flexa-site-migrator',
					'tier'        => 'free',
					'version'     => FLEXASM_VERSION,
					'plugin_file' => plugin_basename( FLEXASM_PATH . 'flexa-site-migrator.php' ),
					'api_url'     => self::API_URL,
				)
			)
		);
	}
}

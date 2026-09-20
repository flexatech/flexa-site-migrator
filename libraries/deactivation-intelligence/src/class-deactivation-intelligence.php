<?php
/**
 * Deactivation Intelligence - reusable WordPress client SDK.
 *
 * A thin client. It contains NO business intelligence. Its only jobs:
 *   - detect deactivation on the plugins screen
 *   - render the feedback modal
 *   - collect optional user input
 *   - send events to the central API on a best-effort basis
 *   - optionally surface configurable recovery actions
 *   - NEVER block or delay deactivation
 *
 * Usage (in your plugin bootstrap):
 *
 *   require_once __DIR__ . '/vendor/deactivation-intelligence/src/class-deactivation-intelligence.php';
 *
 *   Deactivation_Intelligence::init( array(
 *       'product'     => 'flexa',
 *       'tier'        => 'free',
 *       'version'     => FLEXA_VERSION,
 *       'plugin_file' => plugin_basename( __FILE__ ), // e.g. flexa/flexa.php
 *       'api_url'     => 'https://api.example.com',
 *   ) );
 *
 * @package DeactivationIntelligence
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Deactivation_Intelligence' ) ) :

	class Deactivation_Intelligence {

		/**
		 * Registered instances keyed by product slug (supports multiple plugins
		 * loading the SDK in one site).
		 *
		 * @var Deactivation_Intelligence[]
		 */
		private static $instances = array();

		/** @var array Normalized configuration for this instance. */
		private $config;

		private function __construct( array $config ) {
			$this->config = $config;

			// Only ever runs in wp-admin. Zero footprint on the front end.
			add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );

			// Win-back tracking. Record a marker on a real deactivation of this
			// plugin, and report the reactivation once it is turned back on.
			// WordPress suppresses the deactivate_{plugin} hook during silent
			// upgrades, so plugin updates never look like a deactivation here.
			if ( ! empty( $this->config['plugin_file'] ) ) {
				add_action( 'deactivate_' . $this->config['plugin_file'], array( $this, 'mark_deactivated' ) );
			}
			add_action( 'admin_init', array( $this, 'maybe_report_reactivation' ) );
		}

		/**
		 * Initialize (idempotent per product). Returns the instance.
		 *
		 * @param array $args {
		 *     @type string $product     Required. Stable product slug.
		 *     @type string $tier        'free' | 'pro'. Default 'free'.
		 *     @type string $version     Plugin version string.
		 *     @type string $plugin_file Basename of the plugin whose deactivation to intercept.
		 *     @type string $api_url     Base URL of the central API (no trailing slash).
		 *     @type bool   $collect_environment Whether to send wp/php/locale. Default true.
		 * }
		 */
		public static function init( array $args ) {
			$defaults = array(
				'product'             => '',
				'tier'                => 'free',
				'version'             => '',
				'plugin_file'         => '',
				'api_url'             => '',
				'collect_environment' => true,
			);
			$config = wp_parse_args( $args, $defaults );

			if ( empty( $config['product'] ) || empty( $config['api_url'] ) ) {
				// Misconfigured: do nothing rather than risk breaking wp-admin.
				return null;
			}

			$config['api_url'] = untrailingslashit( $config['api_url'] );

			if ( ! isset( self::$instances[ $config['product'] ] ) ) {
				self::$instances[ $config['product'] ] = new self( $config );
			}
			return self::$instances[ $config['product'] ];
		}

		/**
		 * Anonymous, per-site installation id. A UUID stored in options. No email,
		 * no domain, no user identity. Generated once and reused.
		 */
		private function installation_id() {
			// Prefix with the sanitized product slug so the stored key carries a
			// distinctive, plugin-unique prefix (WP.org requires >=4 chars).
			$key = sanitize_key( $this->config['product'] ) . '_di_installation_id';
			$id  = get_option( $key );
			if ( ! $id ) {
				$id = wp_generate_uuid4();
				add_option( $key, $id, '', false );
			}
			return $id;
		}

		/** Option name holding the timestamp of the last real deactivation. */
		private function deactivated_option_key() {
			return sanitize_key( $this->config['product'] ) . '_di_deactivated_at';
		}

		/**
		 * Fires only on a genuine (non-silent) deactivation of this plugin, so a
		 * later reactivation can be detected. Stored non-autoloaded: it is read
		 * once, on the next admin load, then deleted.
		 */
		public function mark_deactivated() {
			update_option( $this->deactivated_option_key(), time(), false );
		}

		/**
		 * When a deactivation marker is present while the plugin is loaded, the
		 * install is active again: the user reactivated. Report it once as a
		 * 'reactivated' recovery event, with how long it stayed off, then clear
		 * the marker. Best-effort and non-blocking; never delays wp-admin.
		 */
		public function maybe_report_reactivation() {
			$key = $this->deactivated_option_key();
			$at  = (int) get_option( $key );
			if ( ! $at ) {
				return;
			}

			// Clear first so a failed or duplicated request cannot double-count.
			delete_option( $key );

			$seconds = time() - $at;
			if ( $seconds < 0 ) {
				$seconds = 0;
			}

			$this->post_event(
				$this->config['api_url'] . '/api/v1/recovery-events',
				array(
					'product'          => $this->config['product'],
					'tier'             => $this->config['tier'],
					'version'          => $this->config['version'],
					'installation_id'  => $this->installation_id(),
					'stage'            => 'reactivated',
					'seconds_inactive' => $seconds,
				)
			);
		}

		/**
		 * Best-effort server-to-server POST. Unlike the browser beacon this is a
		 * plain PHP request with no CORS, so a JSON content-type is fine; the
		 * call is non-blocking so wp-admin never waits on it.
		 */
		private function post_event( $url, array $payload ) {
			wp_remote_post(
				$url,
				array(
					'timeout'  => 2,
					'blocking' => false,
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => wp_json_encode( $payload ),
				)
			);
		}

		/** Enqueue assets only on the plugins list screen. */
		public function maybe_enqueue( $hook ) {
			if ( 'plugins.php' !== $hook ) {
				return;
			}

			$handle   = 'deactivation-intelligence-' . $this->config['product'];
			$src_dir  = plugin_dir_url( __FILE__ ) . '../assets/';
			$dist_dir = __DIR__ . '/../assets/';

			wp_enqueue_style( $handle, $src_dir . 'deactivation-intelligence.css', array(), $this->asset_version( $dist_dir . 'deactivation-intelligence.css' ) );
			wp_enqueue_script( $handle, $src_dir . 'deactivation-intelligence.js', array(), $this->asset_version( $dist_dir . 'deactivation-intelligence.js' ), true );

			wp_localize_script( $handle, 'DeactivationIntelligenceConfig_' . $this->js_key(), $this->client_config() );
		}

		/**
		 * Version string for an asset: its modification time when readable, so an
		 * edited asset always busts the browser cache. Falls back to the plugin
		 * version.
		 */
		private function asset_version( $path ) {
			$mtime = is_readable( $path ) ? filemtime( $path ) : false;
			return $mtime ? (string) $mtime : $this->config['version'];
		}

		private function js_key() {
			return preg_replace( '/[^a-zA-Z0-9_]/', '_', $this->config['product'] );
		}

		/**
		 * Config handed to the browser. Includes reasons, i18n strings, endpoints,
		 * and (best-effort) recovery actions fetched + cached server-side.
		 */
		private function client_config() {
			return array(
				'product'        => $this->config['product'],
				'tier'           => $this->config['tier'],
				'version'        => $this->config['version'],
				'installationId' => $this->installation_id(),
				'pluginFile'     => $this->config['plugin_file'],
				'endpoints'      => array(
					'events'         => $this->config['api_url'] . '/api/v1/events',
					'deactivations'  => $this->config['api_url'] . '/api/v1/deactivations',
					'feedback'       => $this->config['api_url'] . '/api/v1/feedback',
					'featureRequest' => $this->config['api_url'] . '/api/v1/feature-requests',
					'recoveryEvent'  => $this->config['api_url'] . '/api/v1/recovery-events',
				),
				'environment'    => $this->config['collect_environment'] ? array(
					'wp_version'  => get_bloginfo( 'version' ),
					'php_version' => PHP_VERSION,
					'locale'      => get_locale(),
				) : new stdClass(),
				'recoveryActions' => $this->fetch_recovery_config(),
				'reasons'        => $this->reasons(),
				'i18n'           => $this->strings(),
			);
		}

		/**
		 * Fetches recovery-action config from the API with a short timeout and
		 * caches it in a transient. If the API is unreachable, returns an empty
		 * set - the modal still works, just without server-configured recovery.
		 */
		private function fetch_recovery_config() {
			$cache_key = sanitize_key( $this->config['product'] ) . '_di_recovery_' . $this->config['tier'];
			$cached    = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}

			$url = add_query_arg(
				array(
					'product' => $this->config['product'],
					'tier'    => $this->config['tier'],
				),
				$this->config['api_url'] . '/api/v1/config'
			);

			$response = wp_remote_get(
				$url,
				array(
					'timeout'  => 2, // never make the admin wait long
					'blocking' => true,
				)
			);

			$ok      = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );
			$actions = array();
			if ( $ok ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( isset( $body['recovery_actions'] ) && is_array( $body['recovery_actions'] ) ) {
					$actions = $body['recovery_actions'];
				}
			}

			// Cache a successful response (even a legitimately empty action set)
			// for 6 hours. On failure - timeout, network error, or non-200, e.g. a
			// serverless cold start exceeding our short timeout - cache only
			// briefly, so one blip does not suppress recovery actions for hours.
			set_transient( $cache_key, $actions, $ok ? 6 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
			return $actions;
		}

		/** Stable reason ids + localized labels. Ids match the backend. */
		private function reasons() {
			return array(
				array( 'id' => 'no_longer_needed', 'label' => __( 'I no longer need it', 'flexa-site-migrator' ) ),
				array( 'id' => 'missing_feature', 'label' => __( "It's missing a feature I need", 'flexa-site-migrator' ), 'followup' => 'feature' ),
				array( 'id' => 'broken', 'label' => __( "Something isn't working", 'flexa-site-migrator' ), 'followup' => 'broken' ),
				array( 'id' => 'conflict', 'label' => __( 'It conflicts with another plugin/theme', 'flexa-site-migrator' ), 'followup' => 'conflict' ),
				array( 'id' => 'difficult_to_use', 'label' => __( "It's too difficult to use", 'flexa-site-migrator' ), 'followup' => 'difficult' ),
				array( 'id' => 'performance', 'label' => __( "It's affecting performance", 'flexa-site-migrator' ) ),
				array( 'id' => 'alternative', 'label' => __( 'I found a better alternative', 'flexa-site-migrator' ), 'followup' => 'alternative' ),
				array( 'id' => 'troubleshooting', 'label' => __( "I'm troubleshooting", 'flexa-site-migrator' ) ),
				array( 'id' => 'other', 'label' => __( 'Other', 'flexa-site-migrator' ), 'followup' => 'other' ),
			);
		}

		/** All user-facing strings, translatable. */
		private function strings() {
			return array(
				'title'           => __( 'Before you go…', 'flexa-site-migrator' ),
				'subtitle'        => __( 'What made you deactivate this plugin?', 'flexa-site-migrator' ),
				'skip'            => __( 'Skip & deactivate', 'flexa-site-migrator' ),
				'cancel'          => __( 'Cancel', 'flexa-site-migrator' ),
				'submit'          => __( 'Deactivate', 'flexa-site-migrator' ),
				'deactivateAnyway'=> __( 'Deactivate anyway', 'flexa-site-migrator' ),
				'getHelp'         => __( 'Get help', 'flexa-site-migrator' ),
				'notify'          => __( 'Notify me if you add it', 'flexa-site-migrator' ),
				'featureQ'        => __( 'What feature are you looking for?', 'flexa-site-migrator' ),
				'brokenQ'         => __( 'What went wrong?', 'flexa-site-migrator' ),
				'conflictQ'       => __( 'Which plugin or theme is it conflicting with?', 'flexa-site-migrator' ),
				'difficultQ'      => __( 'What was difficult to use?', 'flexa-site-migrator' ),
				'alternativeQ'    => __( 'What alternative are you using?', 'flexa-site-migrator' ),
				'otherQ'          => __( 'Anything you would like to share?', 'flexa-site-migrator' ),
				'optional'        => __( 'Optional', 'flexa-site-migrator' ),
			);
		}
	}

endif;

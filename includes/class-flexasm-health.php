<?php
namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Environment check: libraries + configuration required for build/import.
 */
class Health {

	/** @return array list of [label, status(ok|warn|fail|info), value, hint] */
	public static function report() {
		$rows = array();

		$php = PHP_VERSION;
		$rows[] = array(
			'PHP',
			version_compare( $php, '7.4', '>=' ) ? 'ok' : ( version_compare( $php, '7.2', '>=' ) ? 'warn' : 'fail' ),
			$php,
			version_compare( $php, '7.4', '>=' ) ? '' : __( 'PHP ≥ 7.4 is recommended', 'flexa-site-migrator' ),
		);

		$mysqli = extension_loaded( 'mysqli' );
		$rows[] = array( 'mysqli', $mysqli ? 'ok' : 'fail', $mysqli ? __( 'present', 'flexa-site-migrator' ) : __( 'missing', 'flexa-site-migrator' ), __( 'Required to import the database', 'flexa-site-migrator' ) );

		$zip = class_exists( 'ZipArchive' );
		$rows[] = array( 'ZipArchive (ext-zip)', $zip ? 'ok' : 'fail', $zip ? __( 'present', 'flexa-site-migrator' ) : __( 'missing', 'flexa-site-migrator' ), __( 'Required to compress/extract', 'flexa-site-migrator' ) );

		$curl = extension_loaded( 'curl' );
		$rows[] = array( 'cURL', $curl ? 'ok' : 'warn', $curl ? __( 'present', 'flexa-site-migrator' ) : __( 'missing', 'flexa-site-migrator' ), __( 'Needed for "Pull from production"', 'flexa-site-migrator' ) );

		$ssl = extension_loaded( 'openssl' );
		$rows[] = array( 'OpenSSL', $ssl ? 'ok' : 'warn', $ssl ? __( 'present', 'flexa-site-migrator' ) : __( 'missing', 'flexa-site-migrator' ), __( 'Needed for HTTPS', 'flexa-site-migrator' ) );

		$rb = function_exists( 'random_bytes' );
		$rows[] = array( 'random_bytes', $rb ? 'ok' : 'fail', $rb ? __( 'present', 'flexa-site-migrator' ) : __( 'missing', 'flexa-site-migrator' ), __( 'Generates security tokens', 'flexa-site-migrator' ) );

		if ( ! is_dir( FLEXASM_PACKAGE_DIR ) ) {
			wp_mkdir_p( FLEXASM_PACKAGE_DIR );
		}
		$writable = wp_is_writable( FLEXASM_PACKAGE_DIR );
		$rows[] = array( __( 'flexasm-packages directory is writable', 'flexa-site-migrator' ), $writable ? 'ok' : 'fail', FLEXASM_PACKAGE_DIR, __( 'Where packages are stored/downloaded', 'flexa-site-migrator' ) );

		$met = (int) ini_get( 'max_execution_time' );
		/* translators: %d: max_execution_time in seconds */
		$rows[] = array( 'max_execution_time', ( 0 === $met || $met >= 60 ) ? 'ok' : 'warn', $met ? sprintf( __( '%ds', 'flexa-site-migrator' ), $met ) : __( '0 (unlimited)', 'flexa-site-migrator' ), __( 'The plugin does not override this. A low value can cut short a single-pass import or a large package download — raise it in php.ini if needed.', 'flexa-site-migrator' ) );

		$mem_raw = trim( (string) ini_get( 'memory_limit' ) );
		$mem_ok  = ( '-1' === $mem_raw ) || self::to_bytes( $mem_raw ) >= 256 * 1024 * 1024;
		$rows[]  = array( 'memory_limit', $mem_ok ? 'ok' : 'warn', ( '-1' === $mem_raw ? __( '-1 (unlimited)', 'flexa-site-migrator' ) : $mem_raw ), __( 'The plugin does not raise this. 256M or more is recommended for a single-pass import — raise it in php.ini if needed.', 'flexa-site-migrator' ) );

		$upload = min(
			self::to_bytes( ini_get( 'upload_max_filesize' ) ),
			self::to_bytes( ini_get( 'post_max_size' ) )
		);
		$rows[] = array( __( 'max upload size', 'flexa-site-migrator' ), 'info', size_format( $upload ), __( 'Does not affect the "pull by link" flow', 'flexa-site-migrator' ) );

		$cfg = ABSPATH . 'wp-config.php';
		$cw  = is_file( $cfg ) && wp_is_writable( $cfg );
		$rows[] = array( __( 'wp-config.php is writable', 'flexa-site-migrator' ), $cw ? 'ok' : 'warn', $cw ? __( 'yes', 'flexa-site-migrator' ) : __( 'no', 'flexa-site-migrator' ), __( 'Needed when changing the table prefix', 'flexa-site-migrator' ) );

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		$shell    = function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', $disabled, true );
		$rows[] = array( 'shell_exec (faster mysqldump)', $shell ? 'ok' : 'info', $shell ? __( 'yes', 'flexa-site-migrator' ) : __( 'no', 'flexa-site-migrator' ), __( 'Optional — without it, export still runs via PHP', 'flexa-site-migrator' ) );

		$uploads = wp_upload_dir();
		$free    = @disk_free_space( $uploads['basedir'] );
		if ( false !== $free ) {
			$rows[] = array( __( 'Free disk space', 'flexa-site-migrator' ), 'info', size_format( $free ), '' );
		}

		return $rows;
	}

	public static function has_blocker() {
		foreach ( self::report() as $r ) {
			if ( 'fail' === $r[1] ) { return true; }
		}
		return false;
	}

	private static function to_bytes( $val ) {
		$val  = trim( (string) $val );
		$unit = strtolower( substr( $val, -1 ) );
		$num  = (float) $val;
		switch ( $unit ) {
			case 'g': $num *= 1024;
			case 'm': $num *= 1024;
			case 'k': $num *= 1024;
		}
		return (int) $num;
	}

	/** Render the check table (as a collapsible <details> block). */
	public static function render() {
		$rows  = self::report();
		$fails = 0;
		foreach ( $rows as $r ) { if ( 'fail' === $r[1] ) { $fails++; } }
		$dot = array(
			'ok'   => '#46b450',
			'warn' => '#dba617',
			'fail' => '#dc3232',
			'info' => '#8c8f94',
		);
		?>
		<details class="flexasm-card flexasm-health" <?php echo $fails ? 'open' : ''; ?>>
			<summary style="cursor:pointer;font-weight:600;">
				<?php esc_html_e( 'System check', 'flexa-site-migrator' ); ?>
				<?php if ( $fails ) : ?>
					<?php /* translators: %d: number of items that need attention */ ?>
					<span style="color:#dc3232;">— <?php echo esc_html( sprintf( _n( '%d item needs attention', '%d items need attention', $fails, 'flexa-site-migrator' ), $fails ) ); ?></span>
				<?php else : ?>
					<span style="color:#46b450;">— <?php esc_html_e( 'OK', 'flexa-site-migrator' ); ?></span>
				<?php endif; ?>
			</summary>
			<table class="widefat striped" style="margin-top:12px;">
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td style="width:26px;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr( $dot[ $r[1] ] ); ?>;"></span></td>
						<td style="font-weight:600;"><?php echo esc_html( $r[0] ); ?></td>
						<td><?php echo esc_html( $r[2] ); ?></td>
						<td style="color:#646970;"><?php echo esc_html( $r[3] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</details>
		<?php
	}
}

<?php
namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Pull-by-link:
 *  - On PRODUCTION: Pull::handle() serves package files over HTTP,
 *    with hashed-token verification + HTTP Range support (chunked download for very large files).
 *  - On STAGING: Pull::info()/download() fetches files into wp-content/flexasm-packages/<id>/.
 */
class Pull {

	const CHUNK = 8 * 1024 * 1024; // 8MB per pull

	/** File names allowed to be served/pulled. */
	private static function allowed_name( $name ) {
		return (bool) preg_match( '/^(archive(-\d+)?\.zip|database\.sql|manifest\.json)$/', $name );
	}

	/* ============================ PRODUCTION ============================ */

	/** Hooked on 'init'. Serves ?flexasm_pull=<id>&key=<token>&action=info|file&name=... */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public cross-site endpoint authenticated by a hashed token (verified below), not a nonce.
		if ( ! isset( $_GET['flexasm_pull'] ) ) {
			return;
		}
		$id  = sanitize_text_field( wp_unslash( $_GET['flexasm_pull'] ) );
		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';

		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $id ) ) {
			self::deny( __( 'Invalid ID.', 'flexa-site-migrator' ) );
		}
		$dir       = FLEXASM_PACKAGE_DIR . '/' . $id;
		$hash_file = $dir . '/pull-token.hash';
		if ( ! is_file( $hash_file ) ) {
			self::deny( __( 'Package does not exist or sharing is not enabled.', 'flexa-site-migrator' ) );
		}
		if ( '' === $key || ! hash_equals( trim( file_get_contents( $hash_file ) ), hash( 'sha256', $key ) ) ) {
			self::deny( __( 'Invalid token.', 'flexa-site-migrator' ) );
		}

		// Expired?
		$meta = json_decode( @file_get_contents( $dir . '/pull-meta.json' ), true );
		if ( $meta && ! empty( $meta['expires'] ) && time() > (int) $meta['expires'] ) {
			self::deny( __( 'The link has expired — please create a new package on production.', 'flexa-site-migrator' ) );
		}

		// Password (if the package sets one). Received via header so it never lands in the access log.
		$pass_file = $dir . '/pull-pass.hash';
		if ( is_file( $pass_file ) ) {
			$provided = isset( $_SERVER['HTTP_X_FLEXASM_AUTH'] ) ? (string) wp_unslash( $_SERVER['HTTP_X_FLEXASM_AUTH'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw password verified with password_verify(); must not be altered.
			if ( '' === $provided ) {
				self::json( array( 'ok' => false, 'need_pass' => true, 'message' => __( 'This package is password-protected — please enter the password.', 'flexa-site-migrator' ) ), 401 );
			}
			if ( ! password_verify( $provided, trim( file_get_contents( $pass_file ) ) ) ) {
				self::json( array( 'ok' => false, 'need_pass' => true, 'message' => __( 'Incorrect password.', 'flexa-site-migrator' ) ), 403 );
			}
		}

		// IP allowlist (optional). If blocked, report the calling IP so it's easy to add to the list.
		if ( ! empty( $meta['allow_ips'] ) && is_array( $meta['allow_ips'] ) ) {
			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( ! in_array( $ip, $meta['allow_ips'], true ) ) {
				self::json( array( 'ok' => false, 'blocked_ip' => $ip,
					/* translators: %s: calling IP address */
					'message' => sprintf( __( 'IP %s is not in the allowlist. Add this IP to the "IP restriction" field when creating the package on production.', 'flexa-site-migrator' ), $ip ) ), 403 );
			}
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'info';

		// Cleanup: staging calls this after pulling is done -> delete heavy/sensitive files (DB dump, archive).
		if ( 'cleanup' === $action ) {
			foreach ( glob( $dir . '/archive*.zip' ) as $f ) { wp_delete_file( $f ); }
			wp_delete_file( $dir . '/database.sql' );
			wp_delete_file( $dir . '/manifest.json' );
			// installer.php / runner.php only exist in packages built before 1.0.3.
			wp_delete_file( $dir . '/installer.php' );
			wp_delete_file( $dir . '/runner.php' );
			self::json( array( 'ok' => true, 'cleaned' => true ) );
		}

		if ( 'info' === $action ) {
			$files = array();
			foreach ( glob( $dir . '/archive*.zip' ) as $f ) {
				$files[] = array( 'name' => basename( $f ), 'size' => (int) filesize( $f ), 'type' => 'archive' );
			}
			usort( $files, function ( $a, $b ) { return strcmp( $a['name'], $b['name'] ); } );
			foreach ( array( 'database.sql' => 'database', 'manifest.json' => 'manifest' ) as $n => $t ) {
				if ( is_file( "$dir/$n" ) ) {
					$files[] = array( 'name' => $n, 'size' => (int) filesize( "$dir/$n" ), 'type' => $t );
				}
			}
			self::json( array( 'ok' => true, 'id' => $id, 'files' => $files ) );
		}

		if ( 'file' === $action ) {
			$name = isset( $_GET['name'] ) ? basename( sanitize_text_field( wp_unslash( $_GET['name'] ) ) ) : '';
			if ( ! self::allowed_name( $name ) || ! is_file( "$dir/$name" ) ) {
				self::deny( __( 'Invalid file.', 'flexa-site-migrator' ) );
			}
			self::serve_range( "$dir/$name" );
		}

		self::deny( __( 'Invalid action.', 'flexa-site-migrator' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/** Stream a file as a named attachment (with Range support). */
	public static function serve_file( $path, $filename ) {
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', $filename ) . '"' );
		self::serve_range( $path );
	}

	/** Stream a file with Range support. */
	private static function serve_range( $path ) {
		while ( ob_get_level() ) { ob_end_clean(); }
		$size = filesize( $path );
		$fp   = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked Range stream I/O for multi-GB package files; WP_Filesystem buffers whole files and cannot seek.

		header( 'Content-Type: application/octet-stream' );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );

		$start = 0; $end = $size - 1;
		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d+)-(\d*)/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ), $m ) ) {
			$start = (int) $m[1];
			if ( '' !== $m[2] ) { $end = (int) $m[2]; }
			if ( $end >= $size ) { $end = $size - 1; }
			if ( $start > $end ) { $start = 0; }
			http_response_code( 206 );
			header( "Content-Range: bytes $start-$end/$size" );
		}
		$length = $end - $start + 1;
		header( 'Content-Length: ' . $length );

		fseek( $fp, $start );
		$remain = $length;
		while ( $remain > 0 && ! feof( $fp ) ) {
			$read = fread( $fp, min( 1024 * 256, $remain ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Chunked Range stream I/O for multi-GB package files; WP_Filesystem buffers whole files and cannot seek.
			if ( false === $read || '' === $read ) { break; }
			echo $read; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming raw binary bytes of a package file (octet-stream download); escaping would corrupt the file.
			$remain -= strlen( $read );
			flush();
		}
		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Chunked Range stream I/O for multi-GB package files; WP_Filesystem buffers whole files and cannot seek.
		exit;
	}

	private static function deny( $msg ) {
		self::json( array( 'ok' => false, 'message' => $msg ), 403 );
	}
	private static function json( $arr, $code = 200 ) {
		while ( ob_get_level() ) { ob_end_clean(); }
		wp_send_json( $arr, $code ); // sets the JSON Content-Type + status, prints wp_json_encode(), and exits.
	}

	/* ============================ STAGING ============================ */

	/** Extract the id from the production link. */
	private static function link_id( $link ) {
		$q = wp_parse_url( $link, PHP_URL_QUERY );
		parse_str( (string) $q, $args );
		$id = isset( $args['flexasm_pull'] ) ? $args['flexasm_pull'] : '';
		return preg_match( '/^[A-Za-z0-9_]+$/', $id ) ? $id : '';
	}

	private static function valid_link( $link ) {
		$s = wp_parse_url( $link, PHP_URL_SCHEME );
		return in_array( $s, array( 'http', 'https' ), true ) && self::link_id( $link ) !== '';
	}

	/** Detect SSL-related errors. */
	private static function is_ssl_err( $msg ) {
		foreach ( array( 'SSL', 'certificate', 'error 60', 'self signed', 'self-signed', 'local issuer' ) as $k ) {
			if ( false !== stripos( $msg, $k ) ) { return true; }
		}
		return false;
	}

	/** Provide a hint for common network errors. */
	private static function net_hint( $msg ) {
		if ( self::is_ssl_err( $msg ) ) {
			/* translators: %s: underlying error message */
			return sprintf( __( 'SSL error while calling production (%s). If production is a local/self-signed site (.test, .local…), enable "Skip SSL verification" and try again.', 'flexa-site-migrator' ), $msg );
		}
		/* translators: %s: underlying error message */
		return sprintf( __( 'Could not reach production: %s', 'flexa-site-migrator' ), $msg );
	}

	/**
	 * Test the connection to production: try with SSL verification first; if it hits an SSL
	 * error, retry without verification to tell self-signed apart from unreachable.
	 */
	public static function test( $link, $password = '' ) {
		if ( ! self::valid_link( $link ) ) {
			throw new \Exception( esc_html__( 'Invalid link (missing http/https or the flexasm_pull parameter).', 'flexa-site-migrator' ) );
		}
		$url = add_query_arg( 'action', 'info', $link );
		$get = function ( $verify ) use ( $url, $password ) {
			return wp_remote_get( $url, self::req_args( $verify, $password, 15 ) );
		};

		$ssl = 'ok';
		$res = $get( true );
		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			if ( self::is_ssl_err( $msg ) ) {
				$res2 = $get( false );
				if ( is_wp_error( $res2 ) ) {
					return array( 'ok' => false, 'reachable' => false, 'ssl' => 'fail',
						/* translators: %s: underlying error message */
						'message' => sprintf( __( 'Still could not connect even with SSL verification off: %s', 'flexa-site-migrator' ), $res2->get_error_message() ) );
				}
				$ssl = 'selfsigned';
				$res = $res2;
			} else {
				return array( 'ok' => false, 'reachable' => false, 'ssl' => 'unknown',
					'message' => self::net_hint( $msg ) );
			}
		}

		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 === $code && ! empty( $data['ok'] ) && isset( $data['files'] ) ) {
			$size = 0;
			foreach ( $data['files'] as $f ) { $size += (int) ( $f['size'] ?? 0 ); }
			return array(
				'ok'              => true,
				'reachable'       => true,
				'ssl'             => $ssl,
				'files'           => count( $data['files'] ),
				'size'            => size_format( $size ),
				'insecure_needed' => ( 'selfsigned' === $ssl ),
				'message'         => ( 'selfsigned' === $ssl )
					? __( 'Connected, but the certificate is invalid (self-signed?). "Skip SSL verification" will be enabled automatically.', 'flexa-site-migrator' )
					: __( 'Connection OK, token valid.', 'flexa-site-migrator' ),
			);
		}

		if ( ! empty( $data['need_pass'] ) ) {
			return array( 'ok' => false, 'reachable' => true, 'ssl' => $ssl, 'need_pass' => true,
				'message' => $data['message'] ?? __( 'This package is password-protected — please enter the password.', 'flexa-site-migrator' ) );
		}
		if ( ! empty( $data['message'] ) ) {
			return array( 'ok' => false, 'reachable' => true, 'ssl' => $ssl,
				/* translators: %s: error message returned by production */
				'message' => sprintf( __( 'Production returned an error: %s (check the token/link).', 'flexa-site-migrator' ), $data['message'] ) );
		}
		return array( 'ok' => false, 'reachable' => true, 'ssl' => $ssl,
			/* translators: %s: HTTP response code */
			'message' => sprintf( __( 'HTTP %s — invalid response.', 'flexa-site-migrator' ), $code ) );
	}

	/** Fetch the file list from production + create the local directory. */
	public static function info( $link, $verify_ssl = true, $password = '' ) {
		if ( ! self::valid_link( $link ) ) {
			throw new \Exception( esc_html__( 'Invalid link.', 'flexa-site-migrator' ) );
		}
		$id  = self::link_id( $link );
		$url = add_query_arg( 'action', 'info', $link );
		$res = wp_remote_get( $url, self::req_args( $verify_ssl, $password, 30 ) );
		if ( is_wp_error( $res ) ) {
			throw new \Exception( esc_html( self::net_hint( $res->get_error_message() ) ) );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $res ) ) {
			$body = json_decode( wp_remote_retrieve_body( $res ), true );
			/* translators: %s: HTTP response code */
			$msg  = ! empty( $body['message'] ) ? $body['message'] : sprintf( __( 'Production returned an error (%s). Check the link/token/password.', 'flexa-site-migrator' ), wp_remote_retrieve_response_code( $res ) );
			throw new \Exception( esc_html( $msg ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['ok'] ) || empty( $data['files'] ) ) {
			throw new \Exception( esc_html__( 'Invalid package data.', 'flexa-site-migrator' ) );
		}

		Package::secure_storage_dir();
		$dir = FLEXASM_PACKAGE_DIR . '/' . $id;
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );

		return array( 'id' => $id, 'files' => $data['files'] );
	}

	/** Build the request args, including the password header (if any). */
	private static function req_args( $verify_ssl, $password, $timeout = 30, $extra_headers = array() ) {
		$headers = $extra_headers;
		if ( '' !== (string) $password ) {
			$headers['X-FLEXASM-Auth'] = (string) $password;
		}
		return array(
			'timeout'   => $timeout,
			'sslverify' => (bool) $verify_ssl,
			'headers'   => $headers,
		);
	}

	/** Ask the source to delete the package after pulling is done (best-effort). */
	public static function cleanup( $link, $verify_ssl = true, $password = '' ) {
		if ( ! self::valid_link( $link ) ) {
			return false;
		}
		$url = add_query_arg( 'action', 'cleanup', $link );
		$res = wp_remote_get( $url, self::req_args( $verify_ssl, $password, 20 ) );
		return ! is_wp_error( $res );
	}

	/** Pull one chunk of a file to disk (append). */
	public static function download( $link, $name, $offset, $total, $verify_ssl = true, $password = '' ) {
		if ( ! self::valid_link( $link ) ) {
			throw new \Exception( esc_html__( 'Invalid link.', 'flexa-site-migrator' ) );
		}
		$name = basename( $name );
		if ( ! self::allowed_name( $name ) ) {
			throw new \Exception( esc_html__( 'Invalid file name.', 'flexa-site-migrator' ) );
		}
		$id     = self::link_id( $link );
		$dir    = FLEXASM_PACKAGE_DIR . '/' . $id;
		$target = $dir . '/' . $name;
		$offset = max( 0, (int) $offset );
		$total  = max( 0, (int) $total );

		$end = ( $total > 0 ) ? min( $offset + self::CHUNK, $total ) - 1 : $offset + self::CHUNK - 1;
		$url = add_query_arg( array( 'action' => 'file', 'name' => rawurlencode( $name ) ), $link );

		$res = wp_remote_get( $url, self::req_args( $verify_ssl, $password, 120, array( 'Range' => 'bytes=' . $offset . '-' . $end ) ) );
		if ( is_wp_error( $res ) ) {
			throw new \Exception( esc_html( self::net_hint( $res->get_error_message() ) ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code && 206 !== $code ) {
			/* translators: %s: HTTP response code */
			throw new \Exception( esc_html( sprintf( __( 'Production returned an error during download (%s).', 'flexa-site-migrator' ), $code ) ) );
		}
		$body = wp_remote_retrieve_body( $res );
		$len  = strlen( $body );

		// 200 = server doesn't support Range -> returns the full file (only safe for small files).
		$mode = ( 0 === $offset || 200 === $code ) ? 'wb' : 'ab';
		$fh   = fopen( $target, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked Range stream I/O for multi-GB package files; WP_Filesystem buffers whole files and cannot seek.
		if ( ! $fh ) {
			throw new \Exception( esc_html__( 'Could not write the local file.', 'flexa-site-migrator' ) );
		}
		fwrite( $fh, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked Range stream I/O for multi-GB package files; WP_Filesystem buffers whole files and cannot seek.
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Chunked Range stream I/O for multi-GB package files; WP_Filesystem buffers whole files and cannot seek.

		$new_offset = ( 200 === $code ) ? $len : $offset + $len;
		$done       = ( $total > 0 ) ? ( $new_offset >= $total ) : ( $len < self::CHUNK );

		return array( 'offset' => $new_offset, 'done' => $done );
	}
}

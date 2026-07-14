<?php
/**
 * Flexa Site Migrator - Chunked DB Runner (staging, very large sites)
 *
 * Does NOT boot WordPress -> no dependency on the login session / active_plugins,
 * and no fatal error while the DB is only half-imported. Authenticated with a hashed token.
 *
 * Called via POST: token, phase = import | replace | finalize
 * Returns JSON: { ok, phase, done, progress, ... }
 */

@set_time_limit( 0 );
@ignore_user_abort( true );
@ini_set( 'memory_limit', '512M' );
error_reporting( E_ERROR | E_PARSE );
// PHP 8.1+ makes mysqli THROW on query errors by default; turn that off so the
// import handles failures via return values instead of aborting.
if ( function_exists( 'mysqli_report' ) ) {
	mysqli_report( MYSQLI_REPORT_OFF );
}
header( 'Content-Type: application/json; charset=utf-8' );
ob_start();

// Turn fatal/parse errors into readable JSON (instead of a blank screen).
register_shutdown_function( function () {
	$e = error_get_last();
	if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
		while ( ob_get_level() ) { ob_end_clean(); }
		echo json_encode( array( 'ok' => false, 'message' => 'Runner fatal: ' . $e['message'] ) );
	}
} );

define( 'FLEXASM_DIR', __DIR__ );

function flexasm_die( $arr, $code = 200 ) {
	while ( ob_get_level() ) { ob_end_clean(); }
	http_response_code( 200 ); // always 200 so the client can read 'message'
	echo json_encode( $arr );
	exit;
}

/* ---- Auth: hashed token ---- */
$token     = isset( $_POST['token'] ) ? (string) $_POST['token'] : '';
$hash_file = FLEXASM_DIR . '/flexasm-token.hash';
if ( ! is_file( $hash_file ) ) {
	flexasm_die( array( 'ok' => false, 'message' => 'The migration session does not exist (already completed or deleted).' ), 403 );
}
$stored = trim( file_get_contents( $hash_file ) );
if ( '' === $token || ! hash_equals( $stored, hash( 'sha256', $token ) ) ) {
	flexasm_die( array( 'ok' => false, 'message' => 'Invalid token.' ), 403 );
}

/* ---- State ---- */
$state_file = FLEXASM_DIR . '/flexasm-state.json';
$state      = json_decode( @file_get_contents( $state_file ), true );
if ( ! $state ) {
	flexasm_die( array( 'ok' => false, 'message' => 'Could not read state.' ), 500 );
}
function flexasm_save_state( $state ) {
	file_put_contents( FLEXASM_DIR . '/flexasm-state.json', json_encode( $state ) );
}

/* ---- DB credentials: parse from wp-config.php (without executing it) ---- */
function flexasm_cfg( $cfg, $const ) {
	if ( preg_match( "/define\(\s*['\"]" . $const . "['\"]\s*,\s*'((?:[^'\\\\]|\\\\.)*)'/", $cfg, $m ) ) {
		return stripcslashes( $m[1] );
	}
	if ( preg_match( '/define\(\s*[\'"]' . $const . '[\'"]\s*,\s*"((?:[^"\\\\]|\\\\.)*)"/', $cfg, $m ) ) {
		return stripcslashes( $m[1] );
	}
	return null;
}

$abspath  = rtrim( $state['abspath'], '/' );
$cfg_path = $abspath . '/wp-config.php';
if ( ! is_file( $cfg_path ) ) {
	// WP allows wp-config.php to live in the parent directory (if the parent has no wp-settings.php).
	$up = dirname( $abspath ) . '/wp-config.php';
	if ( is_file( $up ) && ! is_file( dirname( $abspath ) . '/wp-settings.php' ) ) {
		$cfg_path = $up;
	}
}
$wpcfg = @file_get_contents( $cfg_path );
if ( false === $wpcfg ) {
	flexasm_die( array( 'ok' => false, 'message' => 'Could not find/read wp-config.php (tried ' . $cfg_path . ').' ) );
}
$db_name = flexasm_cfg( $wpcfg, 'DB_NAME' );
$db_user = flexasm_cfg( $wpcfg, 'DB_USER' );
$db_pass = flexasm_cfg( $wpcfg, 'DB_PASSWORD' );
$db_host = flexasm_cfg( $wpcfg, 'DB_HOST' );

$port = null; $sock = null;
if ( strpos( (string) $db_host, ':' ) !== false ) {
	list( $h, $p ) = explode( ':', $db_host, 2 );
	$db_host = $h;
	if ( is_numeric( $p ) ) { $port = (int) $p; } else { $sock = $p; }
}

if ( null === $db_name || null === $db_user ) {
	flexasm_die( array( 'ok' => false, 'message' => 'Could not extract DB details from wp-config.php (does the password contain special characters?).' ) );
}

$mysqli = @mysqli_connect( $db_host, $db_user, $db_pass, $db_name, $port, $sock );
if ( ! $mysqli ) {
	flexasm_die( array( 'ok' => false, 'message' => 'DB connection failed: ' . mysqli_connect_error() ) );
}
mysqli_set_charset( $mysqli, 'utf8mb4' );
// Relax strict mode so legacy zero-date defaults (e.g. WooCommerce ActionScheduler's
// "datetime NOT NULL DEFAULT '0000-00-00 00:00:00'") import on MySQL 5.7+/8.0.
mysqli_query( $mysqli, "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'" );
mysqli_query( $mysqli, 'SET SESSION FOREIGN_KEY_CHECKS=0' );

/* ---- Serialization-safe replace ---- */
function flexasm_replace_recursive( $from, $to, $data, $ser = false ) {
	try {
		if ( is_string( $data ) && '' !== $data && ( $un = @unserialize( $data ) ) !== false ) {
			$data = flexasm_replace_recursive( $from, $to, $un, true );
		} elseif ( is_array( $data ) ) {
			$tmp = array();
			foreach ( $data as $k => $v ) { $tmp[ $k ] = flexasm_replace_recursive( $from, $to, $v, false ); }
			$data = $tmp;
		} elseif ( is_object( $data ) ) {
			$tmp = clone $data;
			foreach ( get_object_vars( $data ) as $k => $v ) { $tmp->$k = flexasm_replace_recursive( $from, $to, $v, false ); }
			$data = $tmp;
		} elseif ( is_string( $data ) ) {
			$data = str_replace( $from, $to, $data );
		}
		if ( $ser ) { return serialize( $data ); }
	} catch ( Exception $e ) {}
	return $data;
}

$phase = isset( $_POST['phase'] ) ? $_POST['phase'] : '';

/* ================= IMPORT (by byte offset) ================= */
if ( 'import' === $phase ) {
	$sql_file = FLEXASM_DIR . '/' . $state['sql'];
	$size     = (int) $state['sql_size'];
	$offset   = (int) $state['import']['offset'];
	$budget   = 3 * 1024 * 1024; // ~3MB per chunk

	$fh = fopen( $sql_file, 'r' );
	if ( ! $fh ) { flexasm_die( array( 'ok' => false, 'message' => 'Could not read database.sql.' ), 500 ); }
	fseek( $fh, $offset );

	$buffer = ''; $read = 0; $done = false; $count = 0;
	while ( true ) {
		$line = fgets( $fh );
		if ( false === $line ) { $done = true; break; }
		$read += strlen( $line );
		$t = ltrim( $line );
		if ( '' === trim( $line ) || strpos( $t, '--' ) === 0 ) {
			if ( '' === $buffer && $read >= $budget ) { break; }
			continue;
		}
		$buffer .= $line;
		if ( substr( rtrim( $line ), -1 ) === ';' ) {
			@mysqli_query( $mysqli, $buffer );
			$buffer = '';
			$count++;
			if ( $read >= $budget ) { break; }
		}
	}
	$new_offset = ftell( $fh );
	fclose( $fh );

	$state['import']['offset'] = $done ? $size : $new_offset;
	$state['import']['done']   = $done;
	$state['import']['stmts']  = ( (int) ( $state['import']['stmts'] ?? 0 ) ) + $count;
	flexasm_save_state( $state );

	flexasm_die( array(
		'ok'       => true,
		'phase'    => 'import',
		'done'     => $done,
		'progress' => $size ? min( 100, round( $state['import']['offset'] / $size * 100 ) ) : 100,
	) );
}

/* ================= REPLACE (keyset / offset pagination) ================= */
if ( 'replace' === $phase ) {
	$batch = 500;

	// First-time init: build the table list + make sure the plugin stays active.
	if ( empty( $state['replace']['started'] ) ) {
		$tables = array();
		$r = mysqli_query( $mysqli, 'SHOW TABLES' );
		while ( $row = mysqli_fetch_row( $r ) ) { $tables[] = $row[0]; }
		$state['replace'] = array(
			'started' => true, 'tables' => $tables, 'ti' => 0,
			'last_pk' => null, 'offset' => 0, 'changed' => (int) ( $state['replace']['changed'] ?? 0 ),
		);
		flexasm_ensure_self_active( $mysqli, $state['prod_prefix'] );
		flexasm_save_state( $state );
	}

	$tables = $state['replace']['tables'];
	$ti     = (int) $state['replace']['ti'];

	if ( $ti >= count( $tables ) ) {
		flexasm_die( array( 'ok' => true, 'phase' => 'replace', 'done' => true, 'progress' => 100, 'changed' => (int) $state['replace']['changed'] ) );
	}

	$pairs = flexasm_pairs( $state );
	$table = $tables[ $ti ];

	// Columns + primary key.
	$cols = array(); $pks = array();
	$cres = mysqli_query( $mysqli, "SHOW COLUMNS FROM `$table`" );
	while ( $c = mysqli_fetch_assoc( $cres ) ) {
		$cols[] = $c['Field'];
		if ( 'PRI' === $c['Key'] ) { $pks[] = $c['Field']; }
	}
	$single_pk = ( count( $pks ) === 1 ) ? $pks[0] : null;

	// Fetch one page.
	if ( $single_pk ) {
		$last = $state['replace']['last_pk'];
		$where = ( null === $last ) ? '' : "WHERE `$single_pk` > '" . mysqli_real_escape_string( $mysqli, $last ) . "'";
		$sql = "SELECT * FROM `$table` $where ORDER BY `$single_pk` ASC LIMIT $batch";
	} else {
		$off = (int) $state['replace']['offset'];
		$sql = "SELECT * FROM `$table` LIMIT $batch OFFSET $off";
	}

	$rres = mysqli_query( $mysqli, $sql );
	$rows = array();
	if ( $rres ) { while ( $row = mysqli_fetch_assoc( $rres ) ) { $rows[] = $row; } mysqli_free_result( $rres ); }
	$got = count( $rows );

	$changed = (int) $state['replace']['changed'];
	foreach ( $rows as $row ) {
		$new = $row; $dirty = false;
		foreach ( $cols as $col ) {
			$val = $row[ $col ];
			if ( null === $val ) { continue; }
			$rep = $val;
			foreach ( $pairs as $p ) {
				if ( '' !== $p[0] && strpos( $rep, $p[0] ) !== false ) {
					$rep = flexasm_replace_recursive( $p[0], $p[1], $rep );
				}
			}
			if ( $rep !== $val ) { $new[ $col ] = $rep; $dirty = true; }
		}
		if ( $dirty ) {
			$sets = array();
			foreach ( $cols as $col ) {
				if ( $new[ $col ] !== $row[ $col ] ) {
					$sets[] = "`$col`='" . mysqli_real_escape_string( $mysqli, $new[ $col ] ) . "'";
				}
			}
			if ( $single_pk ) {
				$w = "`$single_pk`='" . mysqli_real_escape_string( $mysqli, $row[ $single_pk ] ) . "'";
			} else {
				$conds = array();
				foreach ( $cols as $col ) {
					$conds[] = ( null === $row[ $col ] ) ? "`$col` IS NULL"
						: "`$col`='" . mysqli_real_escape_string( $mysqli, $row[ $col ] ) . "'";
				}
				$w = implode( ' AND ', $conds );
			}
			if ( $sets && @mysqli_query( $mysqli, "UPDATE `$table` SET " . implode( ',', $sets ) . " WHERE $w LIMIT 1" ) ) {
				$changed++;
			}
		}
	}
	$state['replace']['changed'] = $changed;

	// Advance the cursor.
	if ( $single_pk && $got > 0 ) {
		$state['replace']['last_pk'] = $rows[ $got - 1 ][ $single_pk ];
	} else {
		$state['replace']['offset'] = (int) $state['replace']['offset'] + $got;
	}

	$table_done = ( $got < $batch );
	if ( $table_done ) {
		$state['replace']['ti']      = $ti + 1;
		$state['replace']['last_pk'] = null;
		$state['replace']['offset']  = 0;
	}

	$all_done = ( (int) $state['replace']['ti'] >= count( $tables ) );
	flexasm_save_state( $state );

	flexasm_die( array(
		'ok'       => true,
		'phase'    => 'replace',
		'done'     => $all_done,
		'progress' => min( 100, round( (int) $state['replace']['ti'] / max( 1, count( $tables ) ) * 100 ) ),
		'changed'  => $changed,
	) );
}

/* ================= FINALIZE ================= */
if ( 'finalize' === $phase ) {
	$note = '';
	if ( $state['prod_prefix'] !== $state['stag_prefix'] ) {
		$note = flexasm_update_config_prefix( $state['abspath'], $state['prod_prefix'] )
			? "Changed the table prefix in wp-config to \"{$state['prod_prefix']}\"."
			: "⚠️ The prefixes differ ({$state['stag_prefix']} → {$state['prod_prefix']}) but wp-config.php is NOT writable. Please set it manually: \$table_prefix = '{$state['prod_prefix']}';";
	}

	$result = array(
		'ok'       => true,
		'phase'    => 'finalize',
		'done'     => true,
		'stmts'    => (int) ( $state['import']['stmts'] ?? 0 ),
		'changed'  => (int) ( $state['replace']['changed'] ?? 0 ),
		'note'     => $note,
		'new_url'  => $state['new_url'],
	);

	// Sensitive cleanup: delete the SQL, token, state, and the runner itself.
	@unlink( FLEXASM_DIR . '/' . $state['sql'] );
	@unlink( FLEXASM_DIR . '/flexasm-token.hash' );
	@unlink( FLEXASM_DIR . '/flexasm-state.json' );
	mysqli_close( $mysqli );
	@unlink( __FILE__ );

	flexasm_die( $result );
}

flexasm_die( array( 'ok' => false, 'message' => 'Invalid phase.' ), 400 );

/* ---- helpers ---- */
function flexasm_pairs( $state ) {
	// Prefer the precomputed pairs (each domain maps both http and https).
	if ( ! empty( $state['pairs'] ) && is_array( $state['pairs'] ) ) {
		return $state['pairs'];
	}
	$pairs = array( array( $state['old_url'], $state['new_url'] ) );
	if ( $state['old_home'] !== $state['old_url'] ) { $pairs[] = array( $state['old_home'], $state['new_url'] ); }
	if ( $state['old_path'] !== $state['new_path'] ) { $pairs[] = array( $state['old_path'], $state['new_path'] ); }
	return $pairs;
}

function flexasm_ensure_self_active( $mysqli, $prefix ) {
	$plugin = 'flexa-site-migrator/flexa-site-migrator.php';
	$table  = $prefix . 'options';
	$res = @mysqli_query( $mysqli, "SELECT option_value FROM `$table` WHERE option_name='active_plugins' LIMIT 1" );
	if ( ! $res ) { return; }
	$row  = mysqli_fetch_assoc( $res );
	$list = $row ? @unserialize( $row['option_value'] ) : array();
	if ( ! is_array( $list ) ) { $list = array(); }
	if ( ! in_array( $plugin, $list, true ) ) { $list[] = $plugin; }
	$val = mysqli_real_escape_string( $mysqli, serialize( $list ) );
	@mysqli_query( $mysqli, "UPDATE `$table` SET option_value='$val' WHERE option_name='active_plugins'" );
}

function flexasm_update_config_prefix( $abspath, $prefix ) {
	$path = rtrim( $abspath, '/' ) . '/wp-config.php';
	if ( ! is_writable( $path ) ) { return false; }
	$src = file_get_contents( $path );
	$new = preg_replace(
		'/\$table_prefix\s*=\s*[\'"][^\'"]*[\'"]\s*;/',
		"\$table_prefix = '" . addslashes( $prefix ) . "';",
		$src, 1
	);
	return ( $new && $new !== $src ) ? (bool) file_put_contents( $path, $new ) : false;
}

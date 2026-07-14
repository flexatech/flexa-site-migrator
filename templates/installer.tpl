<?php
/**
 * Flexa Site Migrator - Installer
 * Run this file on STAGING (place it in the same folder as archive.zip + database.sql + manifest.json).
 * Does not depend on WordPress. It will: extract -> import DB -> search-replace -> write wp-config.php.
 */

@set_time_limit( 0 );
@ini_set( 'memory_limit', '512M' );
error_reporting( E_ERROR | E_PARSE );

// PHP 8.1+ makes mysqli THROW on any query error by default, which would abort
// the import on the first harmless failure. Turn that off so we handle errors
// via return values / our own checks (matching the @mysqli_query() style below).
if ( function_exists( 'mysqli_report' ) ) {
	mysqli_report( MYSQLI_REPORT_OFF );
}

define( 'FLEXASM_ROOT', __DIR__ );
$manifest = json_decode( @file_get_contents( FLEXASM_ROOT . '/manifest.json' ), true );
if ( ! $manifest ) {
	die( 'Could not read manifest.json. Please place the installer in the same folder as the package.' );
}

/* Guess the destination URL + path on staging. */
$scheme   = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir_url  = rtrim( str_replace( '\\', '/', dirname( $_SERVER['SCRIPT_NAME'] ) ), '/' );
$guess_url  = $scheme . '://' . $host . $dir_url;
$guess_path = str_replace( '\\', '/', rtrim( FLEXASM_ROOT, '/\\' ) ) . '/';

$step   = $_POST['step'] ?? 'form';
$errors = array();
$log    = array();

/* Fields carried across the form -> check -> deploy steps. */
$fields = array(
	'db_host'  => $_POST['db_host'] ?? 'localhost',
	'db_name'  => $_POST['db_name'] ?? '',
	'db_user'  => $_POST['db_user'] ?? '',
	'db_pass'  => $_POST['db_pass'] ?? '',
	'prefix'   => $_POST['prefix'] ?? $manifest['prefix'],
	'new_url'  => $_POST['new_url'] ?? $guess_url,
	'new_path' => $_POST['new_path'] ?? $guess_path,
);

/* Run the pre-flight system check whenever we are about to migrate. */
$preflight    = array();
$preflight_ok = true;
if ( 'check' === $step || 'deploy' === $step ) {
	$preflight = flexasm_preflight( $manifest, $fields );
	foreach ( $preflight as $c ) {
		if ( ! $c['ok'] ) {
			$preflight_ok = false;
		}
	}
	// Never run the destructive migration if a check failed: fall back to the checklist.
	if ( 'deploy' === $step && ! $preflight_ok ) {
		$step = 'check';
	}
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Serialization-SAFE search-replace (based on: Search-Replace-DB). */
function flexasm_replace_recursive( $from, $to, $data, $serialised = false ) {
	try {
		if ( is_string( $data ) && '' !== $data && ( $un = @unserialize( $data ) ) !== false ) {
			$data = flexasm_replace_recursive( $from, $to, $un, true );
		} elseif ( is_array( $data ) ) {
			$tmp = array();
			foreach ( $data as $k => $v ) {
				$tmp[ $k ] = flexasm_replace_recursive( $from, $to, $v, false );
			}
			$data = $tmp;
		} elseif ( is_object( $data ) ) {
			$tmp = clone $data;
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$tmp->$k = flexasm_replace_recursive( $from, $to, $v, false );
			}
			$data = $tmp;
		} elseif ( is_string( $data ) ) {
			$data = str_replace( $from, $to, $data );
		}
		if ( $serialised ) {
			return serialize( $data );
		}
	} catch ( Exception $e ) {}
	return $data;
}

function flexasm_salt( $len = 64 ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}<>?';
	$out   = '';
	for ( $i = 0; $i < $len; $i++ ) {
		$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}
	return $out;
}

function flexasm_human_size( $bytes ) {
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$i     = 0;
	$bytes = max( (float) $bytes, 0 );
	while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
		$bytes /= 1024;
		$i++;
	}
	return round( $bytes, $i ? 1 : 0 ) . ' ' . $units[ $i ];
}

/**
 * Pre-flight system check. Verifies the environment + package are ready
 * BEFORE the destructive migration runs. Returns a list of
 * array( label, ok, note ) rows. Rows with ok=false block the migration.
 */
function flexasm_preflight( $manifest, $fields ) {
	$checks = array();

	// PHP version.
	$php_ok   = version_compare( PHP_VERSION, '7.0', '>=' );
	$checks[] = array(
		'label' => 'PHP version 7.0+',
		'ok'    => $php_ok,
		'note'  => 'running ' . PHP_VERSION,
	);

	// MySQLi extension.
	$has_mysqli = function_exists( 'mysqli_connect' );
	$checks[]   = array(
		'label' => 'MySQLi extension',
		'ok'    => $has_mysqli,
		'note'  => $has_mysqli ? 'loaded' : 'not installed — cannot import the database',
	);

	// Zip extension (needed to extract the archive).
	$has_zip  = class_exists( 'ZipArchive' );
	$checks[] = array(
		'label' => 'Zip extension (ZipArchive)',
		'ok'    => $has_zip,
		'note'  => $has_zip ? 'loaded' : 'not installed — cannot extract the archive',
	);

	// Target directory writable (extract + write wp-config.php).
	$writable = is_writable( FLEXASM_ROOT );
	$checks[] = array(
		'label' => 'Target folder is writable',
		'ok'    => $writable,
		'note'  => $writable ? FLEXASM_ROOT : 'not writable: ' . FLEXASM_ROOT,
	);

	// database.sql present.
	$sql_file = FLEXASM_ROOT . '/database.sql';
	$has_sql  = is_file( $sql_file );
	$checks[] = array(
		'label' => 'database.sql present',
		'ok'    => $has_sql,
		'note'  => $has_sql ? flexasm_human_size( filesize( $sql_file ) ) : 'missing from this folder',
	);

	// Archive part(s) present.
	$archives = ! empty( $manifest['archives'] ) ? $manifest['archives'] : array( 'archive.zip' );
	$missing  = array();
	foreach ( $archives as $apart ) {
		if ( ! is_file( FLEXASM_ROOT . '/' . basename( $apart ) ) ) {
			$missing[] = basename( $apart );
		}
	}
	$checks[] = array(
		'label' => 'Archive part(s) present (' . count( $archives ) . ')',
		'ok'    => empty( $missing ),
		'note'  => empty( $missing ) ? 'all parts found' : 'missing: ' . implode( ', ', $missing ),
	);

	// Database connection with the entered credentials.
	if ( function_exists( 'mysqli_connect' ) ) {
		$conn = @mysqli_connect( $fields['db_host'], $fields['db_user'], $fields['db_pass'], $fields['db_name'] );
		if ( $conn ) {
			$checks[] = array(
				'label' => 'Database connection',
				'ok'    => true,
				'note'  => 'connected to "' . $fields['db_name'] . '"',
			);
			mysqli_close( $conn );
		} else {
			$checks[] = array(
				'label' => 'Database connection',
				'ok'    => false,
				'note'  => mysqli_connect_error() ?: 'could not connect — check host / name / user / password',
			);
		}
	}

	return $checks;
}

/**
 * Delete the migration artifacts left in this folder after a successful
 * migration: the archive part(s), database.sql, manifest.json and the
 * installer itself. Does NOT touch the extracted site files. Returns a
 * list of array( file, ok ) rows; installer.php is removed last.
 */
function flexasm_cleanup( $manifest ) {
	$targets = array( 'database.sql', 'manifest.json' );
	$archives = ! empty( $manifest['archives'] ) ? $manifest['archives'] : array( 'archive.zip' );
	foreach ( $archives as $apart ) {
		$targets[] = basename( $apart );
	}
	$targets[] = basename( __FILE__ ); // installer.php — delete last.

	$results = array();
	foreach ( $targets as $name ) {
		$path = FLEXASM_ROOT . '/' . $name;
		if ( ! is_file( $path ) ) {
			continue; // already gone.
		}
		$results[] = array(
			'file' => $name,
			'ok'   => @unlink( $path ),
		);
	}
	return $results;
}

/* ------------------------------------------------------------------ */
/* DEPLOY                                                              */
/* ------------------------------------------------------------------ */

if ( 'deploy' === $step ) {

	$db_host = trim( $_POST['db_host'] ?? '' );
	$db_name = trim( $_POST['db_name'] ?? '' );
	$db_user = trim( $_POST['db_user'] ?? '' );
	$db_pass = $_POST['db_pass'] ?? '';
	$prefix  = trim( $_POST['prefix'] ?? $manifest['prefix'] );
	$new_url = rtrim( trim( $_POST['new_url'] ?? '' ), '/' );
	$new_path = rtrim( str_replace( '\\', '/', trim( $_POST['new_path'] ?? '' ) ), '/' ) . '/';

	$old_url  = rtrim( $manifest['site_url'], '/' );
	$old_home = rtrim( $manifest['home_url'], '/' );
	$old_path = rtrim( str_replace( '\\', '/', $manifest['abspath'] ), '/' ) . '/';

	// 1) Connect to the DB.
	$mysqli = @mysqli_connect( $db_host, $db_user, $db_pass, $db_name );
	if ( ! $mysqli ) {
		$errors[] = 'Could not connect to the database: ' . mysqli_connect_error();
	} else {
		mysqli_set_charset( $mysqli, 'utf8mb4' );

		// Relax strict mode + disable FK checks so the dump imports cleanly on
		// MySQL 5.7+/8.0. Without this, legacy zero-date defaults (e.g. WooCommerce
		// ActionScheduler's "scheduled_date_gmt datetime NOT NULL DEFAULT
		// '0000-00-00 00:00:00'") are rejected as an "Invalid default value".
		@mysqli_query( $mysqli, "SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'" );
		@mysqli_query( $mysqli, 'SET SESSION FOREIGN_KEY_CHECKS = 0' );

		// 2) Extract the archive parts.
		$archives = ! empty( $manifest['archives'] ) ? $manifest['archives'] : array( 'archive.zip' );
		$extracted = 0;
		foreach ( $archives as $apart ) {
			$apath = FLEXASM_ROOT . '/' . basename( $apart );
			if ( ! is_file( $apath ) ) {
				$errors[] = 'Missing part: ' . htmlspecialchars( basename( $apart ) );
				break;
			}
			$zip = new ZipArchive();
			if ( $zip->open( $apath ) === true ) {
				$zip->extractTo( FLEXASM_ROOT );
				$zip->close();
				$extracted++;
			} else {
				$errors[] = 'Could not open ' . htmlspecialchars( basename( $apart ) );
				break;
			}
		}
		if ( ! $errors ) {
			$log[] = "Extracted $extracted file part(s).";
		}

		// 3) Import database.sql.
		if ( ! $errors ) {
			$imported = flexasm_import_sql( $mysqli, FLEXASM_ROOT . '/database.sql' );
			$log[]    = "Imported the database ($imported statements).";
		}

		// 4) Search-replace (serialization-safe). Each domain maps both http and https.
		if ( ! $errors ) {
			$bare = function ( $u ) { return preg_replace( '#^https?://#i', '', rtrim( (string) $u, '/' ) ); };
			$ob = $bare( $old_url ); $nb = $bare( $new_url );
			$oh = $bare( $old_home );
			$pairs = array();
			if ( '' !== $ob && $ob !== $nb ) {
				$pairs[] = array( 'https://' . $ob, 'https://' . $nb );
				$pairs[] = array( 'http://' . $ob, 'http://' . $nb );
			}
			if ( '' !== $oh && $oh !== $ob ) {
				$pairs[] = array( 'https://' . $oh, 'https://' . $nb );
				$pairs[] = array( 'http://' . $oh, 'http://' . $nb );
			}
			if ( $old_path !== $new_path ) {
				$pairs[] = array( $old_path, $new_path );
			}
			$changed = flexasm_search_replace_all( $mysqli, $pairs );
			$log[]   = "Search-replace done: $changed data cells updated.";
		}

		// 5) Write wp-config.php.
		if ( ! $errors ) {
			flexasm_write_config( $new_path, $db_name, $db_user, $db_pass, $db_host, $prefix );
			$log[] = 'Created a new wp-config.php.';
		}

		mysqli_close( $mysqli );
	}
}

/** Import the SQL file line by line (buffer until a ';' at end of line). */
function flexasm_import_sql( $mysqli, $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		return 0;
	}
	$buffer = '';
	$count  = 0;
	while ( ( $line = fgets( $fh ) ) !== false ) {
		$trim = ltrim( $line );
		if ( '' === trim( $line ) || strpos( $trim, '--' ) === 0 ) {
			continue;
		}
		$buffer .= $line;
		if ( substr( rtrim( $line ), -1 ) === ';' ) {
			@mysqli_query( $mysqli, $buffer );
			$buffer = '';
			$count++;
		}
	}
	fclose( $fh );
	return $count;
}

/** Walk every table/column, replace safely, and UPDATE by primary key. */
function flexasm_search_replace_all( $mysqli, $pairs ) {
	$changed = 0;
	$tables  = array();
	$res     = mysqli_query( $mysqli, 'SHOW TABLES' );
	while ( $row = mysqli_fetch_row( $res ) ) {
		$tables[] = $row[0];
	}

	foreach ( $tables as $table ) {
		// Get columns + primary key.
		$cols = array();
		$pk   = null;
		$cres = mysqli_query( $mysqli, "SHOW COLUMNS FROM `$table`" );
		while ( $c = mysqli_fetch_assoc( $cres ) ) {
			$cols[] = $c['Field'];
			if ( 'PRI' === $c['Key'] && null === $pk ) {
				$pk = $c['Field'];
			}
		}
		if ( ! $cols ) {
			continue;
		}

		$rres = mysqli_query( $mysqli, "SELECT * FROM `$table`", MYSQLI_USE_RESULT );
		if ( ! $rres ) {
			continue;
		}
		$updates = array();
		while ( $row = mysqli_fetch_assoc( $rres ) ) {
			$new_row = $row;
			$dirty   = false;
			foreach ( $cols as $col ) {
				$val = $row[ $col ];
				if ( null === $val ) {
					continue;
				}
				$rep = $val;
				foreach ( $pairs as $p ) {
					if ( strpos( $rep, $p[0] ) !== false ) {
						$rep = flexasm_replace_recursive( $p[0], $p[1], $rep );
					}
				}
				if ( $rep !== $val ) {
					$new_row[ $col ] = $rep;
					$dirty = true;
				}
			}
			if ( $dirty ) {
				$updates[] = array( 'row' => $row, 'new' => $new_row );
			}
		}
		mysqli_free_result( $rres );

		// Run the UPDATEs after reading is finished (avoid holding the result set).
		foreach ( $updates as $u ) {
			$sets  = array();
			foreach ( $cols as $col ) {
				if ( $u['new'][ $col ] !== $u['row'][ $col ] ) {
					$sets[] = "`$col`='" . mysqli_real_escape_string( $mysqli, $u['new'][ $col ] ) . "'";
				}
			}
			if ( ! $sets ) {
				continue;
			}
			if ( $pk && isset( $u['row'][ $pk ] ) ) {
				$where = "`$pk`='" . mysqli_real_escape_string( $mysqli, $u['row'][ $pk ] ) . "'";
			} else {
				// No PK: match on all of the original values.
				$conds = array();
				foreach ( $cols as $col ) {
					if ( null === $u['row'][ $col ] ) {
						$conds[] = "`$col` IS NULL";
					} else {
						$conds[] = "`$col`='" . mysqli_real_escape_string( $mysqli, $u['row'][ $col ] ) . "'";
					}
				}
				$where = implode( ' AND ', $conds );
			}
			if ( @mysqli_query( $mysqli, "UPDATE `$table` SET " . implode( ',', $sets ) . " WHERE $where LIMIT 1" ) ) {
				$changed++;
			}
		}
	}
	return $changed;
}

/** Generate a new wp-config.php with random salts. */
function flexasm_write_config( $path, $name, $user, $pass, $host, $prefix ) {
	$keys = array( 'AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT' );
	$salt_block = '';
	foreach ( $keys as $k ) {
		$salt_block .= "define('$k', '" . addslashes( flexasm_salt() ) . "');\n";
	}
	$c = "<?php\n"
		. "define('DB_NAME', '" . addslashes( $name ) . "');\n"
		. "define('DB_USER', '" . addslashes( $user ) . "');\n"
		. "define('DB_PASSWORD', '" . addslashes( $pass ) . "');\n"
		. "define('DB_HOST', '" . addslashes( $host ) . "');\n"
		. "define('DB_CHARSET', 'utf8mb4');\n"
		. "define('DB_COLLATE', '');\n\n"
		. $salt_block . "\n"
		. "\$table_prefix = '" . addslashes( $prefix ) . "';\n\n"
		. "define('WP_DEBUG', false);\n"
		. "// Staging environment.\n"
		. "define('WP_ENVIRONMENT_TYPE', 'staging');\n\n"
		. "if ( ! defined('ABSPATH') ) define('ABSPATH', __DIR__ . '/');\n"
		. "require_once ABSPATH . 'wp-settings.php';\n";

	file_put_contents( rtrim( $path, '/' ) . '/wp-config.php', $c );
}

/* One-click cleanup of the migration artifacts after a successful migration. */
$cleaned = null;
if ( 'cleanup' === $step ) {
	$cleaned = flexasm_cleanup( $manifest );
}

$success = ( 'deploy' === $step && empty( $errors ) );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Flexa Site Migrator – Installer</title>
<style>
	body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px;}
	.box{max-width:640px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:12px;padding:28px;}
	h1{font-size:20px;margin:0 0 4px;} .sub{color:#94a3b8;font-size:13px;margin-bottom:20px;}
	label{display:block;font-size:13px;margin:14px 0 6px;color:#cbd5e1;}
	input{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;font-size:14px;}
	.row{display:flex;gap:12px;} .row > div{flex:1;}
	button{margin-top:22px;width:100%;padding:12px;border:0;border-radius:8px;background:#3b82f6;color:#fff;font-size:15px;font-weight:600;cursor:pointer;}
	button:hover{background:#2563eb;}
	.err{background:#7f1d1d;border:1px solid #b91c1c;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;}
	.ok{background:#14532d;border:1px solid #16a34a;padding:14px;border-radius:8px;font-size:14px;}
	.log{font-size:13px;color:#94a3b8;line-height:1.7;margin-top:10px;}
	.warn{color:#fbbf24;font-size:12px;margin-top:18px;}
	code{background:#0f172a;padding:2px 6px;border-radius:4px;}
	a{color:#60a5fa;}
	.checks{list-style:none;margin:0 0 18px;padding:0;}
	.checks li{display:flex;align-items:flex-start;gap:10px;padding:11px 12px;border:1px solid #334155;border-radius:8px;margin-bottom:8px;background:#0f172a;}
	.checks .ic{font-size:15px;line-height:1.4;flex:0 0 auto;}
	.checks .pass .ic{color:#22c55e;} .checks .fail .ic{color:#f87171;}
	.checks .lbl{font-size:14px;color:#e2e8f0;font-weight:600;}
	.checks .note{font-size:12px;color:#94a3b8;margin-top:2px;word-break:break-all;}
	.checks li.fail{border-color:#b91c1c;}
	.btn-sec{background:#334155;} .btn-sec:hover{background:#475569;}
</style>
</head>
<body>
<div class="box">
	<h1>Flexa Site Migrator</h1>
	<div class="sub">Install the package on staging</div>

	<?php if ( $success ) : ?>
		<div class="ok">✅ Migration complete!</div>
		<div class="log"><?php foreach ( $log as $l ) echo '• ' . htmlspecialchars( $l ) . '<br>'; ?></div>
		<p class="warn">⚠️ For security reasons you should delete the migration files now: <code>installer.php</code>, the <code>archive-*.zip</code> files, <code>database.sql</code>, and <code>manifest.json</code>. You can do it with one click below.</p>
		<form method="post" onsubmit="return confirm('Delete the backup/migration files from this folder? This cannot be undone.');">
			<input type="hidden" name="step" value="cleanup">
			<input type="hidden" name="new_url" value="<?php echo htmlspecialchars( rtrim( (string) ( $_POST['new_url'] ?? '' ), '/' ) ); ?>">
			<button type="submit">🗑 Delete backup files now</button>
		</form>
		<p style="margin-top:14px;"><a href="<?php echo htmlspecialchars( rtrim( (string) ( $_POST['new_url'] ?? '' ), '/' ) ); ?>/wp-admin/">→ Skip &amp; log in to wp-admin</a></p>

	<?php elseif ( 'cleanup' === $step ) : ?>
		<div class="ok">🧹 Cleanup complete!</div>
		<ul class="checks" style="margin-top:14px;">
			<?php foreach ( $cleaned as $r ) : ?>
				<li class="<?php echo $r['ok'] ? 'pass' : 'fail'; ?>">
					<span class="ic"><?php echo $r['ok'] ? '✔' : '✖'; ?></span>
					<span>
						<span class="lbl"><?php echo htmlspecialchars( $r['file'] ); ?></span>
						<span class="note"><?php echo $r['ok'] ? 'deleted' : 'could not delete — remove it manually'; ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p><a href="<?php echo htmlspecialchars( rtrim( (string) ( $_POST['new_url'] ?? '' ), '/' ) ); ?>/wp-admin/">→ Log in to wp-admin</a></p>

	<?php elseif ( 'check' === $step ) : ?>

		<div class="sub" style="margin-top:-8px;">System check</div>
		<ul class="checks">
			<?php foreach ( $preflight as $c ) : ?>
				<li class="<?php echo $c['ok'] ? 'pass' : 'fail'; ?>">
					<span class="ic"><?php echo $c['ok'] ? '✔' : '✖'; ?></span>
					<span>
						<span class="lbl"><?php echo htmlspecialchars( $c['label'] ); ?></span>
						<span class="note"><?php echo htmlspecialchars( $c['note'] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $preflight_ok ) : ?>
			<div class="ok" style="margin-bottom:16px;">All checks passed — ready to migrate.</div>
			<form method="post">
				<input type="hidden" name="step" value="deploy">
				<?php foreach ( $fields as $k => $v ) : ?>
					<input type="hidden" name="<?php echo htmlspecialchars( $k ); ?>" value="<?php echo htmlspecialchars( $v ); ?>">
				<?php endforeach; ?>
				<button type="submit">Start migration →</button>
			</form>
		<?php else : ?>
			<div class="err" style="margin-bottom:16px;">Please fix the items marked ✖ above, then re-check.</div>
		<?php endif; ?>

		<form method="post">
			<input type="hidden" name="step" value="form">
			<?php foreach ( $fields as $k => $v ) : ?>
				<input type="hidden" name="<?php echo htmlspecialchars( $k ); ?>" value="<?php echo htmlspecialchars( $v ); ?>">
			<?php endforeach; ?>
			<button type="submit" class="btn-sec">← Back to details</button>
		</form>

	<?php else : ?>

		<?php foreach ( $errors as $e ) echo '<div class="err">' . htmlspecialchars( $e ) . '</div>'; ?>

		<form method="post">
			<input type="hidden" name="step" value="check">

			<strong style="font-size:13px;color:#cbd5e1;">Staging database</strong>
			<div class="row">
				<div><label>DB Host</label><input name="db_host" value="<?php echo htmlspecialchars( $fields['db_host'] ); ?>"></div>
				<div><label>DB Name</label><input name="db_name" value="<?php echo htmlspecialchars( $fields['db_name'] ); ?>"></div>
			</div>
			<div class="row">
				<div><label>DB User</label><input name="db_user" value="<?php echo htmlspecialchars( $fields['db_user'] ); ?>"></div>
				<div><label>DB Password</label><input name="db_pass" type="password" value="<?php echo htmlspecialchars( $fields['db_pass'] ); ?>"></div>
			</div>
			<label>Table prefix</label>
			<input name="prefix" value="<?php echo htmlspecialchars( $fields['prefix'] ); ?>">

			<label>New staging URL</label>
			<input name="new_url" value="<?php echo htmlspecialchars( $fields['new_url'] ); ?>">

			<label>Staging directory path (ABSPATH)</label>
			<input name="new_path" value="<?php echo htmlspecialchars( $fields['new_path'] ); ?>">

			<button type="submit">Check system →</button>
		</form>
		<p class="warn">Original URL: <code><?php echo htmlspecialchars( $manifest['site_url'] ); ?></code></p>
	<?php endif; ?>
</div>
</body>
</html>

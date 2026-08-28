<?php
namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Export the database to a SQL file.
 * - Prefers mysqldump when shell_exec is available (faster).
 * - Falls back to pure PHP, exporting in chunks (runs anywhere, no timeouts).
 */
class Database {

	const ROWS_PER_CHUNK = 2000; // number of rows processed per AJAX call

	private $wpdb;
	private $sql_file;
	private $excludes;

	public function __construct( $sql_file, $excludes = array() ) {
		global $wpdb;
		$this->wpdb     = $wpdb;
		$this->sql_file = $sql_file;
		$this->excludes = array_values( (array) $excludes );
	}

	/**
	 * DB-level row filters the export can apply (opt-in from the export UI).
	 * key (sent from the export UI) => label is defined in the template; here we
	 * only need the set of recognised keys.
	 */
	public static function exclude_map() {
		return array( 'spam_comments', 'revisions' );
	}

	/**
	 * True when a selected exclude drops rows from specific tables. Such filters
	 * need a per-table WHERE clause, which mysqldump can't express in one pass, so
	 * the caller falls back to the PHP chunked exporter when this is true.
	 */
	public function has_row_filters() {
		foreach ( self::exclude_map() as $key ) {
			if ( in_array( $key, $this->excludes, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * SQL WHERE condition (without the WHERE keyword) that drops the excluded rows
	 * for $table, or '' when nothing is filtered. Only the exact core tables match
	 * (a lookalike custom table is never touched), and every literal is constant —
	 * no request data — so it is safe to inline into the prepared query.
	 */
	public function row_filter( $table ) {
		$conds = array();
		$wpdb  = $this->wpdb;
		$has   = function ( $key ) { return in_array( $key, $this->excludes, true ); };

		if ( $has( 'spam_comments' ) ) {
			if ( $table === $wpdb->comments ) {
				$conds[] = "comment_approved <> 'spam'";
			} elseif ( $table === $wpdb->commentmeta ) {
				// Drop meta orphaned by the spam comments we're leaving out.
				$conds[] = 'comment_id NOT IN (SELECT comment_ID FROM ' . self::esc_id( $wpdb->comments ) . " WHERE comment_approved = 'spam')";
			}
		}
		if ( $has( 'revisions' ) ) {
			if ( $table === $wpdb->posts ) {
				$conds[] = "post_type <> 'revision'";
			} elseif ( $table === $wpdb->postmeta ) {
				// Drop meta orphaned by the revisions we're leaving out.
				$conds[] = 'post_id NOT IN (SELECT ID FROM ' . self::esc_id( $wpdb->posts ) . " WHERE post_type = 'revision')";
			}
		}
		return implode( ' AND ', $conds );
	}

	/** List of tables in the current database. */
	public function tables() {
		$rows   = $this->wpdb->get_results( 'SHOW TABLES', ARRAY_N );
		$tables = array();
		foreach ( $rows as $r ) {
			$tables[] = $r[0];
		}
		return $tables;
	}

	/** Try mysqldump. Returns true on success. */
	public function try_mysqldump() {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}
		$disabled = explode( ',', (string) ini_get( 'disable_functions' ) );
		if ( in_array( 'shell_exec', array_map( 'trim', $disabled ), true ) ) {
			return false;
		}

		$host = DB_HOST;
		$port = '';
		if ( strpos( $host, ':' ) !== false ) {
			list( $host, $port ) = explode( ':', $host, 2 );
			$port = ' --port=' . escapeshellarg( $port );
		}

		$cmd = sprintf(
			'mysqldump --no-tablespaces --skip-comments --default-character-set=%s -h%s%s -u%s %s 2>/dev/null',
			escapeshellarg( DB_CHARSET ?: 'utf8mb4' ),
			escapeshellarg( $host ),
			$port,
			escapeshellarg( DB_USER ),
			escapeshellarg( DB_NAME )
		);

		// The password travels via the environment, not the command line, so it
		// never shows up in the process list (`ps`) while the dump runs.
		putenv( 'MYSQL_PWD=' . DB_PASSWORD );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Optional mysqldump fast-path; every argument is escapeshellarg()'d and built only from WP DB_* constants (no user input), and the call is skipped when shell_exec is in disable_functions.
		$output = shell_exec( $cmd );
		putenv( 'MYSQL_PWD' );
		if ( $output && strpos( $output, 'CREATE TABLE' ) !== false ) {
			file_put_contents( $this->sql_file, $this->header() . $output );
			return true;
		}
		return false;
	}

	private function header() {
		return "-- Flexa Site Migrator dump\n"
			. "-- Generated: " . gmdate( 'Y-m-d H:i:s' ) . "\n"
			. "SET FOREIGN_KEY_CHECKS=0;\n"
			. "SET NAMES " . ( DB_CHARSET ?: 'utf8mb4' ) . ";\n\n";
	}

	/**
	 * Export one chunk based on the current state.
	 * $state = ['t' => table_index, 'o' => row_offset, 'started' => bool]
	 * Returns the updated $state plus a 'done' flag.
	 */
	public function export_chunk( array $tables, array $state ) {
		$fh = fopen( $this->sql_file, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked stream I/O for multi-GB package files; WP_Filesystem buffers whole files and cannot seek.
		if ( ! $fh ) {
			throw new \Exception( esc_html__( 'Could not write to the SQL file.', 'flexa-site-migrator' ) );
		}

		// First run: write the header.
		if ( empty( $state['started'] ) ) {
			fwrite( $fh, $this->header() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked stream I/O; see fopen note.
			$state['started'] = true;
			$state['t'] = 0;
			$state['o'] = 0;
		}

		$ti     = (int) $state['t'];
		$offset = (int) $state['o'];
		$budget = self::ROWS_PER_CHUNK;

		while ( $ti < count( $tables ) && $budget > 0 ) {
			$table = $tables[ $ti ];

			// Starting a new table (offset 0): write its structure.
			if ( 0 === $offset ) {
				$this->write_structure( $fh, $table );
			}

			$limit  = (int) $budget; // maximum number of rows requested this time
			$offset = (int) $offset;
			$wpdb   = $this->wpdb;
			// Table name comes from SHOW TABLES (not user input) and is bound with
			// the %i identifier placeholder (WP 6.2+); LIMIT/OFFSET use %d. The
			// optional WHERE (spam/revision filter) is built from constant literals
			// only — no request data, no % placeholders — so it inlines safely.
			$where = $this->row_filter( $table );
			$sql   = 'SELECT * FROM %i ' . ( '' !== $where ? 'WHERE ' . $where . ' ' : '' ) . 'LIMIT %d OFFSET %d';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Chunked table export; no higher-level API exists and caching does not apply.
			$rows = $wpdb->get_results(
				$wpdb->prepare( $sql, $table, $limit, $offset ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = count( $rows );

			if ( $count > 0 ) {
				$this->write_inserts( $fh, $table, $rows );
				$offset += $count;
				$budget -= $count;
			}

			// Fewer rows returned than requested => table exhausted => move to next table.
			if ( $count < $limit ) {
				$ti++;
				$offset = 0;
			}
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Chunked stream I/O; see fopen note.

		$state['t'] = $ti;
		$state['o'] = $offset;
		$done       = ( $ti >= count( $tables ) );

		if ( $done ) {
			file_put_contents( $this->sql_file, "\nSET FOREIGN_KEY_CHECKS=1;\n", FILE_APPEND );
		}

		return array( 'state' => $state, 'done' => $done );
	}

	/**
	 * Backtick-quote a MySQL identifier for SQL text written into the dump FILE
	 * (live queries use the %i prepare() placeholder instead). Table names come
	 * from SHOW TABLES, never a request.
	 */
	private static function esc_id( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	private function write_structure( $fh, $table ) {
		fwrite( $fh, "\n-- Table: $table\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked stream I/O; see fopen note.
		fwrite( $fh, 'DROP TABLE IF EXISTS ' . self::esc_id( $table ) . ";\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked stream I/O; see fopen note.
		$wpdb   = $this->wpdb;
		$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- SHOW CREATE TABLE is a read-only statement (the sniff matches the CREATE TABLE keyword); structure dump for the export, identifier bound with %i.
		if ( isset( $create[1] ) ) {
			fwrite( $fh, $create[1] . ";\n\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked stream I/O; see fopen note.
		}
	}

	private function write_inserts( $fh, $table, $rows ) {
		foreach ( $rows as $row ) {
			$vals = array();
			foreach ( $row as $value ) {
				if ( null === $value ) {
					$vals[] = 'NULL';
				} else {
					// prepare( '%s' ) returns the value quoted and escaped; the
					// placeholder-escape token must be stripped because this string
					// is written to the dump file, not passed back through query().
					$vals[] = $this->wpdb->remove_placeholder_escape( $this->wpdb->prepare( '%s', $value ) );
				}
			}
			fwrite( $fh, 'INSERT INTO ' . self::esc_id( $table ) . ' VALUES (' . implode( ',', $vals ) . ");\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked stream I/O; see fopen note.
		}
	}
}

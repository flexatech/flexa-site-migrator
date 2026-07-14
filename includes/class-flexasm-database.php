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

	public function __construct( $sql_file ) {
		global $wpdb;
		$this->wpdb     = $wpdb;
		$this->sql_file = $sql_file;
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
			'mysqldump --no-tablespaces --skip-comments --default-character-set=%s -h%s%s -u%s -p%s %s 2>/dev/null',
			escapeshellarg( DB_CHARSET ?: 'utf8mb4' ),
			escapeshellarg( $host ),
			$port,
			escapeshellarg( DB_USER ),
			escapeshellarg( DB_PASSWORD ),
			escapeshellarg( DB_NAME )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Optional mysqldump fast-path; every argument is escapeshellarg()'d and built only from WP DB_* constants (no user input), and the call is skipped when shell_exec is in disable_functions.
		$output = shell_exec( $cmd );
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
			// Table name comes from SHOW TABLES (not user input) and MySQL has no
			// placeholder for identifiers; LIMIT/OFFSET are prepared with %d. The
			// disable/enable block covers the whole multi-line statement.
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare( 'SELECT * FROM ' . self::esc_id( $table ) . ' LIMIT %d OFFSET %d', $limit, $offset ),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
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

	/** Backtick-quote a MySQL identifier (table name from SHOW TABLES, never a request). */
	private static function esc_id( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	private function write_structure( $fh, $table ) {
		fwrite( $fh, "\n-- Table: $table\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked stream I/O; see fopen note.
		fwrite( $fh, 'DROP TABLE IF EXISTS ' . self::esc_id( $table ) . ";\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked stream I/O; see fopen note.
		$create = $this->wpdb->get_row( 'SHOW CREATE TABLE ' . self::esc_id( $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table identifier from SHOW TABLES (not user input); MySQL has no placeholder for identifiers.
		if ( isset( $create[1] ) ) {
			fwrite( $fh, $create[1] . ";\n\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked stream I/O; see fopen note.
		}
	}

	private function write_inserts( $fh, $table, $rows ) {
		$dbh = $this->wpdb->dbh; // mysqli handle
		foreach ( $rows as $row ) {
			$vals = array();
			foreach ( $row as $value ) {
				if ( null === $value ) {
					$vals[] = 'NULL';
				} else {
					$vals[] = "'" . mysqli_real_escape_string( $dbh, $value ) . "'"; // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string -- Escaping a value for the streamed dump using WP's own mysqli handle ($wpdb->dbh).
				}
			}
			fwrite( $fh, 'INSERT INTO ' . self::esc_id( $table ) . ' VALUES (' . implode( ',', $vals ) . ");\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked stream I/O; see fopen note.
		}
	}
}

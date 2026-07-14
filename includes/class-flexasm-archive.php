<?php
namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Compress the site into MULTIPLE zip parts (archive-1.zip, archive-2.zip, ...).
 * Each part is closed once it exceeds a size threshold -> avoids RAM/timeout issues
 * during ZipArchive::close() on very large sites.
 */
class Archive {

	const FILES_PER_CHUNK = 400;
	const PART_SIZE       = 200 * 1024 * 1024; // ~200MB per part

	private $dir;
	private $list_file;
	private $root;

	private $skip_dirs = array(
		'wp-content/cache',
		'wp-content/flexasm-packages',
		'wp-content/uploads/backup',
		'wp-content/upgrade',
		'node_modules',
		'.git',
		'.svn',
	);

	public function __construct( $dir, $list_file ) {
		$this->dir       = rtrim( $dir, '/\\' );
		$this->list_file = $list_file;
		// ABSPATH is the WP install root — the base the archive walks to package the whole site. No WP function returns the install root.
		$this->root      = rtrim( ABSPATH, '/\\' );
	}

	private function part_path( $n ) {
		return $this->dir . '/archive-' . (int) $n . '.zip';
	}

	/** Scan the site and write the list of relative paths to a file. Returns the file count. */
	public function build_filelist() {
		$fh = fopen( $this->list_file, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked stream I/O for multi-GB package files; WP_Filesystem buffers whole files and cannot seek.
		if ( ! $fh ) {
			throw new \Exception( esc_html__( 'Could not create the file list.', 'flexa-site-migrator' ) );
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		$count = 0;
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$abs = $file->getPathname();
			$rel = str_replace( '\\', '/', ltrim( str_replace( $this->root, '', $abs ), '/\\' ) );
			if ( $this->should_skip( $rel ) ) {
				continue;
			}
			fwrite( $fh, $rel . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Chunked stream I/O; see fopen note.
			$count++;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Chunked stream I/O; see fopen note.
		return $count;
	}

	private function should_skip( $rel ) {
		foreach ( $this->skip_dirs as $skip ) {
			if ( 0 === strpos( $rel, $skip ) || false !== strpos( $rel, '/' . $skip ) ) {
				return true;
			}
		}
		if ( 'wp-config.php' === $rel ) {
			return true;
		}
		return false;
	}

	/**
	 * Compress one chunk into the current part; automatically rotates to a new part when PART_SIZE is exceeded.
	 * $st = ['offset'=>int, 'part'=>int, 'part_bytes'=>int, 'parts'=>[]]
	 * Returns ['state'=>$st, 'done'=>bool].
	 */
	public function zip_chunk( array $st, $total ) {
		$offset = (int) ( $st['offset'] ?? 0 );
		$part   = max( 1, (int) ( $st['part'] ?? 1 ) );
		$pbytes = (int) ( $st['part_bytes'] ?? 0 );
		$parts  = isset( $st['parts'] ) && is_array( $st['parts'] ) ? $st['parts'] : array();

		$pf  = $this->part_path( $part );
		$zip = new \ZipArchive();
		if ( $zip->open( $pf, \ZipArchive::CREATE ) !== true ) {
			/* translators: %s: zip archive file name */
			throw new \Exception( esc_html( sprintf( __( 'Could not open %s', 'flexa-site-migrator' ), basename( $pf ) ) ) );
		}

		$fh = fopen( $this->list_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Chunked stream I/O for multi-GB package files; WP_Filesystem buffers whole files and cannot seek.
		for ( $i = 0; $i < $offset; $i++ ) {
			fgets( $fh );
		}

		$added  = 0;
		$rotate = false;
		while ( $added < self::FILES_PER_CHUNK && ( $line = fgets( $fh ) ) !== false ) {
			$offset++;
			$added++;
			$rel = trim( $line );
			if ( '' === $rel ) {
				continue;
			}
			$abs = $this->root . '/' . $rel;
			if ( is_file( $abs ) && is_readable( $abs ) ) {
				$zip->addFile( $abs, $rel );
				$pbytes += max( 0, (int) @filesize( $abs ) );
			}
			if ( $pbytes >= self::PART_SIZE ) {
				$rotate = true;
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Chunked stream I/O; see fopen note.
		$zip->close();

		if ( ! in_array( basename( $pf ), $parts, true ) ) {
			$parts[] = basename( $pf );
		}

		$done = ( $offset >= $total );
		if ( $rotate && ! $done ) {
			$part++;
			$pbytes = 0;
		}

		return array(
			'state' => array(
				'offset'     => $offset,
				'part'       => $part,
				'part_bytes' => $pbytes,
				'parts'      => $parts,
			),
			'done'  => $done,
		);
	}
}

<?php
namespace Flexa\SiteMigrator;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Streams a set of files to the browser as a single ZIP, without ever holding
 * the archive in memory or writing a temp copy to disk.
 *
 * Entries are STORED (no compression): the package parts are already compressed
 * zips, so this is effectively a fast concatenation with ZIP metadata. Because
 * a whole package can exceed 4 GB (the very reason it is split into parts), the
 * central directory uses Zip64 for member offsets and a Zip64 end-of-central-
 * directory record, so the result stays valid at any size.
 *
 * The complete output length is computed up front (all member sizes are known
 * from filesize()), so we can send an accurate Content-Length and the browser
 * shows a real progress bar / ETA for the download.
 */
class Zip_Stream {

	const CHUNK = 8388608; // 8 MB read blocks while streaming file data.

	/**
	 * Plan the archive layout: filter to real files, then compute each member's
	 * size / local-header offset / whether it needs a Zip64 offset, plus the
	 * central-directory size, its start offset and the exact total output length.
	 *
	 * Pure and side-effect free (only reads file sizes) so it can be unit-tested.
	 *
	 * @param array $entries List of array( 'path' => absolute path, 'name' => name-in-zip ).
	 * @return array{items:array,count:int,central_len:int,cd_offset:int,total:int}
	 */
	public static function plan( array $entries ) {
		$items = array();
		foreach ( $entries as $e ) {
			if ( empty( $e['path'] ) || ! is_file( $e['path'] ) ) {
				continue;
			}
			$items[] = array(
				'path' => $e['path'],
				'name' => (string) $e['name'],
				'size' => (int) filesize( $e['path'] ),
			);
		}

		$offset      = 0;
		$central_len = 0;
		foreach ( $items as $i => $it ) {
			$name_len              = strlen( $it['name'] );
			$items[ $i ]['offset'] = $offset;
			$items[ $i ]['zip64']  = ( $offset >= 0xFFFFFFFF );
			$offset               += 30 + $name_len + $it['size']; // local header + name + data (sizes < 4 GB per part).
			$central_len          += 46 + $name_len + ( $items[ $i ]['zip64'] ? 12 : 0 );
		}

		return array(
			'items'       => $items,
			'count'       => count( $items ),
			'central_len' => $central_len,
			'cd_offset'   => $offset,
			'total'       => $offset + $central_len + 56 /* zip64 eocd */ + 20 /* locator */ + 22 /* eocd */,
		);
	}

	/**
	 * Stream the given files as one ZIP attachment.
	 *
	 * @param array         $entries  List of array( 'path' => absolute path, 'name' => name-in-zip ).
	 * @param string        $filename Download filename shown to the browser.
	 * @param callable|null $sink     Optional byte writer( string $bytes ). Defaults to echo+flush
	 *                                and sending HTTP headers; tests pass a collector to capture
	 *                                the archive in-process without touching output buffers.
	 * @return int The total number of bytes written (== Content-Length).
	 */
	public static function stream( array $entries, $filename, $sink = null ) {
		$plan = self::plan( $entries );

		if ( null === $sink ) {
			if ( function_exists( 'nocache_headers' ) ) {
				nocache_headers();
			}
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', $filename ) . '"' );
			header( 'Content-Length: ' . $plan['total'] );
			header( 'X-Content-Type-Options: nosniff' );

			// Note: we do NOT raise max_execution_time here; the admin System check
			// reports the server's limit so the user can raise it in php.ini if a
			// large download is at risk of being cut short.
			while ( ob_get_level() > 0 ) {
				ob_end_clean(); // don't buffer a multi-GB stream in memory.
			}
			$sink = static function ( $bytes ) {
				echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary ZIP bytes streamed as an octet attachment; escaping would corrupt the archive.
				flush();
			};
		}

		self::write( $plan, $sink );
		return $plan['total'];
	}

	/** Emit the whole archive (local headers + data + central directory + EOCD) through $sink. */
	private static function write( array $plan, callable $sink ) {
		$items = $plan['items'];

		// 1) Local file header + raw data for each member.
		foreach ( $items as $i => $it ) {
			$crc                = self::crc32_file( $it['path'] );
			$items[ $i ]['crc'] = $crc;
			$sink( self::local_header( $it['name'], $crc, $it['size'] ) );
			self::pump( $it['path'], $sink );
		}

		// 2) Central directory.
		foreach ( $items as $it ) {
			$sink( self::central_header( $it['name'], $it['crc'], $it['size'], $it['offset'], $it['zip64'] ) );
		}

		// 3) Zip64 end of central directory record + locator, then the classic EOCD.
		$count      = $plan['count'];
		$central    = $plan['central_len'];
		$cd_offset  = $plan['cd_offset'];
		$z64_offset = $cd_offset + $central;

		$sink(
			pack( 'V', 0x06064b50 ) . pack( 'P', 44 )
			. pack( 'v', 45 ) . pack( 'v', 45 )
			. pack( 'V', 0 ) . pack( 'V', 0 )
			. pack( 'P', $count ) . pack( 'P', $count )
			. pack( 'P', $central ) . pack( 'P', $cd_offset )
		);
		$sink(
			pack( 'V', 0x07064b50 ) . pack( 'V', 0 )
			. pack( 'P', $z64_offset ) . pack( 'V', 1 )
		);
		$sink(
			pack( 'V', 0x06054b50 )
			. pack( 'v', 0 ) . pack( 'v', 0 )
			. pack( 'v', min( $count, 0xFFFF ) ) . pack( 'v', min( $count, 0xFFFF ) )
			. pack( 'V', min( $central, 0xFFFFFFFF ) ) . pack( 'V', min( $cd_offset, 0xFFFFFFFF ) )
			. pack( 'v', 0 )
		);
	}

	/** Classic local file header (member sizes are always < 4 GB, so no Zip64 here). */
	private static function local_header( $name, $crc, $size ) {
		return pack( 'V', 0x04034b50 )
			. pack( 'v', 20 )   // version needed
			. pack( 'v', 0 )    // flags
			. pack( 'v', 0 )    // method: store
			. pack( 'v', 0 )    // mod time
			. pack( 'v', 0 )    // mod date
			. pack( 'V', $crc )
			. pack( 'V', $size ) // compressed size
			. pack( 'V', $size ) // uncompressed size
			. pack( 'v', strlen( $name ) )
			. pack( 'v', 0 )    // extra length
			. $name;
	}

	/** Central directory header; Zip64 extra carries the 8-byte offset when it overflows 32 bits. */
	private static function central_header( $name, $crc, $size, $offset, $zip64 ) {
		$extra      = '';
		$off_field  = $offset;
		if ( $zip64 ) {
			$extra     = pack( 'v', 0x0001 ) . pack( 'v', 8 ) . pack( 'P', $offset );
			$off_field = 0xFFFFFFFF;
		}
		return pack( 'V', 0x02014b50 )
			. pack( 'v', 45 )              // version made by
			. pack( 'v', $zip64 ? 45 : 20 ) // version needed
			. pack( 'v', 0 )               // flags
			. pack( 'v', 0 )               // method: store
			. pack( 'v', 0 )               // mod time
			. pack( 'v', 0 )               // mod date
			. pack( 'V', $crc )
			. pack( 'V', $size )           // compressed size
			. pack( 'V', $size )           // uncompressed size
			. pack( 'v', strlen( $name ) )
			. pack( 'v', strlen( $extra ) )
			. pack( 'v', 0 )               // comment length
			. pack( 'v', 0 )               // disk number start
			. pack( 'v', 0 )               // internal attrs
			. pack( 'V', 0 )               // external attrs
			. pack( 'V', $off_field )      // local header offset
			. $name
			. $extra;
	}

	/** ZIP-standard CRC-32 of a file, read in C (fast, constant memory). */
	private static function crc32_file( $path ) {
		return hexdec( hash_file( 'crc32b', $path ) );
	}

	/** Stream a file's bytes through $sink in chunks. */
	private static function pump( $path, callable $sink ) {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming multi-GB package parts to the client; WP_Filesystem buffers whole files in memory.
		if ( ! $fh ) {
			return;
		}
		while ( ! feof( $fh ) ) {
			$sink( fread( $fh, self::CHUNK ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Streaming multi-GB package parts to the client; WP_Filesystem buffers whole files in memory.
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Chunked stream I/O; see fopen note.
	}
}

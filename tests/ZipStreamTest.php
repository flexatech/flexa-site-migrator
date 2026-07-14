<?php
namespace Flexa\SiteMigrator\Tests;

use Flexa\SiteMigrator\Zip_Stream;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Correctness tests for the streaming ZIP writer. A corrupt archive would break
 * every manual migration, so these assert the bytes really unzip.
 */
final class ZipStreamTest extends TestCase {

	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/flexasm-ziptest-' . uniqid();
		mkdir( $this->dir, 0777, true );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->dir . '/*' ) as $f ) {
			unlink( $f );
		}
		@rmdir( $this->dir );
	}

	/** Write a fixture file and return its absolute path. */
	private function file( $name, $bytes ) {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $bytes );
		return $path;
	}

	/** Build the archive in-process via the test sink and return the raw bytes. */
	private function build( array $entries ) {
		$buf   = '';
		$total = Zip_Stream::stream(
			$entries,
			'out.zip',
			function ( $chunk ) use ( &$buf ) {
				$buf .= $chunk;
			}
		);
		// The returned total (== Content-Length) must equal the bytes produced.
		$this->assertSame( strlen( $buf ), $total, 'Content-Length must equal the byte count' );
		return $buf;
	}

	public function test_plan_computes_offsets_and_total(): void {
		$entries = array(
			array( 'path' => $this->file( 'a.txt', 'hello' ), 'name' => 'a.txt' ),   // 5 bytes
			array( 'path' => $this->file( 'bb.txt', 'world!!' ), 'name' => 'bb.txt' ), // 7 bytes
		);
		$plan = Zip_Stream::plan( $entries );

		$this->assertSame( 2, $plan['count'] );
		// First member starts at offset 0.
		$this->assertSame( 0, $plan['items'][0]['offset'] );
		// Second starts after: 30 (LFH) + 5 (name "a.txt") + 5 (data) = 40.
		$this->assertSame( 40, $plan['items'][1]['offset'] );
		// Central dir starts after both members: 40 + 30 + 6 (name) + 7 (data) = 83.
		$this->assertSame( 83, $plan['cd_offset'] );
		// Central dir: 2 * 46 + name lengths (5 + 6) = 103.
		$this->assertSame( 103, $plan['central_len'] );
		// Total = cd_offset + central_len + zip64 eocd(56) + locator(20) + eocd(22).
		$this->assertSame( 83 + 103 + 56 + 20 + 22, $plan['total'] );
	}

	public function test_archive_unzips_with_identical_contents(): void {
		$files = array(
			'manifest.json' => '{"site":"x","n":1}',
			'database.sql'  => str_repeat( "INSERT INTO t VALUES (1);\n", 500 ),
			'installer.php' => "<?php echo 'hi';\n",
			'archive-1.zip' => random_bytes( 50000 ), // binary payload
		);
		$entries = array();
		foreach ( $files as $name => $bytes ) {
			$entries[] = array( 'path' => $this->file( $name, $bytes ), 'name' => $name );
		}

		$zipPath = $this->dir . '/result.zip';
		file_put_contents( $zipPath, $this->build( $entries ) );

		$za = new \ZipArchive();
		$this->assertTrue( true === $za->open( $zipPath ), 'archive must open' );
		$this->assertSame( count( $files ), $za->numFiles );

		foreach ( $files as $name => $bytes ) {
			$got = $za->getFromName( $name );
			$this->assertNotFalse( $got, "entry $name must exist" );
			$this->assertSame( $bytes, $got, "entry $name must be byte-identical (CRC-verified by ZipArchive)" );
		}
		$za->close();
	}

	public function test_empty_entry_set_is_a_valid_empty_zip(): void {
		$bytes = $this->build( array() );
		// Just the zip64 EOCD + locator + EOCD.
		$this->assertSame( 56 + 20 + 22, strlen( $bytes ) );

		$zipPath = $this->dir . '/empty.zip';
		file_put_contents( $zipPath, $bytes );
		$za = new \ZipArchive();
		$this->assertTrue( true === $za->open( $zipPath ) );
		$this->assertSame( 0, $za->numFiles );
		$za->close();
	}

	public function test_missing_files_are_skipped(): void {
		$entries = array(
			array( 'path' => $this->file( 'real.txt', 'data' ), 'name' => 'real.txt' ),
			array( 'path' => $this->dir . '/does-not-exist.bin', 'name' => 'ghost.bin' ),
		);
		$plan = Zip_Stream::plan( $entries );
		$this->assertSame( 1, $plan['count'] );

		$zipPath = $this->dir . '/skip.zip';
		file_put_contents( $zipPath, $this->build( $entries ) );
		$za = new \ZipArchive();
		$za->open( $zipPath );
		$this->assertSame( 1, $za->numFiles );
		$this->assertFalse( $za->getFromName( 'ghost.bin' ) );
		$za->close();
	}

	public function test_output_contains_zip64_eocd_record(): void {
		$entries = array( array( 'path' => $this->file( 'x', 'y' ), 'name' => 'x' ) );
		$bytes   = $this->build( $entries );
		// Zip64 end-of-central-directory record signature must be present.
		$this->assertStringContainsString( pack( 'V', 0x06064b50 ), $bytes );
		// Zip64 locator signature must be present.
		$this->assertStringContainsString( pack( 'V', 0x07064b50 ), $bytes );
	}

	public function test_central_header_uses_zip64_for_offsets_past_4gb(): void {
		// Directly exercise the offset-overflow branch without creating a 4 GB file.
		$m = new ReflectionMethod( Zip_Stream::class, 'central_header' );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true ); // no-op (and deprecated) on PHP 8.1+, needed below it.
		}

		$offset = 0x1_0000_0001; // > 0xFFFFFFFF
		$header = $m->invoke( null, 'big.zip', 0, 100, $offset, true );

		// The 32-bit offset field must be the 0xFFFFFFFF sentinel...
		$sentinel_pos = 42; // offset of the local-header-offset field in the 46-byte fixed record.
		$this->assertSame( "\xFF\xFF\xFF\xFF", substr( $header, $sentinel_pos, 4 ) );
		// ...and a Zip64 extra field (tag 0x0001, size 8) carrying the real 8-byte offset must follow the name.
		$this->assertStringContainsString( pack( 'v', 0x0001 ) . pack( 'v', 8 ) . pack( 'P', $offset ), $header );
	}
}

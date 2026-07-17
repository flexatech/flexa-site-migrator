<?php
namespace Flexa\SiteMigrator\Tests;

use Flexa\SiteMigrator\Package;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for package id validation and the admin-ajax download URL builders.
 */
final class PackageTest extends TestCase {

	public function test_for_id_accepts_valid_ids(): void {
		$this->assertInstanceOf( Package::class, Package::for_id( '20260713_101702_abcdef01' ) );
		$this->assertInstanceOf( Package::class, Package::for_id( 'Package_1' ) );
	}

	#[DataProvider( 'invalidIds' )]
	public function test_for_id_rejects_invalid_ids( string $id ): void {
		$this->expectException( \Exception::class );
		Package::for_id( $id );
	}

	public static function invalidIds(): array {
		return array(
			'path traversal' => array( '../etc' ),
			'slash'          => array( 'a/b' ),
			'dot'            => array( 'a.b' ),
			'space'          => array( 'a b' ),
			'empty'          => array( '' ),
			'null byte'      => array( "a\0b" ),
		);
	}

	public function test_installer_download_url_targets_admin_ajax_with_nonce(): void {
		$url = Package::installer_download_url( 'pkg_1' );
		$query = $this->query( $url );

		$this->assertStringContainsString( 'admin-ajax.php', $url );
		$this->assertSame( 'flexasm_installer', $query['action'] );
		$this->assertSame( 'pkg_1', $query['package'] );
		$this->assertSame( 'nonce_flexasm_build', $query['nonce'] );
	}

	public function test_file_download_url_targets_admin_ajax_with_nonce(): void {
		$url = Package::file_download_url( 'pkg_3', 'archive-1.zip' );
		$query = $this->query( $url );

		$this->assertStringContainsString( 'admin-ajax.php', $url );
		$this->assertSame( 'flexasm_file', $query['action'] );
		$this->assertSame( 'pkg_3', $query['package'] );
		$this->assertSame( 'archive-1.zip', $query['file'] );
		$this->assertSame( 'nonce_flexasm_build', $query['nonce'] );
	}

	public function test_package_download_url_uses_the_zip_action(): void {
		$url = Package::package_download_url( 'pkg_2' );
		$query = $this->query( $url );

		$this->assertStringContainsString( 'admin-ajax.php', $url );
		$this->assertSame( 'flexasm_package_zip', $query['action'] );
		$this->assertSame( 'pkg_2', $query['package'] );
		$this->assertSame( 'nonce_flexasm_build', $query['nonce'] );
	}

	/** Parse the query string of a URL into an assoc array. */
	private function query( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $out );
		return $out;
	}
}

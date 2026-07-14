<?php
namespace Flexa\SiteMigrator\Tests;

use Flexa\SiteMigrator\Pull;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests for the pull-link parsing/validation gatekeepers. These decide what a
 * remote caller is allowed to name and fetch, so their regexes matter.
 */
final class PullTest extends TestCase {

	/** Invoke a private static method on Pull. */
	private function call( string $method, ...$args ) {
		$m = new ReflectionMethod( Pull::class, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true ); // no-op (and deprecated) on PHP 8.1+, needed below it.
		}
		return $m->invoke( null, ...$args );
	}

	#[DataProvider( 'allowedNames' )]
	public function test_allowed_name_accepts_package_files( string $name ): void {
		$this->assertTrue( $this->call( 'allowed_name', $name ) );
	}

	public static function allowedNames(): array {
		return array(
			array( 'archive.zip' ),
			array( 'archive-1.zip' ),
			array( 'archive-12.zip' ),
			array( 'database.sql' ),
			array( 'manifest.json' ),
		);
	}

	#[DataProvider( 'deniedNames' )]
	public function test_allowed_name_rejects_everything_else( string $name ): void {
		$this->assertFalse( $this->call( 'allowed_name', $name ) );
	}

	public static function deniedNames(): array {
		return array(
			array( 'installer.php' ),
			array( 'flexasm-token.hash' ),
			array( 'flexasm-state.json' ),
			array( '../../wp-config.php' ),
			array( 'archive.zip.php' ),
			array( 'database.sql ' ),
			array( 'ARCHIVE.ZIP' ),
		);
	}

	public function test_link_id_extracts_the_package_id(): void {
		$this->assertSame(
			'pkg_abc123',
			$this->call( 'link_id', 'https://prod.example/?flexasm_pull=pkg_abc123&key=deadbeef' )
		);
	}

	#[DataProvider( 'badLinks' )]
	public function test_link_id_rejects_malformed_links( string $link ): void {
		$this->assertSame( '', $this->call( 'link_id', $link ) );
	}

	public static function badLinks(): array {
		return array(
			'no id'        => array( 'https://prod.example/?key=x' ),
			'bad chars'    => array( 'https://prod.example/?flexasm_pull=../etc&key=x' ),
			'empty id'     => array( 'https://prod.example/?flexasm_pull=&key=x' ),
		);
	}

	public function test_valid_link_requires_http_scheme_and_id(): void {
		$this->assertTrue( $this->call( 'valid_link', 'https://prod.example/?flexasm_pull=abc&key=x' ) );
		$this->assertTrue( $this->call( 'valid_link', 'http://prod.example/?flexasm_pull=abc&key=x' ) );
		$this->assertFalse( $this->call( 'valid_link', 'ftp://prod.example/?flexasm_pull=abc' ) );
		$this->assertFalse( $this->call( 'valid_link', 'javascript:alert(1)' ) );
		$this->assertFalse( $this->call( 'valid_link', 'https://prod.example/?key=nopackage' ) );
	}
}

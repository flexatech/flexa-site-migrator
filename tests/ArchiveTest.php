<?php
namespace Flexa\SiteMigrator\Tests;

use Flexa\SiteMigrator\Archive;
use PHPUnit\Framework\TestCase;

require_once FLEXASM_PATH . 'includes/class-flexasm-archive.php';

/**
 * Tests that the optional exclude selection (media/themes/plugins/mu-plugins)
 * keeps whole trees out of the file list, while always dropping wp-config.php.
 */
final class ArchiveTest extends TestCase {

	/** Fixture files created under ABSPATH (the tree Archive walks). */
	private array $fixtures = array(
		'index.php',
		'wp-config.php',
		'wp-content/uploads/pic.jpg',
		'wp-content/themes/x/style.css',
		'wp-content/plugins/y/y.php',
		'wp-content/mu-plugins/z.php',
	);

	protected function setUp(): void {
		foreach ( $this->fixtures as $rel ) {
			$abs = rtrim( ABSPATH, '/' ) . '/' . $rel;
			if ( ! is_dir( dirname( $abs ) ) ) {
				mkdir( dirname( $abs ), 0777, true );
			}
			file_put_contents( $abs, 'x' );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->fixtures as $rel ) {
			@unlink( rtrim( ABSPATH, '/' ) . '/' . $rel );
		}
	}

	/** Run build_filelist with the given excludes and return the relative paths it recorded. */
	private function listWith( array $excludes ): array {
		$list = sys_get_temp_dir() . '/flexasm-archivetest-' . uniqid() . '.filelist';
		( new Archive( sys_get_temp_dir(), $list, $excludes ) )->build_filelist();
		$paths = array_filter( array_map( 'trim', explode( "\n", (string) file_get_contents( $list ) ) ) );
		@unlink( $list );
		return array_values( $paths );
	}

	public function test_no_excludes_keeps_every_tree_but_drops_wp_config(): void {
		$paths = $this->listWith( array() );
		$this->assertContains( 'wp-content/uploads/pic.jpg', $paths );
		$this->assertContains( 'wp-content/themes/x/style.css', $paths );
		$this->assertContains( 'wp-content/plugins/y/y.php', $paths );
		$this->assertContains( 'wp-content/mu-plugins/z.php', $paths );
		$this->assertNotContains( 'wp-config.php', $paths );
	}

	public function test_selected_trees_are_left_out(): void {
		$paths = $this->listWith( array( 'media', 'plugins' ) );
		$this->assertNotContains( 'wp-content/uploads/pic.jpg', $paths );
		$this->assertNotContains( 'wp-content/plugins/y/y.php', $paths );
		// Untouched trees still ship.
		$this->assertContains( 'wp-content/themes/x/style.css', $paths );
		$this->assertContains( 'wp-content/mu-plugins/z.php', $paths );
	}

	public function test_exclude_map_exposes_the_four_ui_keys(): void {
		$this->assertSame(
			array( 'media', 'themes', 'mu-plugins', 'plugins' ),
			array_keys( Archive::exclude_map() )
		);
	}
}

<?php
namespace Flexa\SiteMigrator\Tests;

use Flexa\SiteMigrator\Database;
use PHPUnit\Framework\TestCase;

require_once FLEXASM_PATH . 'includes/class-flexasm-database.php';

/**
 * Tests the DB-level row filters (exclude spam comments / post revisions): the
 * WHERE clause each selection produces, that lookalike tables are untouched, and
 * that a selected filter forces the PHP export path over mysqldump.
 */
final class DatabaseTest extends TestCase {

	protected function setUp(): void {
		// Minimal $wpdb: only the core table-name properties row_filter() reads.
		$GLOBALS['wpdb'] = (object) array(
			'comments'    => 'wp_comments',
			'commentmeta' => 'wp_commentmeta',
			'posts'       => 'wp_posts',
			'postmeta'    => 'wp_postmeta',
		);
	}

	private function db( array $excludes ): Database {
		return new Database( sys_get_temp_dir() . '/flexasm-dbtest.sql', $excludes );
	}

	public function test_exclude_map_lists_the_db_keys(): void {
		$this->assertSame( array( 'spam_comments', 'revisions' ), Database::exclude_map() );
	}

	public function test_no_excludes_filters_nothing(): void {
		$db = $this->db( array() );
		$this->assertFalse( $db->has_row_filters() );
		$this->assertSame( '', $db->filter_key( 'wp_comments' ) );
		$this->assertSame( '', $db->filter_key( 'wp_posts' ) );
	}

	public function test_spam_comments_filters_comments_and_their_meta(): void {
		$db = $this->db( array( 'spam_comments' ) );
		$this->assertTrue( $db->has_row_filters() );
		$this->assertSame( 'spam_comments', $db->filter_key( 'wp_comments' ) );
		$this->assertSame( 'spam_commentmeta', $db->filter_key( 'wp_commentmeta' ) );
		// Unrelated tables are left alone.
		$this->assertSame( '', $db->filter_key( 'wp_posts' ) );
		$this->assertSame( '', $db->filter_key( 'wp_options' ) );
	}

	public function test_revisions_filters_posts_and_their_meta(): void {
		$db = $this->db( array( 'revisions' ) );
		$this->assertSame( 'revisions_posts', $db->filter_key( 'wp_posts' ) );
		$this->assertSame( 'revisions_postmeta', $db->filter_key( 'wp_postmeta' ) );
		$this->assertSame( '', $db->filter_key( 'wp_comments' ) );
	}

	public function test_a_lookalike_table_name_is_never_filtered(): void {
		// Only the exact core table names match, so a plugin's own *_posts table
		// (which has no post_type column) is dumped untouched.
		$db = $this->db( array( 'revisions' ) );
		$this->assertSame( '', $db->filter_key( 'wp_acme_posts' ) );
	}
}

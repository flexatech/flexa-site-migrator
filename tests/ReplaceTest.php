<?php
namespace Flexa\SiteMigrator\Tests;

use Flexa\SiteMigrator\Replace;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the URL-pair builder and the serialize-safe search-replace, which
 * is where a migration silently corrupts data if the logic is wrong.
 */
final class ReplaceTest extends TestCase {

	public function test_build_pairs_maps_both_schemes(): void {
		$pairs = Replace::build_pairs( 'https://old.com', 'https://new.com' );
		$this->assertContains( array( 'https://old.com', 'https://new.com' ), $pairs );
		$this->assertContains( array( 'http://old.com', 'http://new.com' ), $pairs );
	}

	public function test_build_pairs_strips_scheme_and_trailing_slash_from_bare(): void {
		$pairs = Replace::build_pairs( 'http://old.com/', 'https://new.com/' );
		// Bare host is derived without scheme/slash, then re-prefixed with both schemes.
		$this->assertContains( array( 'https://old.com', 'https://new.com' ), $pairs );
		$this->assertContains( array( 'http://old.com', 'http://new.com' ), $pairs );
	}

	public function test_build_pairs_skips_identical_urls(): void {
		$this->assertSame( array(), Replace::build_pairs( 'https://same.com', 'https://same.com' ) );
	}

	public function test_build_pairs_adds_home_when_it_differs_from_siteurl(): void {
		$pairs = Replace::build_pairs( 'https://wp.old.com', 'https://new.com', 'https://old.com', 'https://new.com' );
		$this->assertContains( array( 'https://wp.old.com', 'https://new.com' ), $pairs );
		$this->assertContains( array( 'https://old.com', 'https://new.com' ), $pairs );
	}

	public function test_build_pairs_adds_path_pair_only_when_paths_differ(): void {
		$with = Replace::build_pairs( 'https://a.com', 'https://b.com', '', '', '/old/path/', '/new/path/' );
		$this->assertContains( array( '/old/path/', '/new/path/' ), $with );

		$without = Replace::build_pairs( 'https://a.com', 'https://b.com', '', '', '/same/', '/same/' );
		$this->assertNotContains( array( '/same/', '/same/' ), $without );
	}

	public function test_recursive_replaces_plain_string(): void {
		$this->assertSame(
			'go to https://new.com/page',
			Replace::recursive( 'https://old.com', 'https://new.com', 'go to https://old.com/page' )
		);
	}

	public function test_recursive_leaves_non_strings_untouched(): void {
		$this->assertSame( 42, Replace::recursive( 'a', 'b', 42 ) );
		$this->assertNull( Replace::recursive( 'a', 'b', null ) );
		$this->assertTrue( Replace::recursive( 'a', 'b', true ) );
	}

	public function test_recursive_rewrites_serialized_string_and_fixes_lengths(): void {
		// A serialized PHP value (as stored in wp_options) whose inner string is longer after replace.
		$original   = array( 'url' => 'http://old.com', 'nested' => array( 'link' => 'http://old.com/x' ) );
		$serialized = serialize( $original );

		$result = Replace::recursive( 'http://old.com', 'https://brand-new-domain.com', $serialized );

		// Must still unserialize (i.e. the internal s:<len> prefixes were recalculated)...
		$restored = unserialize( $result );
		$this->assertIsArray( $restored );
		$this->assertSame( 'https://brand-new-domain.com', $restored['url'] );
		$this->assertSame( 'https://brand-new-domain.com/x', $restored['nested']['link'] );
	}

	public function test_recursive_handles_serialized_object(): void {
		$obj      = (object) array( 'home' => 'http://old.com' );
		$replaced = Replace::recursive( 'http://old.com', 'https://new.com', serialize( $obj ) );

		$restored = unserialize( $replaced );
		$this->assertIsObject( $restored );
		$this->assertSame( 'https://new.com', $restored->home );
	}

	public function test_recursive_walks_plain_arrays(): void {
		$in  = array( 'a' => 'http://old.com/1', 'b' => array( 'http://old.com/2' ) );
		$out = Replace::recursive( 'http://old.com', 'http://new.com', $in );
		$this->assertSame( 'http://new.com/1', $out['a'] );
		$this->assertSame( 'http://new.com/2', $out['b'][0] );
	}
}

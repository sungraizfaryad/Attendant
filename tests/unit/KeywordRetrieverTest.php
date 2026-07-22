<?php
use PHPUnit\Framework\TestCase;

require_once ATTENDANT_PLUGIN_DIR . 'includes/fallback/class-attendant-keyword-retriever.php';

/**
 * Keyword_Retriever runs MySQL FULLTEXT against wp_attendant_chunks. Tests
 * use a fake wpdb so we can verify SQL shape + ordering without a live DB.
 */
final class KeywordRetrieverTest extends TestCase {

	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = new class() {
			public string $prefix = 'wp_';
			public array $captured = array();
			public array $rows     = array();
			public function prepare( $sql, ...$args ) {
				foreach ( $args as $a ) {
					$sql = preg_replace( '/%s|%d/', is_int( $a ) ? (string) $a : "'" . addslashes( (string) $a ) . "'", $sql, 1 );
				}
				return $sql;
			}
			public function get_results( $sql, $output = ARRAY_A ) {
				$this->captured[] = $sql;
				return $this->rows;
			}
		};
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
	}

	public function test_empty_keywords_returns_empty(): void {
		$hits = ATTENDANT_Keyword_Retriever::search( $this->wpdb, '', array(), 8 );
		$this->assertSame( array(), $hits );
		$this->assertEmpty( $this->wpdb->captured );
	}

	public function test_keyword_query_uses_natural_language_mode(): void {
		$this->wpdb->rows = array(
			array( 'post_id' => 10, 'score' => '1.5' ),
			array( 'post_id' => 11, 'score' => '0.9' ),
		);
		$hits = ATTENDANT_Keyword_Retriever::search( $this->wpdb, 'villas spain', array(), 8 );
		$this->assertSame( array( 10, 11 ), $hits );
		$this->assertStringContainsString( 'IN NATURAL LANGUAGE MODE', $this->wpdb->captured[0] );
		$this->assertStringContainsString( 'attendant_chunks', $this->wpdb->captured[0] );
	}

	public function test_constraint_post_ids_added_to_where(): void {
		$this->wpdb->rows = array( array( 'post_id' => 7, 'score' => '2.0' ) );
		ATTENDANT_Keyword_Retriever::search( $this->wpdb, 'spain', array( 5, 6, 7 ), 8 );
		$this->assertStringContainsString( 'post_id IN', $this->wpdb->captured[0] );
		$this->assertStringContainsString( '5', $this->wpdb->captured[0] );
	}

	public function test_constraint_intersects_results(): void {
		$this->wpdb->rows = array(
			array( 'post_id' => 10, 'score' => '1.5' ),
			array( 'post_id' => 11, 'score' => '0.9' ),
		);
		// Constraint is enforced in SQL, not in PHP — function trusts wpdb's filter.
		$hits = ATTENDANT_Keyword_Retriever::search( $this->wpdb, 'spain', array( 11 ), 8 );
		$this->assertSame( array( 10, 11 ), $hits );
	}
}

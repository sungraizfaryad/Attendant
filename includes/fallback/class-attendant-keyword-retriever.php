<?php
/**
 * Attendant — Keyword_Retriever
 *
 * MySQL FULLTEXT search over wp_attendant_chunks.chunk_text. Returns ranked
 * post IDs. Used by the no-AI Rule_Conversation path in place of embedding
 * cosine similarity.
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lexical retrieval over the chunks table for the no-AI path.
 *
 * Wraps a single MySQL FULLTEXT query against wp_attendant_chunks.chunk_text.
 * The class is intentionally tiny so Rule_Conversation can call it directly
 * without orchestration overhead.
 */
final class ATTENDANT_Keyword_Retriever {

	/**
	 * Run a FULLTEXT search and return post IDs ordered by relevance.
	 *
	 * @param object $wpdb               Either the global $wpdb or a test double.
	 * @param string $keywords           User keyword string (may be empty).
	 * @param int[]  $post_id_constraint Optional whitelist of post IDs (from Query_Builder filters).
	 * @param int    $limit              Max distinct post IDs to return.
	 * @return int[] Post IDs ordered by descending relevance.
	 */
	public static function search( $wpdb, string $keywords, array $post_id_constraint, int $limit ): array {
		$keywords = trim( $keywords );
		if ( '' === $keywords ) {
			return array();
		}

		$table = $wpdb->prefix . 'attendant_chunks';

		$where = '';
		if ( ! empty( $post_id_constraint ) ) {
			$ids = array_map( 'intval', $post_id_constraint );
			// $where is built from intval-cast IDs only — no user input reaches it.
			$where = 'AND post_id IN (' . implode( ',', $ids ) . ')';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT post_id, MAX(MATCH(chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE)) AS score
			 FROM {$table}
			 WHERE MATCH(chunk_text) AGAINST (%s IN NATURAL LANGUAGE MODE) {$where}
			 GROUP BY post_id
			 ORDER BY score DESC
			 LIMIT %d",
			$keywords,
			$keywords,
			$limit
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map( static fn( $r ) => (int) $r['post_id'], $rows );
	}
}

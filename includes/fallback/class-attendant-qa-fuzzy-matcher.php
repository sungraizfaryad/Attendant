<?php
/**
 * Attendant — QA_Fuzzy_Matcher
 *
 * Lexical match for admin-defined Q&A pairs in no-AI mode. Combines Jaccard
 * token overlap with a normalised Levenshtein penalty to tolerate typos and
 * minor word-order shifts.
 *
 * @package Attendant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ATTENDANT_QA_Fuzzy_Matcher {

	/** Weight applied to the Jaccard token-overlap signal in the score blend. */
	private const JACCARD_WEIGHT = 0.8;

	/** Weight applied to the Levenshtein typo signal in the score blend. */
	private const LEV_WEIGHT = 0.2;

	/** Two tokens count as the same when their Levenshtein distance is ≤ this. */
	private const FUZZY_EDIT_DISTANCE = 2;

	/**
	 * Score every pair against the user message and return the top hit above
	 * its own threshold.
	 *
	 * @param string $user_message Raw user message (pre-sanitised by REST layer).
	 * @param array  $pairs        List of [{id, question, answer, threshold}, ...].
	 * @return array|null {id, question, answer, score} or null when nothing scores above threshold.
	 */
	public static function match( string $user_message, array $pairs ): ?array {
		$msg_tokens = self::tokenize( $user_message );
		if ( empty( $msg_tokens ) ) {
			return null;
		}

		$best = null;
		foreach ( $pairs as $pair ) {
			$q_tokens  = self::tokenize( (string) $pair['question'] );
			$score     = self::score( $msg_tokens, $q_tokens, (string) $user_message, (string) $pair['question'] );
			$threshold = (float) ( $pair['threshold'] ?? 0.65 );

			if ( $score >= $threshold && ( null === $best || $score > $best['score'] ) ) {
				$best = array(
					'id'       => $pair['id'] ?? null,
					'question' => $pair['question'],
					'answer'   => $pair['answer'],
					'score'    => $score,
				);
			}
		}
		return $best;
	}

	private static function tokenize( string $text ): array {
		$text   = mb_strtolower( $text );
		$text   = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
		$tokens = preg_split( '/\s+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		// Drop interrogatives and very short stop-like tokens — they appear in
		// most questions and add no discriminating power for Jaccard scoring.
		$stop = array( 'a', 'an', 'the', 'is', 'are', 'i', 'my', 'do', 'does', 'how', 'can', 'will', 'would', 'should' );
		return array_values( array_filter( $tokens, static fn( $t ) => ! in_array( $t, $stop, true ) ) );
	}

	private static function score( array $msg, array $qst, string $msg_raw, string $qst_raw ): float {
		if ( empty( $msg ) || empty( $qst ) ) {
			return 0.0;
		}

		// Fuzzy token intersection: a msg token matches a q token if they are
		// identical or within edit distance 2 (catches single transpositions/typos).
		$fuzzy_intersect = 0;
		$matched_q       = array();
		foreach ( $msg as $mt ) {
			foreach ( $qst as $qi => $qt ) {
				if ( isset( $matched_q[ $qi ] ) ) {
					continue;
				}
				if ( $mt === $qt || levenshtein( $mt, $qt ) <= self::FUZZY_EDIT_DISTANCE ) {
					$fuzzy_intersect++;
					$matched_q[ $qi ] = true;
					break;
				}
			}
		}

		// Note: the union uses strict equality while the intersection is fuzzy
		// (edit-distance ≤ 2 counts as a match). That makes the metric an
		// approximation rather than a strict Jaccard, but the score is still
		// bounded to [0,1] because |fuzzy_intersect| ≤ min(|msg|, |qst|) ≤ |union|.
		$union   = array_unique( array_merge( $msg, $qst ) );
		$jaccard = $fuzzy_intersect / max( 1, count( $union ) );

		// Normalised edit distance on the raw lowercase strings — rewards near-identical phrasing.
		$lev_norm = self::normalized_levenshtein( mb_strtolower( $msg_raw ), mb_strtolower( $qst_raw ) );

		// Blend: JACCARD_WEIGHT on fuzzy Jaccard, LEV_WEIGHT on (1 - lev_norm).
		return ( self::JACCARD_WEIGHT * $jaccard ) + ( self::LEV_WEIGHT * ( 1.0 - $lev_norm ) );
	}

	private static function normalized_levenshtein( string $a, string $b ): float {
		// PHP's native levenshtein() caps at 255-char input. Truncate first,
		// then compute the denominator from the truncated lengths so the ratio
		// stays consistent.
		$a   = substr( $a, 0, 250 );
		$b   = substr( $b, 0, 250 );
		$max = max( strlen( $a ), strlen( $b ) );
		if ( 0 === $max ) {
			return 0.0;
		}
		return levenshtein( $a, $b ) / $max;
	}
}

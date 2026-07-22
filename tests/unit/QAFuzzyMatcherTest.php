<?php
use PHPUnit\Framework\TestCase;

require_once ATTENDANT_PLUGIN_DIR . 'includes/fallback/class-attendant-qa-fuzzy-matcher.php';

/**
 * QA_Fuzzy_Matcher replaces embedding cosine for the no-AI mode. It scores
 * with a Jaccard token-overlap plus a Levenshtein penalty for typos, and
 * returns the highest-scoring pair above the threshold.
 */
final class QAFuzzyMatcherTest extends TestCase {

	/** @return array<int, array{question: string, answer: string, threshold: float}> */
	private function pairs(): array {
		return array(
			array(
				'id'        => 1,
				'question'  => 'How do I reset my password?',
				'answer'    => 'Visit the login screen and click Forgot password.',
				'threshold' => 0.65,
			),
			array(
				'id'        => 2,
				'question'  => 'What are your opening hours?',
				'answer'    => 'Monday to Friday 9am to 5pm.',
				'threshold' => 0.65,
			),
		);
	}

	public function test_exact_match_returns_pair(): void {
		$out = ATTENDANT_QA_Fuzzy_Matcher::match( 'How do I reset my password?', $this->pairs() );
		$this->assertNotNull( $out );
		$this->assertSame( 1, $out['id'] );
		$this->assertGreaterThanOrEqual( 0.95, $out['score'] );
	}

	public function test_paraphrase_match_above_threshold(): void {
		$out = ATTENDANT_QA_Fuzzy_Matcher::match( 'how can I reset my password', $this->pairs() );
		$this->assertNotNull( $out );
		$this->assertSame( 1, $out['id'] );
	}

	public function test_typo_tolerated(): void {
		$out = ATTENDANT_QA_Fuzzy_Matcher::match( 'how do I reset my passwrod', $this->pairs() );
		$this->assertNotNull( $out );
		$this->assertSame( 1, $out['id'] );
	}

	public function test_unrelated_text_returns_null(): void {
		$out = ATTENDANT_QA_Fuzzy_Matcher::match( 'what is the meaning of life', $this->pairs() );
		$this->assertNull( $out );
	}

	public function test_returns_highest_score_when_multiple_above_threshold(): void {
		$pairs   = $this->pairs();
		$pairs[] = array(
			'id'        => 3,
			'question'  => 'How do I reset password securely?',
			'answer'    => 'Use a strong unique passphrase.',
			'threshold' => 0.65,
		);
		$out = ATTENDANT_QA_Fuzzy_Matcher::match( 'How do I reset my password?', $pairs );
		$this->assertNotNull( $out );
		$this->assertSame( 1, $out['id'] );
	}
}

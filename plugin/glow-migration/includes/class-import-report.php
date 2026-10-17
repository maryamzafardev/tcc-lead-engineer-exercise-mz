<?php
/**
 * Import_Report class.
 *
 * @package GlowMigration
 */

namespace GlowMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Collects what happened during one import run: per-record outcomes,
 * warnings, and a tally of unrecognised controls.
 *
 * See specs/001-glow-article-importer/data-model.md §5–§6.
 */
class Import_Report {

	/**
	 * The only outcomes a record can have.
	 */
	const OUTCOMES = array( 'created', 'updated', 'unchanged', 'failed' );

	/**
	 * Warning codes the importer may raise.
	 */
	const WARNING_CODES = array(
		'unrecognised_control',
		'empty_body',
		'unusual_row_layout',
		'media_missing_image',
		'quote_missing_text',
		'html_fallback',
		'duplicate_match',
	);

	/**
	 * Count per outcome.
	 *
	 * @var array<string, int>
	 */
	private array $counts;

	/**
	 * One entry per processed record.
	 *
	 * @var array<int, array{line: int, legacy_id: ?int, post_id: ?int, outcome: string, title: string}>
	 */
	private array $rows = array();

	/**
	 * Warnings in the order they were raised.
	 *
	 * @var array<int, array{line: int, legacy_id: ?int, code: string, message: string}>
	 */
	private array $warnings = array();

	/**
	 * Unrecognised control tally: alias => { count, legacy_ids }.
	 *
	 * @var array<string, array{count: int, legacy_ids: int[]}>
	 */
	private array $unrecognised = array();

	/**
	 * Starts every outcome count at zero.
	 */
	public function __construct() {
		$this->counts = array_fill_keys( self::OUTCOMES, 0 );
	}

	/**
	 * Records the outcome for one record.
	 *
	 * @param int      $line      Line number in the export file (1-based).
	 * @param int|null $legacy_id Legacy article ID, if it could be read.
	 * @param int|null $post_id   WordPress post ID, if there is one.
	 * @param string   $outcome   One of self::OUTCOMES.
	 * @param string   $title     Article title, or an error message for failures.
	 * @throws \InvalidArgumentException When the outcome isn't recognised.
	 */
	public function record( int $line, ?int $legacy_id, ?int $post_id, string $outcome, string $title ): void {
		if ( ! in_array( $outcome, self::OUTCOMES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown import outcome "%s".', $outcome ) );
		}

		++$this->counts[ $outcome ];

		$this->rows[] = array(
			'line'      => $line,
			'legacy_id' => $legacy_id,
			'post_id'   => $post_id,
			'outcome'   => $outcome,
			'title'     => $title,
		);
	}

	/**
	 * Records a warning. Warnings never change a record's outcome.
	 *
	 * @param int      $line      Line number in the export file (1-based).
	 * @param int|null $legacy_id Legacy article ID, if known.
	 * @param string   $code      One of self::WARNING_CODES.
	 * @param string   $message   Human-readable detail.
	 * @throws \InvalidArgumentException When the code isn't recognised.
	 */
	public function warn( int $line, ?int $legacy_id, string $code, string $message ): void {
		if ( ! in_array( $code, self::WARNING_CODES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown warning code "%s".', $code ) );
		}

		$this->warnings[] = array(
			'line'      => $line,
			'legacy_id' => $legacy_id,
			'code'      => $code,
			'message'   => $message,
		);
	}

	/**
	 * Adds one occurrence of an unrecognised control to the tally.
	 *
	 * @param string $alias     The control's editor alias.
	 * @param int    $legacy_id Legacy article ID it was found in.
	 */
	public function add_unrecognised( string $alias, int $legacy_id ): void {
		if ( ! isset( $this->unrecognised[ $alias ] ) ) {
			$this->unrecognised[ $alias ] = array(
				'count'      => 0,
				'legacy_ids' => array(),
			);
		}

		++$this->unrecognised[ $alias ]['count'];

		if ( ! in_array( $legacy_id, $this->unrecognised[ $alias ]['legacy_ids'], true ) ) {
			$this->unrecognised[ $alias ]['legacy_ids'][] = $legacy_id;
		}
	}

	/**
	 * Count per outcome, always in the order created, updated, unchanged, failed.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		return $this->counts;
	}

	/**
	 * Per-record results.
	 *
	 * @return array<int, array{line: int, legacy_id: ?int, post_id: ?int, outcome: string, title: string}>
	 */
	public function rows(): array {
		return $this->rows;
	}

	/**
	 * Warnings raised during the run.
	 *
	 * @return array<int, array{line: int, legacy_id: ?int, code: string, message: string}>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	/**
	 * Unrecognised control tally.
	 *
	 * @return array<string, array{count: int, legacy_ids: int[]}>
	 */
	public function unrecognised(): array {
		return $this->unrecognised;
	}

	/**
	 * Whether any record failed.
	 *
	 * @return bool
	 */
	public function has_failures(): bool {
		return $this->counts['failed'] > 0;
	}
}

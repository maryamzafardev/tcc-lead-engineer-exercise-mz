<?php
/**
 * Importer class.
 *
 * @package GlowMigration
 */

namespace GlowMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Turns one legacy article record into a WordPress post.
 *
 * This is the User Story 1 version: it validates, converts and creates posts.
 * Matching existing posts (US2) and dates/status (US3) come in later phases.
 *
 * Authors, categories and media are never touched (FR-026): this class
 * doesn't read the legacy `author` or `category` fields, and doesn't set
 * `post_author`, `post_category` or `tags_input`.
 */
class Importer {

	/**
	 * Grid converter.
	 *
	 * @var Grid_Converter
	 */
	private Grid_Converter $converter;

	/**
	 * Run report.
	 *
	 * @var Import_Report
	 */
	private Import_Report $report;

	/**
	 * When true, nothing is written to the database.
	 *
	 * @var bool
	 */
	private bool $dry_run;

	/**
	 * Sets up the importer.
	 *
	 * @param Grid_Converter $converter Grid converter.
	 * @param Import_Report  $report    Run report to record outcomes in.
	 * @param bool           $dry_run   Whether to skip all writes.
	 */
	public function __construct( Grid_Converter $converter, Import_Report $report, bool $dry_run ) {
		$this->converter = $converter;
		$this->report    = $report;
		$this->dry_run   = $dry_run;
	}

	/**
	 * Imports one NDJSON line. Never throws: any error is recorded as a
	 * failed record so the rest of the file still imports (FR-002).
	 *
	 * @param string $line    Raw NDJSON line.
	 * @param int    $line_no 1-based line number, for reporting.
	 */
	public function import_line( string $line, int $line_no ): void {
		$legacy_id = null;

		try {
			$record    = json_decode( $line, true, 512, JSON_THROW_ON_ERROR );
			$legacy_id = $this->validate( $record );
			$title     = (string) $record['properties']['title'];

			$converted = $this->converter->convert( (string) ( $record['properties']['articleBody'] ?? '' ) );

			foreach ( $converted['notices'] as list( $code, $message ) ) {
				$this->report->warn( $line_no, $legacy_id, $code, $message );
			}

			$postarr = array(
				'post_type'    => 'post',
				'post_title'   => $title,
				'post_excerpt' => wp_strip_all_tags( (string) ( $record['properties']['excerpt'] ?? '' ) ),
				'post_name'    => $this->slug( $record ),
				'post_content' => $converted['content'],
				'meta_input'   => array(
					'_glow_legacy_id'          => $legacy_id,
					'_glow_legacy_path'        => (string) ( $record['path'] ?? '' ),
					'_glow_legacy_update_date' => (string) ( $record['updateDate'] ?? '' ),
				),
			);

			$post_id = null;

			if ( ! $this->dry_run ) {
				// wp_insert_post() unslashes its input, so slash it first; meta_input values included.
				$result = wp_insert_post( wp_slash( $postarr ), true );

				if ( is_wp_error( $result ) ) {
					throw new \RuntimeException( $result->get_error_message() );
				}

				$post_id = (int) $result;
			}

			$this->report->record( $line_no, $legacy_id, $post_id, 'created', $title );
		} catch ( \Throwable $e ) {
			$this->report->record( $line_no, $legacy_id, null, 'failed', $e->getMessage() );
		}
	}

	/**
	 * Checks the required fields (data-model.md §1) and returns the legacy ID.
	 *
	 * @param mixed $record Decoded record.
	 * @return int Legacy ID.
	 * @throws \UnexpectedValueException When a required field is missing or invalid.
	 */
	private function validate( $record ): int {
		if ( ! is_array( $record ) ) {
			throw new \UnexpectedValueException( 'Record is not a JSON object.' );
		}

		$id = $record['id'] ?? null;

		if ( ! is_int( $id ) || $id <= 0 ) {
			throw new \UnexpectedValueException( 'Missing or invalid legacy "id"; it must be a positive integer.' );
		}

		if ( '' === trim( (string) ( $record['properties']['title'] ?? '' ) ) ) {
			throw new \UnexpectedValueException( sprintf( 'Legacy article %d has no title.', $id ) );
		}

		$create_date = (string) ( $record['createDate'] ?? '' );

		if ( '' === $create_date || false === strtotime( $create_date ) ) {
			throw new \UnexpectedValueException( sprintf( 'Legacy article %d has a missing or unparseable "createDate".', $id ) );
		}

		return $id;
	}

	/**
	 * Post slug from `urlName`, falling back to the title (FR-006).
	 *
	 * @param array $record Decoded record.
	 * @return string
	 */
	private function slug( array $record ): string {
		$slug = sanitize_title( (string) ( $record['urlName'] ?? '' ) );

		return '' !== $slug ? $slug : sanitize_title( (string) $record['properties']['title'] );
	}
}

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
 * Each record is matched to its post by legacy ID and compared by a source
 * fingerprint, so reruns create, update or skip but never duplicate (US2).
 * Dates and status (US3) come in a later phase.
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

			$hash     = $this->source_hash( $record );
			$existing = $this->find_existing( $legacy_id, $line_no );

			// Same source and same converter version as last time: nothing to do (FR-019).
			if ( null !== $existing && get_post_meta( $existing, '_glow_import_hash', true ) === $hash ) {
				$this->report->record( $line_no, $legacy_id, $existing, 'unchanged', $title );
				return;
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
					'_glow_import_hash'        => $hash,
				),
			);

			$outcome = null === $existing ? 'created' : 'updated';
			$post_id = $existing;

			if ( ! $this->dry_run ) {
				$post_id = $this->save( $postarr, $existing );
			}

			$this->report->record( $line_no, $legacy_id, $post_id, $outcome, $title );
		} catch ( \Throwable $e ) {
			$this->report->record( $line_no, $legacy_id, null, 'failed', $e->getMessage() );
		}
	}

	/**
	 * Creates a new post, or updates the matched one in place. Never deletes (FR-022).
	 *
	 * @param array    $postarr  Post data, unslashed.
	 * @param int|null $existing Matched post ID, or null to create.
	 * @return int Post ID.
	 * @throws \RuntimeException When WordPress refuses the write.
	 */
	private function save( array $postarr, ?int $existing ): int {
		if ( null !== $existing ) {
			$postarr['ID'] = $existing;
		}

		// wp_insert_post()/wp_update_post() unslash their input, so slash it first; meta_input values included.
		$result = null === $existing
			? wp_insert_post( wp_slash( $postarr ), true )
			: wp_update_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() );
		}

		return (int) $result;
	}

	/**
	 * Finds the post previously imported for a legacy ID (FR-018).
	 *
	 * Every status is searched, trash included, so a trashed article is never
	 * imported a second time.
	 *
	 * @param int $legacy_id Legacy article ID.
	 * @param int $line_no   Line number, for the duplicate warning.
	 * @return int|null Post ID, or null if the article hasn't been imported yet.
	 */
	private function find_existing( int $legacy_id, int $line_no ): ?int {
		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array_merge( array_keys( get_post_stati() ), array( 'trash' ) ),
				'meta_key'         => '_glow_legacy_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $legacy_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		if ( array() === $ids ) {
			return null;
		}

		if ( count( $ids ) > 1 ) {
			$this->report->warn(
				$line_no,
				$legacy_id,
				'duplicate_match',
				sprintf( '%d posts share this legacy ID (%s); using post %d.', count( $ids ), implode( ', ', $ids ), $ids[0] )
			);
		}

		return (int) $ids[0];
	}

	/**
	 * Fingerprint of everything that affects the post (research R3).
	 *
	 * Includes `updateDate`, so any different `updateDate` counts as a change
	 * (FR-020), and the converter version, so improving the converter
	 * re-converts every post on the next run.
	 *
	 * @param array $record Decoded record.
	 * @return string SHA-256 hex digest.
	 */
	private function source_hash( array $record ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					CONVERTER_VERSION,
					$record['properties']['title'] ?? null,
					$record['properties']['excerpt'] ?? null,
					$record['urlName'] ?? null,
					$record['published'] ?? null,
					$record['createDate'] ?? null,
					$record['updateDate'] ?? null,
					$record['properties']['articleBody'] ?? null,
				)
			)
		);
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

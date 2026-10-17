<?php
/**
 * Import_Command class.
 *
 * @package GlowMigration
 */

namespace GlowMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Imports Glow Daily articles from the legacy CMS export.
 *
 * See specs/001-glow-article-importer/contracts/cli-import.md.
 */
class Import_Command {

	/**
	 * Imports articles from a legacy NDJSON export as posts.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the NDJSON export, e.g. wp-content/glow-fixtures/articles.ndjson.
	 *
	 * [--dry-run]
	 * : Decode, convert and compare every record without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     # Import the first export, attributing revisions to the admin user.
	 *     $ wp glow import wp-content/glow-fixtures/articles.ndjson --user=admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function import( $args, $assoc_args ): void {
		list( $file ) = $args;

		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			\WP_CLI::error( sprintf( 'Cannot read import file "%s".', $file ) );
		}

		$dry_run  = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$report   = new Import_Report();
		$importer = new Importer( new Grid_Converter(), $report, $dry_run );

		// WP-CLI runs without a user who has unfiltered_html, so KSES would strip
		// parts of the generated block markup (e.g. column styles). The content
		// comes from our own converter, which already applies an allow-list. (R4)
		kses_remove_filters();

		$warnings_shown = 0;
		$lines          = new \SplFileObject( $file );
		$lines->setFlags( \SplFileObject::DROP_NEW_LINE );

		foreach ( $lines as $index => $line ) {
			if ( '' === trim( (string) $line ) ) {
				continue;
			}

			$importer->import_line( (string) $line, $index + 1 );

			$rows = $report->rows();
			\WP_CLI::log( $this->progress_line( end( $rows ) ) );

			$warnings = $report->warnings();

			for ( $i = $warnings_shown, $n = count( $warnings ); $i < $n; $i++ ) {
				\WP_CLI::warning( sprintf( 'line %d legacy %s: %s', $warnings[ $i ]['line'], $warnings[ $i ]['legacy_id'] ?? '?', $warnings[ $i ]['message'] ) );
			}

			$warnings_shown = count( $warnings );
		}

		$this->print_summary( $report );

		$counts = $report->counts();

		if ( $report->has_failures() ) {
			\WP_CLI::error( sprintf( '%d record(s) failed; see the messages above.', $counts['failed'] ) );
		}

		\WP_CLI::success(
			sprintf(
				'Imported %d records (%d created, %d updated, %d unchanged).',
				array_sum( $counts ),
				$counts['created'],
				$counts['updated'],
				$counts['unchanged']
			)
		);
	}

	/**
	 * One progress line for a processed record.
	 *
	 * @param array $row Report row.
	 * @return string
	 */
	private function progress_line( array $row ): string {
		if ( 'failed' === $row['outcome'] ) {
			return sprintf( 'Failed line %d (legacy %s): %s', $row['line'], $row['legacy_id'] ?? '?', $row['title'] );
		}

		return sprintf(
			'%s post %s for legacy %d "%s"',
			ucfirst( $row['outcome'] ),
			$row['post_id'] ?? '-',
			$row['legacy_id'],
			$row['title']
		);
	}

	/**
	 * Prints the outcome table.
	 *
	 * @param Import_Report $report Run report.
	 */
	private function print_summary( Import_Report $report ): void {
		$rows = array();

		foreach ( $report->counts() as $outcome => $count ) {
			$rows[] = array(
				'outcome' => $outcome,
				'count'   => $count,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'outcome', 'count' ) );
	}
}

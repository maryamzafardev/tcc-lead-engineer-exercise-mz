<?php
/**
 * Grid_Converter class.
 *
 * @package GlowMigration
 */

namespace GlowMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Converts legacy grid JSON (sections → rows → areas → controls) into
 * serialized block markup. See specs/001-glow-article-importer/data-model.md §2–§3.
 */
class Grid_Converter {

	/**
	 * Legacy site that media paths are relative to.
	 */
	const LEGACY_ORIGIN = 'https://www.glowdaily.example';

	/**
	 * Rich text converter.
	 *
	 * @var Rte_Converter
	 */
	private Rte_Converter $rte;

	/**
	 * Notices raised during the current conversion.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $notices = array();

	/**
	 * Aliases of controls left out of the current conversion.
	 *
	 * @var string[]
	 */
	private array $dropped = array();

	/**
	 * Sets up the converter.
	 *
	 * @param Rte_Converter|null $rte Rich text converter. A new one is created if omitted.
	 */
	public function __construct( ?Rte_Converter $rte = null ) {
		$this->rte = $rte ?? new Rte_Converter();
	}

	/**
	 * Converts a string-encoded grid body into block markup.
	 *
	 * @param string $article_body_json The legacy `properties.articleBody` value.
	 * @return array{content: string, notices: array<int, array{0: string, 1: string}>, dropped: string[]}
	 * @throws \JsonException When the body isn't valid JSON. The importer marks the record failed.
	 */
	public function convert( string $article_body_json ): array {
		$this->notices = array();
		$this->dropped = array();

		$grid     = '' === trim( $article_body_json ) ? null : json_decode( $article_body_json, true, 512, JSON_THROW_ON_ERROR );
		$sections = is_array( $grid ) && is_array( $grid['sections'] ?? null ) ? $grid['sections'] : array();
		$blocks   = array();

		foreach ( $sections as $section ) {
			foreach ( (array) ( $section['rows'] ?? array() ) as $row ) {
				$blocks[] = $this->convert_row( (array) $row );
			}
		}

		$content = Block_Markup::join( $blocks );

		if ( '' === $content ) {
			$this->notices[] = array( 'empty_body', 'The article body has no content.' );
		}

		return array(
			'content' => $content,
			'notices' => $this->notices,
			'dropped' => $this->dropped,
		);
	}

	/**
	 * Converts one row. A single area goes straight into the post flow; two or
	 * more areas become a columns block (FR-012, FR-013).
	 *
	 * @param array $row Row data.
	 * @return string Serialized blocks, or '' if the row has no content.
	 */
	private function convert_row( array $row ): string {
		$areas = array_values( array_filter( (array) ( $row['areas'] ?? array() ), 'is_array' ) );

		if ( array() === $areas ) {
			return '';
		}

		if ( 1 === count( $areas ) ) {
			return $this->convert_area( $areas[0] );
		}

		$grids = array_map( static fn( $area ) => (int) ( $area['grid'] ?? 0 ), $areas );

		if ( 12 !== array_sum( $grids ) || count( $areas ) > 2 ) {
			$this->notices[] = array(
				'unusual_row_layout',
				sprintf( 'Row "%s" has area widths %s; converted using those proportions.', $row['name'] ?? '', implode( ' + ', $grids ) ),
			);
		}

		$columns   = array();
		$has_inner = false;

		foreach ( $areas as $i => $area ) {
			$inner     = $this->convert_area( $area );
			$has_inner = $has_inner || '' !== $inner;
			$columns[] = Block_Markup::column( $inner, self::width( $grids[ $i ] ) );
		}

		return $has_inner ? Block_Markup::columns( $columns ) : '';
	}

	/**
	 * Converts the controls in one area, in display order.
	 *
	 * @param array $area Area data.
	 * @return string
	 */
	private function convert_area( array $area ): string {
		$blocks = array();

		foreach ( (array) ( $area['controls'] ?? array() ) as $control ) {
			$blocks[] = $this->convert_control( (array) $control );
		}

		return Block_Markup::join( $blocks );
	}

	/**
	 * Converts one control by its editor alias.
	 *
	 * @param array $control Control data.
	 * @return string
	 */
	private function convert_control( array $control ): string {
		$alias = (string) ( $control['editor']['alias'] ?? '' );
		$value = $control['value'] ?? null;

		switch ( $alias ) {
			case 'rte':
				$result        = $this->rte->convert( is_string( $value ) ? $value : '' );
				$this->notices = array_merge( $this->notices, $result['notices'] );
				return $result['content'];

			case 'quote':
				$text = trim( (string) ( $value['text'] ?? '' ) );

				if ( '' === $text ) {
					$this->notices[] = array( 'quote_missing_text', 'Skipped a quote with no text.' );
					return '';
				}

				return Block_Markup::pullquote( $text, trim( (string) ( $value['citation'] ?? '' ) ) );

			case 'media':
				// value.id is deliberately ignored: media files aren't imported (FR-026).
				$path = trim( (string) ( $value['image'] ?? '' ) );

				if ( '' === $path ) {
					$this->notices[] = array( 'media_missing_image', 'Skipped an image with no file path.' );
					return '';
				}

				return Block_Markup::image(
					self::LEGACY_ORIGIN . '/' . ltrim( $path, '/' ),
					(string) ( $value['altText'] ?? '' ),
					trim( (string) ( $value['caption'] ?? '' ) )
				);

			default:
				// Retired or undocumented features (e.g. wirewaxVideoId, pinterestWidget):
				// leave them out so readers never see raw IDs or URLs, but keep a record (FR-016).
				// The rest of the area still converts, because each control is independent (FR-017).
				$label           = '' === $alias ? '(missing)' : $alias;
				$this->dropped[] = $label;
				$this->notices[] = array( 'unrecognised_control', sprintf( 'Left out unrecognised control "%s".', $label ) );
				return '';
		}
	}

	/**
	 * Column width as a percentage of 12 grid units, without trailing zeros.
	 *
	 * @param int $grid Area width out of 12.
	 * @return string e.g. "50%", "33.33%".
	 */
	private static function width( int $grid ): string {
		$percent = number_format( $grid / 12 * 100, 2, '.', '' );

		return rtrim( rtrim( $percent, '0' ), '.' ) . '%';
	}
}

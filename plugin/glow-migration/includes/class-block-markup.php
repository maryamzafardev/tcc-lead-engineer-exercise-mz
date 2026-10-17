<?php
/**
 * Block_Markup class.
 *
 * @package GlowMigration
 */

namespace GlowMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Emits serialized block markup for the core blocks the importer produces.
 *
 * Each method returns exactly what the block's save() function would output,
 * so the editor accepts the content without validation errors. See
 * specs/001-glow-article-importer/contracts/block-markup.md.
 *
 * Methods that take `$inner_html` expect content that is already escaped.
 * Methods that take plain text escape it themselves.
 */
class Block_Markup {

	/**
	 * Paragraph block.
	 *
	 * @param string $inner_html Escaped inline HTML.
	 * @return string
	 */
	public static function paragraph( string $inner_html ): string {
		return self::wrap( 'core/paragraph', array(), '<p>' . $inner_html . '</p>' );
	}

	/**
	 * Heading block. Level 2 is the block default, so it isn't stored.
	 *
	 * @param string $inner_html Escaped inline HTML.
	 * @param int    $level      Heading level, 1–6.
	 * @return string
	 */
	public static function heading( string $inner_html, int $level ): string {
		$attrs = 2 === $level ? array() : array( 'level' => $level );
		$tag   = 'h' . $level;

		return self::wrap( 'core/heading', $attrs, '<' . $tag . ' class="wp-block-heading">' . $inner_html . '</' . $tag . '>' );
	}

	/**
	 * List block.
	 *
	 * @param string[] $item_blocks Serialized list-item blocks.
	 * @param bool     $ordered     Whether this is an ordered list.
	 * @return string
	 */
	public static function list_block( array $item_blocks, bool $ordered ): string {
		$attrs = $ordered ? array( 'ordered' => true ) : array();
		$tag   = $ordered ? 'ol' : 'ul';

		return self::wrap( 'core/list', $attrs, '<' . $tag . ' class="wp-block-list">' . implode( '', $item_blocks ) . '</' . $tag . '>' );
	}

	/**
	 * List item block.
	 *
	 * @param string $inner_html  Escaped inline HTML.
	 * @param string $nested_list Optional serialized list block nested in this item.
	 * @return string
	 */
	public static function list_item( string $inner_html, string $nested_list = '' ): string {
		return self::wrap( 'core/list-item', array(), '<li>' . $inner_html . $nested_list . '</li>' );
	}

	/**
	 * Pullquote block.
	 *
	 * @param string $text     Plain-text quote.
	 * @param string $citation Plain-text citation. Left out when empty.
	 * @return string
	 */
	public static function pullquote( string $text, string $citation ): string {
		$cite = '' === $citation ? '' : '<cite>' . self::text( $citation ) . '</cite>';

		return self::wrap(
			'core/pullquote',
			array(),
			'<figure class="wp-block-pullquote"><blockquote><p>' . self::text( $text ) . '</p>' . $cite . '</blockquote></figure>'
		);
	}

	/**
	 * Image block referencing an external URL.
	 *
	 * There's no attachment `id` and no `wp-image-N` class, because media
	 * files aren't imported (FR-026).
	 *
	 * @param string $src     Absolute image URL.
	 * @param string $alt     Plain-text alt text. Always output, even when empty.
	 * @param string $caption Plain-text caption. Left out when empty.
	 * @return string
	 */
	public static function image( string $src, string $alt, string $caption ): string {
		$figcaption = '' === $caption ? '' : '<figcaption class="wp-element-caption">' . self::text( $caption ) . '</figcaption>';

		return self::wrap(
			'core/image',
			array(),
			'<figure class="wp-block-image"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"/>' . $figcaption . '</figure>'
		);
	}

	/**
	 * Columns block.
	 *
	 * @param string[] $column_blocks Serialized column blocks.
	 * @return string
	 */
	public static function columns( array $column_blocks ): string {
		return self::wrap( 'core/columns', array(), '<div class="wp-block-columns">' . implode( "\n\n", $column_blocks ) . '</div>' );
	}

	/**
	 * Column block with an explicit width.
	 *
	 * @param string $inner_blocks Serialized inner blocks.
	 * @param string $width        CSS width, e.g. "33.33%".
	 * @return string
	 */
	public static function column( string $inner_blocks, string $width ): string {
		return self::wrap(
			'core/column',
			array( 'width' => $width ),
			'<div class="wp-block-column" style="flex-basis:' . $width . '">' . $inner_blocks . '</div>'
		);
	}

	/**
	 * Custom HTML block, used as a fallback for markup that has no native equivalent.
	 *
	 * @param string $raw Raw HTML, stored unchanged.
	 * @return string
	 */
	public static function html( string $raw ): string {
		return self::wrap( 'core/html', array(), $raw );
	}

	/**
	 * Joins serialized blocks the same way the editor separates them.
	 *
	 * @param string[] $blocks Serialized blocks. Empty strings are dropped.
	 * @return string
	 */
	public static function join( array $blocks ): string {
		return implode( "\n\n", array_filter( $blocks, static fn( $block ) => '' !== $block ) );
	}

	/**
	 * Escapes plain text for use as element content.
	 *
	 * Only `&`, `<` and `>` are encoded. Quotes stay as typed, matching what the
	 * editor saves, so the first save in the editor doesn't change the content.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private static function text( string $text ): string {
		return htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	/**
	 * Adds the block comment delimiters around saved HTML.
	 *
	 * @param string $name  Block name, e.g. "core/paragraph".
	 * @param array  $attrs Block attributes stored in the opening delimiter.
	 * @param string $html  The block's saved HTML.
	 * @return string
	 */
	private static function wrap( string $name, array $attrs, string $html ): string {
		return get_comment_delimited_block_content( $name, $attrs, "\n" . $html . "\n" );
	}
}

<?php
/**
 * Rte_Converter class.
 *
 * @package GlowMigration
 */

namespace GlowMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Converts legacy rich text (rte) HTML into paragraph, heading and list blocks.
 *
 * Inline markup is filtered through an allow-list, so stray wrappers such as
 * `<span style>` are removed while their text is kept. Block-level elements
 * with no native equivalent fall back to a Custom HTML block. See research R2.
 */
class Rte_Converter {

	/**
	 * Inline tags kept as-is.
	 */
	const KEEP_INLINE = array( 'strong', 'em', 'sup', 'sub', 'code' );

	/**
	 * Inline tags renamed to their semantic equivalent.
	 */
	const RENAME_INLINE = array(
		'b' => 'strong',
		'i' => 'em',
	);

	/**
	 * Tags treated as inline content (kept, renamed or unwrapped).
	 */
	const INLINE_TAGS = array( 'a', 'abbr', 'b', 'br', 'code', 'del', 'em', 'font', 'i', 'ins', 'mark', 's', 'small', 'span', 'strong', 'sub', 'sup', 'u' );

	/**
	 * Wrapper elements whose children are processed as if they were top level.
	 */
	const UNWRAP_BLOCKS = array( 'div', 'section', 'article' );

	/**
	 * Link attributes kept on `<a>`.
	 */
	const LINK_ATTRIBUTES = array( 'href', 'target', 'rel' );

	/**
	 * Notices raised during the current conversion.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $notices = array();

	/**
	 * Converts an rte HTML fragment into serialized blocks.
	 *
	 * @param string $html Legacy rich text HTML.
	 * @return array{content: string, notices: array<int, array{0: string, 1: string}>}
	 */
	public function convert( string $html ): array {
		$this->notices = array();

		if ( '' === trim( $html ) ) {
			return array(
				'content' => '',
				'notices' => array(),
			);
		}

		$root = $this->load( $html );

		return array(
			'content' => Block_Markup::join( $this->convert_children( $root ) ),
			'notices' => $this->notices,
		);
	}

	/**
	 * Parses the fragment and returns its wrapper element.
	 *
	 * @param string $html HTML fragment.
	 * @return \DOMElement
	 */
	private function load( string $html ): \DOMElement {
		$doc = new \DOMDocument();
		$doc->loadHTML(
			'<?xml encoding="utf-8"?><div id="glow-root">' . $html . '</div>',
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD | LIBXML_HTML_NOIMPLIED
		);

		$root = ( new \DOMXPath( $doc ) )->query( '//div[@id="glow-root"]' )->item( 0 );

		return $root instanceof \DOMElement ? $root : $doc->createElement( 'div' );
	}

	/**
	 * Converts the children of a node into a list of serialized blocks.
	 *
	 * Runs of text and inline elements are buffered into one paragraph.
	 *
	 * @param \DOMNode $parent Parent node.
	 * @return string[]
	 */
	private function convert_children( \DOMNode $parent ): array {
		$blocks = array();
		$inline = '';

		foreach ( $parent->childNodes as $node ) {
			if ( $this->is_inline( $node ) ) {
				$inline .= $this->inline( $node );
				continue;
			}

			if ( ! $node instanceof \DOMElement ) {
				continue; // Comments, processing instructions.
			}

			$blocks[] = $this->paragraph_if_not_empty( $inline );
			$inline   = '';

			array_push( $blocks, ...$this->convert_block( $node ) );
		}

		$blocks[] = $this->paragraph_if_not_empty( $inline );

		return array_values( array_filter( $blocks, static fn( $block ) => '' !== $block ) );
	}

	/**
	 * Converts one block-level element.
	 *
	 * @param \DOMElement $el Element.
	 * @return string[]
	 */
	private function convert_block( \DOMElement $el ): array {
		$tag = strtolower( $el->tagName );

		if ( 'p' === $tag ) {
			return array( $this->paragraph_if_not_empty( $this->inline_children( $el ) ) );
		}

		if ( preg_match( '/^h([1-6])$/', $tag, $m ) ) {
			$inner = $this->inline_children( $el );

			return $this->is_blank( $inner ) ? array() : array( Block_Markup::heading( trim( $inner ), max( 2, (int) $m[1] ) ) );
		}

		if ( 'ul' === $tag || 'ol' === $tag ) {
			$list = $this->list_block( $el );

			return '' === $list ? array() : array( $list );
		}

		if ( in_array( $tag, self::UNWRAP_BLOCKS, true ) ) {
			return $this->convert_children( $el );
		}

		$this->notices[] = array( 'html_fallback', sprintf( 'Kept <%s> as a Custom HTML block.', $tag ) );

		return array( Block_Markup::html( $el->ownerDocument->saveHTML( $el ) ) );
	}

	/**
	 * Converts a `<ul>` or `<ol>` element, including nested lists.
	 *
	 * @param \DOMElement $el List element.
	 * @return string Serialized list block, or '' if it has no items.
	 */
	private function list_block( \DOMElement $el ): string {
		$items = array();

		foreach ( $el->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement || 'li' !== strtolower( $child->tagName ) ) {
				continue; // Whitespace between items, or invalid children.
			}

			$inner  = '';
			$nested = '';

			foreach ( $child->childNodes as $part ) {
				if ( $part instanceof \DOMElement && in_array( strtolower( $part->tagName ), array( 'ul', 'ol' ), true ) ) {
					$nested .= $this->list_block( $part );
				} else {
					$inner .= $this->inline( $part );
				}
			}

			if ( $this->is_blank( $inner ) && '' === $nested ) {
				continue;
			}

			$items[] = Block_Markup::list_item( trim( $inner ), $nested );
		}

		return array() === $items ? '' : Block_Markup::list_block( $items, 'ol' === strtolower( $el->tagName ) );
	}

	/**
	 * Serializes all children of an element as inline HTML.
	 *
	 * @param \DOMNode $el Element.
	 * @return string
	 */
	private function inline_children( \DOMNode $el ): string {
		$html = '';

		foreach ( $el->childNodes as $child ) {
			$html .= $this->inline( $child );
		}

		return $html;
	}

	/**
	 * Serializes one node as inline HTML, applying the allow-list.
	 *
	 * @param \DOMNode $node Node.
	 * @return string
	 */
	private function inline( \DOMNode $node ): string {
		if ( $node instanceof \DOMText ) {
			return htmlspecialchars( $node->data, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		}

		if ( ! $node instanceof \DOMElement ) {
			return '';
		}

		$tag = strtolower( $node->tagName );

		if ( 'br' === $tag ) {
			return '<br>';
		}

		$tag = self::RENAME_INLINE[ $tag ] ?? $tag;

		if ( 'a' === $tag ) {
			$attrs = '';

			foreach ( self::LINK_ATTRIBUTES as $name ) {
				if ( $node->hasAttribute( $name ) ) {
					// esc_attr, not esc_url: {localLink:N} placeholders must survive for the link-rewriting pass.
					$attrs .= ' ' . $name . '="' . esc_attr( $node->getAttribute( $name ) ) . '"';
				}
			}

			return '<a' . $attrs . '>' . $this->inline_children( $node ) . '</a>';
		}

		if ( in_array( $tag, self::KEEP_INLINE, true ) ) {
			return '<' . $tag . '>' . $this->inline_children( $node ) . '</' . $tag . '>';
		}

		// Anything else (span, font, u, …): drop the tag, keep the content.
		return $this->inline_children( $node );
	}

	/**
	 * Whether a node belongs in inline content.
	 *
	 * @param \DOMNode $node Node.
	 * @return bool
	 */
	private function is_inline( \DOMNode $node ): bool {
		if ( $node instanceof \DOMText ) {
			return true;
		}

		return $node instanceof \DOMElement && in_array( strtolower( $node->tagName ), self::INLINE_TAGS, true );
	}

	/**
	 * Wraps inline HTML in a paragraph block, unless it has no visible text.
	 *
	 * @param string $inline Inline HTML.
	 * @return string
	 */
	private function paragraph_if_not_empty( string $inline ): string {
		return $this->is_blank( $inline ) ? '' : Block_Markup::paragraph( trim( $inline ) );
	}

	/**
	 * Whether inline HTML is empty apart from whitespace, `&nbsp;` and `<br>` (FR-015).
	 *
	 * @param string $inline Inline HTML.
	 * @return bool
	 */
	private function is_blank( string $inline ): bool {
		$text = str_replace( array( "\u{00A0}", '<br>' ), ' ', wp_strip_all_tags( $inline ) );

		return '' === trim( $text ) && false === stripos( $inline, '<img' );
	}
}

<?php
/**
 * Framework-free test runner for the Glow migration converters.
 *
 * Run inside wp-env:
 *   npx wp-env run cli wp eval-file wp-content/plugins/glow-migration/tests/run.php
 *
 * To (re)write snapshot files instead of comparing against them:
 *   npx wp-env run cli env GLOW_UPDATE_SNAPSHOTS=1 wp eval-file wp-content/plugins/glow-migration/tests/run.php
 *
 * @package GlowMigration
 */

namespace GlowMigration\Tests;

use GlowMigration\Block_Markup;
use GlowMigration\Grid_Converter;
use GlowMigration\Import_Report;
use GlowMigration\Importer;
use GlowMigration\Rte_Converter;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal assertion collector.
 */
final class Runner {

	/**
	 * Number of passed assertions.
	 *
	 * @var int
	 */
	private int $passed = 0;

	/**
	 * Labels of failed assertions.
	 *
	 * @var string[]
	 */
	private array $failed = array();

	/**
	 * Prints a section heading.
	 *
	 * @param string $title Section title.
	 */
	public function section( string $title ): void {
		echo "\n== {$title}\n";
	}

	/**
	 * Asserts strict equality, printing a diff-friendly message on failure.
	 *
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $label    Assertion label.
	 */
	public function assert_same( $expected, $actual, string $label ): void {
		if ( $expected === $actual ) {
			$this->pass( $label );
			return;
		}

		$this->fail(
			$label,
			"    expected: " . var_export( $expected, true ) . "\n    actual:   " . var_export( $actual, true )
		);
	}

	/**
	 * Asserts a condition is true.
	 *
	 * @param bool   $condition Condition.
	 * @param string $label     Assertion label.
	 */
	public function assert_true( bool $condition, string $label ): void {
		$condition ? $this->pass( $label ) : $this->fail( $label );
	}

	/**
	 * Compares against tests/snapshots/{$name}.html, or writes it when
	 * GLOW_UPDATE_SNAPSHOTS=1.
	 *
	 * @param string $name   Snapshot name.
	 * @param string $actual Actual markup.
	 */
	public function snapshot( string $name, string $actual ): void {
		$file = __DIR__ . '/snapshots/' . $name . '.html';

		if ( '1' === getenv( 'GLOW_UPDATE_SNAPSHOTS' ) ) {
			file_put_contents( $file, $actual ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->pass( "snapshot {$name} (written)" );
			return;
		}

		if ( ! file_exists( $file ) ) {
			$this->fail( "snapshot {$name}", '    missing snapshot file; run with GLOW_UPDATE_SNAPSHOTS=1' );
			return;
		}

		$this->assert_same( file_get_contents( $file ), $actual, "snapshot {$name}" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Asserts that parsing and re-serializing the markup gives it back unchanged.
	 *
	 * @param string $html  Serialized blocks.
	 * @param string $label Assertion label.
	 */
	public function roundtrip( string $html, string $label ): void {
		$this->assert_same( $html, serialize_blocks( parse_blocks( $html ) ), "roundtrip: {$label}" );
	}

	/**
	 * Prints the totals and exits non-zero if anything failed.
	 */
	public function finish(): void {
		$failed = count( $this->failed );

		echo "\n{$this->passed} passed, {$failed} failed\n";

		foreach ( $this->failed as $label ) {
			echo "  FAILED: {$label}\n";
		}

		exit( $failed ? 1 : 0 );
	}

	/**
	 * Records a pass.
	 *
	 * @param string $label Assertion label.
	 */
	private function pass( string $label ): void {
		++$this->passed;
		echo "PASS {$label}\n";
	}

	/**
	 * Records a failure.
	 *
	 * @param string $label  Assertion label.
	 * @param string $detail Optional detail lines.
	 */
	private function fail( string $label, string $detail = '' ): void {
		$this->failed[] = $label;
		echo "FAIL {$label}\n" . ( '' === $detail ? '' : $detail . "\n" );
	}
}

$t = new Runner();

/*
 * Block_Markup: every expected string is copied from
 * specs/001-glow-article-importer/contracts/block-markup.md.
 */
$t->section( 'Block_Markup' );

$cases = array(
	'paragraph'              => array(
		Block_Markup::paragraph( 'x' ),
		"<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->",
	),
	'heading h2'             => array(
		Block_Markup::heading( 'x', 2 ),
		"<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">x</h2>\n<!-- /wp:heading -->",
	),
	'heading h3'             => array(
		Block_Markup::heading( 'x', 3 ),
		"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">x</h3>\n<!-- /wp:heading -->",
	),
	'unordered list'         => array(
		Block_Markup::list_block( array( Block_Markup::list_item( 'a' ) ), false ),
		"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>a</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
	),
	'ordered list'           => array(
		Block_Markup::list_block( array( Block_Markup::list_item( 'a' ) ), true ),
		"<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\"><!-- wp:list-item -->\n<li>a</li>\n<!-- /wp:list-item --></ol>\n<!-- /wp:list -->",
	),
	'pullquote with cite'    => array(
		Block_Markup::pullquote( 'TEXT', 'CITATION' ),
		"<!-- wp:pullquote -->\n<figure class=\"wp-block-pullquote\"><blockquote><p>TEXT</p><cite>CITATION</cite></blockquote></figure>\n<!-- /wp:pullquote -->",
	),
	'pullquote without cite' => array(
		Block_Markup::pullquote( 'TEXT', '' ),
		"<!-- wp:pullquote -->\n<figure class=\"wp-block-pullquote\"><blockquote><p>TEXT</p></blockquote></figure>\n<!-- /wp:pullquote -->",
	),
	'image with caption'     => array(
		Block_Markup::image( 'https://www.glowdaily.example/media/5501/spring-flatlay.jpg', 'ALT', 'CAPTION' ),
		"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://www.glowdaily.example/media/5501/spring-flatlay.jpg\" alt=\"ALT\"/><figcaption class=\"wp-element-caption\">CAPTION</figcaption></figure>\n<!-- /wp:image -->",
	),
	'image without caption'  => array(
		Block_Markup::image( 'https://www.glowdaily.example/media/5530/fragrance-bottles.jpg', '', '' ),
		"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img src=\"https://www.glowdaily.example/media/5530/fragrance-bottles.jpg\" alt=\"\"/></figure>\n<!-- /wp:image -->",
	),
	'html fallback'          => array(
		Block_Markup::html( '<table><tr><td>x</td></tr></table>' ),
		"<!-- wp:html -->\n<table><tr><td>x</td></tr></table>\n<!-- /wp:html -->",
	),
);

$four_eight = Block_Markup::columns(
	array(
		Block_Markup::column( Block_Markup::paragraph( 'left' ), '33.33%' ),
		Block_Markup::column( Block_Markup::paragraph( 'right' ), '66.67%' ),
	)
);

$cases['columns 4 + 8'] = array(
	$four_eight,
	"<!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column {\"width\":\"33.33%\"} -->\n<div class=\"wp-block-column\" style=\"flex-basis:33.33%\"><!-- wp:paragraph -->\n<p>left</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n<!-- wp:column {\"width\":\"66.67%\"} -->\n<div class=\"wp-block-column\" style=\"flex-basis:66.67%\"><!-- wp:paragraph -->\n<p>right</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->",
);

foreach ( $cases as $label => list( $actual, $expected ) ) {
	$t->assert_same( $expected, $actual, $label );
	$t->roundtrip( $actual, $label );
}

$t->assert_true(
	false === strpos( $cases['image with caption'][0] . $cases['image without caption'][0], 'wp-image-' ),
	'image has no wp-image- class (FR-026)'
);
$t->assert_true(
	false === strpos( $cases['image with caption'][0], '"id"' ),
	'image has no id attribute (FR-026)'
);
$t->assert_same(
	"a\n\nb",
	Block_Markup::join( array( 'a', '', 'b' ) ),
	'join drops empty blocks and separates with a blank line'
);
$t->assert_same(
	"<!-- wp:pullquote -->\n<figure class=\"wp-block-pullquote\"><blockquote><p>Fresh &amp; citrusy &lt;b&gt;</p></blockquote></figure>\n<!-- /wp:pullquote -->",
	Block_Markup::pullquote( 'Fresh & citrusy <b>', '' ),
	'pullquote escapes plain text'
);
$t->assert_true(
	false !== strpos( Block_Markup::pullquote( "I'd never skip it", "Mia's pick" ), "<p>I'd never skip it</p><cite>Mia's pick</cite>" ),
	'pullquote keeps apostrophes as typed (no &#039;)'
);

/*
 * Import_Report.
 */
$t->section( 'Import_Report' );

$report = new Import_Report();
$report->record( 1, 10231, 12, 'created', 'A' );
$report->record( 2, 10232, 13, 'unchanged', 'B' );
$report->warn( 2, 10232, 'unrecognised_control', 'wirewaxVideoId left out' );
$report->add_unrecognised( 'wirewaxVideoId', 10232 );
$report->add_unrecognised( 'wirewaxVideoId', 10232 );

$t->assert_same(
	array(
		'created'   => 1,
		'updated'   => 0,
		'unchanged' => 1,
		'failed'    => 0,
	),
	$report->counts(),
	'counts use exactly the four outcomes'
);
$t->assert_true( ! $report->has_failures(), 'no failures yet' );
$t->assert_same(
	array(
		'wirewaxVideoId' => array(
			'count'      => 2,
			'legacy_ids' => array( 10232 ),
		),
	),
	$report->unrecognised(),
	'unrecognised tally counts occurrences and de-duplicates IDs'
);

$report->record( 3, null, null, 'failed', 'Syntax error' );
$t->assert_true( $report->has_failures(), 'has_failures after a failed record' );

try {
	$report->record( 4, 1, 1, 'skipped', 'x' );
	$t->assert_true( false, 'rejects unknown outcome "skipped"' );
} catch ( \InvalidArgumentException $e ) {
	$t->assert_true( true, 'rejects unknown outcome "skipped"' );
}

/*
 * Rte_Converter (T009).
 */
$t->section( 'Rte_Converter' );

$rte = new Rte_Converter();

$rte_cases = array(
	'nbsp-only paragraph is dropped'       => array(
		'<p>&nbsp;</p>',
		'',
	),
	'stray span is unwrapped'              => array(
		'<p>a <span style="color:#ff0000">b</span></p>',
		Block_Markup::paragraph( 'a b' ),
	),
	'heading then paragraph'               => array(
		'<h2>x</h2><p>y</p>',
		Block_Markup::join( array( Block_Markup::heading( 'x', 2 ), Block_Markup::paragraph( 'y' ) ) ),
	),
	'h3 keeps its level'                   => array(
		'<h3>Fresh &amp; citrusy</h3>',
		Block_Markup::heading( 'Fresh &amp; citrusy', 3 ),
	),
	'h1 becomes h2'                        => array(
		'<h1>Title</h1>',
		Block_Markup::heading( 'Title', 2 ),
	),
	'unordered list with 2 items'          => array(
		'<ul><li>a</li><li>b</li></ul>',
		Block_Markup::list_block( array( Block_Markup::list_item( 'a' ), Block_Markup::list_item( 'b' ) ), false ),
	),
	'nested list'                          => array(
		'<ul><li>a<ul><li>b</li></ul></li></ul>',
		Block_Markup::list_block(
			array( Block_Markup::list_item( 'a', Block_Markup::list_block( array( Block_Markup::list_item( 'b' ) ), false ) ) ),
			false
		),
	),
	'localLink href is kept unchanged'     => array(
		'<p>See <a href="{localLink:10299}">this</a>.</p>',
		Block_Markup::paragraph( 'See <a href="{localLink:10299}">this</a>.' ),
	),
	'legacy-domain href is kept'           => array(
		'<p><a href="https://www.glowdaily.example/skincare/best-vitamin-c-serums/" class="x" onclick="y()">r</a></p>',
		Block_Markup::paragraph( '<a href="https://www.glowdaily.example/skincare/best-vitamin-c-serums/">r</a>' ),
	),
	'entities are not double-encoded'      => array(
		'<p>Fresh &amp; citrusy</p>',
		Block_Markup::paragraph( 'Fresh &amp; citrusy' ),
	),
	'b and i become strong and em'         => array(
		'<p><b>bold</b> <i>it</i> <strong>s</strong> <em>e</em></p>',
		Block_Markup::paragraph( '<strong>bold</strong> <em>it</em> <strong>s</strong> <em>e</em>' ),
	),
	'loose top-level text becomes a paragraph' => array(
		'Hello <strong>there</strong><p>next</p>',
		Block_Markup::join( array( Block_Markup::paragraph( 'Hello <strong>there</strong>' ), Block_Markup::paragraph( 'next' ) ) ),
	),
	'div wrapper is unwrapped'             => array(
		'<div><p>inside</p></div>',
		Block_Markup::paragraph( 'inside' ),
	),
	'emoji and apostrophes survive'        => array(
		"<p>We ❤️ it, don't we?</p>",
		Block_Markup::paragraph( "We ❤️ it, don't we?" ),
	),
);

foreach ( $rte_cases as $label => list( $input, $expected ) ) {
	$result = $rte->convert( $input );
	$t->assert_same( $expected, $result['content'], $label );
	if ( '' !== $result['content'] ) {
		$t->roundtrip( $result['content'], $label );
	}
}

$fallback = $rte->convert( '<p>a</p><table><tr><td>x</td></tr></table>' );
$t->assert_true( false !== strpos( $fallback['content'], '<!-- wp:html -->' ), 'unknown block element falls back to a Custom HTML block' );
$t->assert_same( 'html_fallback', $fallback['notices'][0][0] ?? null, 'fallback raises an html_fallback notice' );
$t->roundtrip( $fallback['content'], 'html fallback' );

/*
 * Grid_Converter against the fixtures (T011).
 */
$t->section( 'Grid_Converter (fixtures)' );

$grid      = new Grid_Converter();
$converted = array();

foreach ( array( 'articles.ndjson' => '', 'articles-v2.ndjson' => '-v2' ) as $file => $suffix ) {
	foreach ( file( ABSPATH . 'wp-content/glow-fixtures/' . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$record  = json_decode( $line, true );
		$id      = (int) $record['id'];
		$content = $grid->convert( $record['properties']['articleBody'] )['content'];

		$converted[ $id . $suffix ] = $content;
		$t->snapshot( "article-{$id}{$suffix}", $content );
		$t->roundtrip( $content, "article {$id}{$suffix}" );
		$t->assert_true( false === strpos( $content, 'wp-image-' ), "article {$id}{$suffix} has no wp-image- class (FR-026)" );
	}
}

$t->assert_true(
	false !== strpos( $converted['10232'], '"width":"33.33%"' ) && false !== strpos( $converted['10232'], '"width":"66.67%"' ),
	'10232 Product & Text row becomes 33.33% / 66.67% columns'
);
$t->assert_same( 2, substr_count( $converted['10233'], '"width":"50%"' ), '10233 Text & Content row becomes two 50% columns' );
$t->assert_same( 0, substr_count( $converted['10231'], '<p></p>' ) + substr_count( $converted['10231'], "\u{00A0}</p>" ), '10231 has no empty paragraph from <p>&nbsp;</p>' );
$t->assert_true( false !== strpos( $converted['10231'], '{localLink:10233}' ), '10231 keeps its {localLink:10233} href' );
$t->assert_true( false === strpos( $converted['10234'], '<span' ), '10234 stray span removed' );
$t->assert_true(
	false !== strpos( $converted['10235'], 'src="https://www.glowdaily.example/media/5530/fragrance-bottles.jpg" alt=""' ),
	'10235 image points at the legacy URL with empty alt'
);

$edge = $grid->convert( '' );
$t->assert_same( '', $edge['content'], 'empty body gives empty content' );
$t->assert_same( 'empty_body', $edge['notices'][0][0] ?? null, 'empty body raises empty_body' );

try {
	$grid->convert( '{not json' );
	$t->assert_true( false, 'invalid JSON body throws JsonException' );
} catch ( \JsonException $e ) {
	$t->assert_true( true, 'invalid JSON body throws JsonException' );
}

$odd = $grid->convert(
	wp_json_encode(
		array(
			'sections' => array(
				array(
					'rows' => array(
						array(
							'name'  => 'Three',
							'areas' => array(
								array( 'grid' => 4, 'controls' => array( array( 'editor' => array( 'alias' => 'rte' ), 'value' => '<p>a</p>' ) ) ),
								array( 'grid' => 4, 'controls' => array( array( 'editor' => array( 'alias' => 'quote' ), 'value' => array( 'text' => '' ) ) ) ),
								array( 'grid' => 4, 'controls' => array( array( 'editor' => array( 'alias' => 'media' ), 'value' => array( 'image' => '' ) ) ) ),
							),
						),
					),
				),
			),
		)
	)
);
$codes = array_column( $odd['notices'], 0 );
$t->assert_true( in_array( 'unusual_row_layout', $codes, true ), 'three-area row raises unusual_row_layout' );
$t->assert_true( in_array( 'quote_missing_text', $codes, true ), 'empty quote raises quote_missing_text' );
$t->assert_true( in_array( 'media_missing_image', $codes, true ), 'image without path raises media_missing_image' );
$t->assert_same( 3, substr_count( $odd['content'], '"width":"33.33%"' ), 'three-area row becomes three 33.33% columns' );
$t->roundtrip( $odd['content'], 'three-area row' );

/*
 * Unrecognised controls (T027).
 */
$t->section( 'Unrecognised controls' );

$records = array();
foreach ( file( ABSPATH . 'wp-content/glow-fixtures/articles.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	$record                         = json_decode( $line, true );
	$records[ (int) $record['id'] ] = $grid->convert( $record['properties']['articleBody'] );
}

foreach ( array( 10232, 10233 ) as $id ) {
	foreach ( array( 'wirewax', '8812345', 'pinterest.com' ) as $needle ) {
		$t->assert_true( false === stripos( $records[ $id ]['content'], $needle ), "{$id} content has no \"{$needle}\" (SC-006)" );
	}
}

$t->assert_same( array( 'wirewaxVideoId' ), $records[10232]['dropped'], '10232 dropped exactly wirewaxVideoId' );
$t->assert_same( array( 'pinterestWidget' ), $records[10233]['dropped'], '10233 dropped exactly pinterestWidget' );
$t->assert_same( array(), $records[10231]['dropped'], '10231 dropped nothing' );
$t->assert_true(
	in_array( 'unrecognised_control', array_column( $records[10232]['notices'], 0 ), true ),
	'10232 raises an unrecognised_control notice'
);

// FR-017: the controls next to the dropped one still convert.
$t->assert_true( false !== strpos( $records[10232]['content'], 'What an essence does' ), '10232 keeps the rte that follows wirewaxVideoId in the same area' );
$t->assert_true( false !== strpos( $records[10233]['content'], 'SPF is the one product' ), '10233 keeps the quote before pinterestWidget in the same area' );

$missing = $grid->convert( wp_json_encode( array( 'sections' => array( array( 'rows' => array( array( 'areas' => array( array( 'grid' => 12, 'controls' => array( array( 'value' => 'x' ) ) ) ) ) ) ) ) ) ) );
$t->assert_same( array( '(missing)' ), $missing['dropped'], 'control with no alias is recorded as "(missing)"' );

/*
 * Importer::dates_for (T022).
 */
$t->section( 'Importer::dates_for' );

$t->assert_same(
	'2021-09-02 22:15:00',
	Importer::dates_for( '2021-09-03T08:15:00+10:00' )['post_date_gmt'],
	'10231 createDate +10:00 → GMT 2021-09-02 22:15:00'
);
$t->assert_same(
	'2022-03-13 23:00:00',
	Importer::dates_for( '2022-03-14T10:00:00+11:00' )['post_date_gmt'],
	'10232 createDate +11:00 (daylight saving) → GMT 2022-03-13 23:00:00'
);

// The wp-env site runs in UTC, which would hide site-time bugs, so check a non-UTC site too.
$sydney = static fn() => 'Australia/Sydney';
add_filter( 'pre_option_timezone_string', $sydney );

$t->assert_same(
	array(
		'post_date'     => '2021-09-03 08:15:00',
		'post_date_gmt' => '2021-09-02 22:15:00',
	),
	Importer::dates_for( '2021-09-03T08:15:00+10:00' ),
	'Sydney site (AEST): site time equals the legacy wall-clock time'
);
$t->assert_same(
	'2022-03-14 10:00:00',
	Importer::dates_for( '2022-03-14T10:00:00+11:00' )['post_date'],
	'Sydney site (AEDT): daylight-saving date keeps its wall-clock time'
);

remove_filter( 'pre_option_timezone_string', $sydney );

try {
	Importer::dates_for( 'not a date' );
	$t->assert_true( false, 'unparseable date throws' );
} catch ( \Exception $e ) {
	$t->assert_true( true, 'unparseable date throws' );
}

$t->finish();

<?php
/**
 * Helpers for showing migrated articles on the front end.
 *
 * @package GlowMigration
 */

namespace GlowMigration;

/**
 * Get random imported articles, optionally from one section of the site.
 *
 * Used by the "You might also like" area at the bottom of every article.
 *
 * Example: get_random_articles( 3, 'skincare' );
 *
 * @param int    $count   Number of articles to return.
 * @param string $section Optional legacy section, for example 'skincare'.
 * @return \WP_Post[] Random articles.
 */
function get_random_articles( $count = 3, $section = '' ) {
	$args = array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => $count,
		'orderby'        => 'rand',
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'     => '_glow_legacy_id',
				'compare' => 'EXISTS',
			),
		),
	);

	if ( $section ) {
		$args['meta_query'][] = array(
			'key'     => '_glow_legacy_path',
			'value'   => '/' . $section . '/',
			'compare' => 'LIKE',
		);
	}

	return get_posts( $args );
}

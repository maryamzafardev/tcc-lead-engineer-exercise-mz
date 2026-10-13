<?php
/**
 * Imports images from the legacy site and attaches them to migrated articles.
 *
 * @package GlowMigration
 */

namespace GlowMigration;

use WP_CLI;

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

class Media_Importer {

	/**
	 * Import all images referenced in the export.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the NDJSON export.
	 *
	 * @param array $args CLI arguments.
	 */
	public function __invoke( $args ) {
		$lines    = file( $args[0] );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Importing media', count( $lines ) );

		foreach ( $lines as $line ) {
			$article = json_decode( $line, true );
			$post_id = $this->find_post( $article['id'] );

			$body   = json_decode( $article['properties']['articleBody'], true );
			$images = $this->get_images( $body );

			foreach ( $images as $i => $image ) {
				$url = 'https://www.glowdaily.example' . $image['image']

				$tmp = download_url( $url );

				$file = array(
					'name'     => basename( $url ),
					'tmp_name' => $tmp,
				);

				$attachment_id = media_handle_sideload( $file, $post_id, $image['altText'] );

				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $image['altText'] );

				if ( $i === 0 ) {
					set_post_thumbnail( $post_id, $attachment_id );
				}
			}

			$progress->tick();
		}

		$progress->finish();
		WP_CLI::success( 'Media imported!' );
	}

	/**
	 * Find the WordPress post for a legacy node ID.
	 *
	 * @param int $legacy_id Legacy node ID.
	 * @return int Post ID.
	 */
	private function find_post( $legacy_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		foreach ( $posts as $post ) {
			if ( get_post_meta( $post->ID, '_glow_legacy_id', true ) == $legacy_id ) {
				return $post->ID;
			}
		}

		return 0;
	}

	/**
	 * Collect every media control from the grid JSON.
	 *
	 * @param array $body Decoded grid JSON.
	 * @return array Media control values.
	 */
	private function get_images( $body ) {
		$images = array();

		foreach ( $body['sections'] as $section ) {
			foreach ( $section['rows'] as $row ) {
				foreach ( $row['areas'] as $area ) {
					foreach ( $area['controls'] as $control ) {
						if ( 'media' === $control['editor']['alias'] ) {
							$images[] = $control['value'];
						}
					}
				}
			}
		}

		return $images;
	}
}

WP_CLI::add_command( 'glow media', __NAMESPACE__ . '\\Media_Importer' );

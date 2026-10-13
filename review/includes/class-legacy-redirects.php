<?php
/**
 * Redirects legacy Glow Daily URLs to their migrated posts.
 *
 * @package GlowMigration
 */

namespace GlowMigration;

class Legacy_Redirects {

	const OPTION = 'glow_legacy_redirects';

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
	}

	/**
	 * Build the redirect map from the export. Called by the importer after each run.
	 *
	 * @param string $file Path to the NDJSON export.
	 */
	public static function build_map( $file ) {
		$map = get_option( self::OPTION, array() );

		foreach ( file( $file ) as $line ) {
			$article = json_decode( $line, true );

			$posts = get_posts(
				array(
					'meta_key'   => '_glow_legacy_id',
					'meta_value' => $article['id'],
				)
			);

			if ( $posts ) {
				$map[ $article['path'] ] = get_permalink( $posts[0] );
			}

			// Editors sometimes pasted full URLs, so store those too.
			$map[ 'https://www.glowdaily.example' . $article['path'] ] = get_permalink( $posts[0] );
		}

		update_option( self::OPTION, $map );
	}

	/**
	 * Redirect if the current URL is a legacy URL.
	 */
	public function maybe_redirect() {
		if ( ! is_404() ) {
			return;
		}

		$map  = get_option( self::OPTION, array() );
		$path = $_SERVER['REQUEST_URI'];

		if ( isset( $map[ $path ] ) ) {
			wp_redirect( $map[ $path ] );
		}

		// Let the content team test redirects before launch.
		if ( isset( $_GET['glow_test_redirect'] ) ) {
			wp_redirect( $_GET['glow_test_redirect'] );
			exit;
		}
	}

	public function add_admin_page() {
		add_management_page( 'Legacy redirects', 'Legacy redirects', 'read', 'glow-redirects', array( $this, 'render_admin_page' ) );
	}

	public function render_admin_page() {
		$map = get_option( self::OPTION, array() );

		echo '<div class="wrap"><h1>Legacy redirects (' . count( $map ) . ')</h1><table class="widefat">';

		foreach ( $map as $from => $to ) {
			echo '<tr><td>' . $from . '</td><td><a href="' . $to . '">' . $to . '</a></td></tr>';
		}

		echo '</table></div>';
	}
}

new Legacy_Redirects();

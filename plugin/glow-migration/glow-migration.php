<?php
/**
 * Plugin Name: Glow Daily Migration
 * Description: Imports Glow Daily articles from the legacy CMS export.
 * Version:     0.1.0
 * Requires PHP: 8.1
 *
 * @package GlowMigration
 */

namespace GlowMigration;

defined( 'ABSPATH' ) || exit;

const GLOW_MIGRATION_DIR = __DIR__;

/**
 * Version of the grid-to-block conversion. It's part of each post's import
 * hash, so bumping it makes the next run re-convert every post.
 */
const CONVERTER_VERSION = '1';

require_once GLOW_MIGRATION_DIR . '/includes/class-block-markup.php';
require_once GLOW_MIGRATION_DIR . '/includes/class-import-report.php';
require_once GLOW_MIGRATION_DIR . '/includes/class-rte-converter.php';
require_once GLOW_MIGRATION_DIR . '/includes/class-grid-converter.php';
require_once GLOW_MIGRATION_DIR . '/includes/class-importer.php';
require_once GLOW_MIGRATION_DIR . '/includes/class-import-command.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'glow', Import_Command::class );
}

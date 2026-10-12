<?php
/**
 * Plugin Name: Folderino
 * Plugin URI: https://github.com/rinothecoder/folderino
 * Description: Organise the Media Library into folders. Drag and drop, subfolders, and no file on disk is ever moved.
 * Version: 1.0.1
 * Author: Rino de Boer
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: folderino
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/rinothecoder/folderino/
 */

defined( 'ABSPATH' ) || exit;

define( 'FOLDERINO_VERSION', '1.0.1' );
define( 'FOLDERINO_FILE', __FILE__ );
define( 'FOLDERINO_DIR', plugin_dir_path( __FILE__ ) );
define( 'FOLDERINO_URL', plugin_dir_url( __FILE__ ) );
// The private taxonomy that holds the folders. Files are only linked to it, never moved.
define( 'FOLDERINO_TAX', 'folderino_folder' );

require_once FOLDERINO_DIR . 'includes/context.php';
require_once FOLDERINO_DIR . 'includes/class-folderino-migrate.php';
require_once FOLDERINO_DIR . 'includes/class-folderino-updates.php';
require_once FOLDERINO_DIR . 'includes/class-folderino-admin.php';

add_action( 'plugins_loaded', 'folderino_boot', 99 );

/**
 * Start Folderino. Visitors on the front end never load the folder code.
 */
function folderino_boot() {
	Folderino_Updates::boot();

	// The old media folders snippet is still running. Two copies would clash, so we wait.
	if ( function_exists( 'rdb_mf_get_tree' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-warning"><p><strong>Folderino:</strong> ' . esc_html__( 'the old media folders snippet is still active. Turn it off so Folderino can take over. Your folders stay exactly as they are.', 'folderino' ) . '</p></div>';
			}
		);
		return;
	}

	if ( is_admin() ) {
		Folderino_Admin::boot();
	} elseif ( ! folderino_is_bricks_builder() ) {
		return;
	}

	Folderino_Migrate::boot();

	require_once FOLDERINO_DIR . 'includes/functions.php';
	require_once FOLDERINO_DIR . 'includes/query.php';
	require_once FOLDERINO_DIR . 'includes/ajax.php';
	require_once FOLDERINO_DIR . 'includes/assets.php';
}
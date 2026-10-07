<?php
/**
 * Folderino. Context: where Folderino runs.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Are we inside the Bricks builder main window (?bricks=run)?
 * The preview iframe (&brickspreview) does not need the folders.
 */
function folderino_is_bricks_builder() {
	if ( is_admin() || ! isset( $_GET['bricks'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return false;
	}
	$bricks = sanitize_key( wp_unslash( $_GET['bricks'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	return 'run' === $bricks && ! isset( $_GET['brickspreview'] ); // phpcs:ignore WordPress.Security.NonceVerification
}

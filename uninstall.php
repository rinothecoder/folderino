<?php
/**
 * Folderino uninstall.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'folderino_migrated' );
// The folders themselves are kept on purpose, so installing Folderino again brings them back.
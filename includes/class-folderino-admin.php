<?php
/**
 * Folderino. The "Check for updates" link on the Plugins screen, and its result.
 */

defined( 'ABSPATH' ) || exit;

final class Folderino_Admin {
	public static function boot() {
		add_filter( 'plugin_action_links_' . plugin_basename( FOLDERINO_FILE ), array( __CLASS__, 'links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function links( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=folderino_check_updates' ), 'folderino_check_updates' );
			array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'folderino' ) . '</a>' );
		}
		return $links;
	}

	// After a check: say what was found, right on the Plugins screen.
	public static function notice() {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only shows a read-only notice after our own nonce-checked redirect.
		if ( 'plugins.php' !== $pagenow || empty( $_GET['folderino_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$status = Folderino_Updates::status();
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p><strong>Folderino:</strong> %2$s</p></div>',
			esc_attr( $status['type'] ),
			esc_html( $status['message'] )
		);
	}
}

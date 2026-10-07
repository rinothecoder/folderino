<?php
/**
 * Folderino. One-time move of existing folders to the Folderino taxonomy.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Folders created by the earlier version of this tool lived in the taxonomy "rdb_media_folder".
 * This renames that taxonomy in place, so every folder keeps its ID, its files, its order
 * and its count. Nothing is copied, nothing is deleted, and no file on disk is touched.
 */
final class Folderino_Migrate {
	const OLD_TAX  = 'rdb_media_folder';
	const OLD_META = 'rdb_mf_order';
	const OPTION   = 'folderino_migrated';

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'maybe_run' ), 1 );
	}

	public static function maybe_run() {
		if ( get_option( self::OPTION ) ) {
			return;
		}
		global $wpdb;

		$old = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::OLD_TAX ) );
		$new = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", FOLDERINO_TAX ) );

		// Folders already exist on both sides: never merge by guessing. Leave everything as it is.
		if ( $old && $new ) {
			update_option( self::OPTION, array( 'status' => 'skipped', 'time' => time() ), false );
			return;
		}

		if ( $old ) {
			$wpdb->update( $wpdb->term_taxonomy, array( 'taxonomy' => FOLDERINO_TAX ), array( 'taxonomy' => self::OLD_TAX ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $wpdb->termmeta, array( 'meta_key' => 'folderino_order' ), array( 'meta_key' => self::OLD_META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			wp_cache_flush();
		}

		update_option( self::OPTION, array( 'status' => 'done', 'folders' => $old, 'time' => time() ), false );
	}
}
<?php
/**
 * Folderino. Filtering the Media Library by folder, and putting new uploads in the open folder.
 */

defined( 'ABSPATH' ) || exit;

/* ==================================================================
 * 3. FILTERING THE MEDIA LIBRARY BY FOLDER
 * ==================================================================
 * "none" = Uncategorized, a number = that folder (not its subfolders),
 * anything else = no filter (All files).
 */

/**
 * Build the tax_query for a folder value, or null for "All files".
 */
function folderino_tax_query( $folder ) {
	if ( 'none' === $folder ) {
		return array(
			array(
				'taxonomy' => FOLDERINO_TAX,
				'operator' => 'NOT EXISTS',
			),
		);
	}
	$id = absint( $folder );
	if ( ! $id ) {
		return null;
	}
	return array(
		array(
			'taxonomy'         => FOLDERINO_TAX,
			'field'            => 'term_id',
			'terms'            => array( $id ),
			'include_children' => false,
		),
	);
}

add_filter( 'ajax_query_attachments_args', 'folderino_filter_grid_query' );

/**
 * Grid view and media modals load files through admin-ajax "query-attachments".
 * WordPress strips unknown keys from that query, so we read our value from the raw request.
 */
function folderino_filter_grid_query( $args ) {
	// phpcs:ignore WordPress.Security.NonceVerification -- read-only filter; core already checked upload_files.
	$query = ( isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ) ? wp_unslash( $_REQUEST['query'] ) : array();
	if ( empty( $query['folderino_folder'] ) || ! is_scalar( $query['folderino_folder'] ) ) {
		return $args;
	}
	$tax_query = folderino_tax_query( sanitize_key( $query['folderino_folder'] ) );
	if ( $tax_query ) {
		$existing          = ( isset( $args['tax_query'] ) && is_array( $args['tax_query'] ) ) ? $args['tax_query'] : array();
		$args['tax_query'] = array_merge( $existing, $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}
	return $args;
}

add_action( 'pre_get_posts', 'folderino_filter_list_view' );

/**
 * List view (upload.php?mode=list) uses a normal page load with ?folderino_folder=ID.
 */
function folderino_filter_list_view( $query ) {
	global $pagenow;
	if ( ! is_admin() || 'upload.php' !== $pagenow || ! $query->is_main_query() ) {
		return;
	}
	if ( empty( $_GET['folderino_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$tax_query = folderino_tax_query( sanitize_key( wp_unslash( $_GET['folderino_folder'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( $tax_query ) {
		$existing = $query->get( 'tax_query' );
		$existing = is_array( $existing ) ? $existing : array();
		$query->set( 'tax_query', array_merge( $existing, $tax_query ) );
	}
}

/* ==================================================================
 * 4. NEW UPLOADS GO INTO THE OPEN FOLDER
 * ==================================================================
 * Our JavaScript adds folderino_folder + a nonce to every upload request
 * while a folder is open. Core has already checked its own upload nonce
 * and the upload_files capability; we check ours on top.
 */

add_action( 'add_attachment', 'folderino_assign_new_upload' );

function folderino_assign_new_upload( $attachment_id ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified just below.
	if ( empty( $_POST['folderino_folder'] ) || empty( $_POST['folderino_nonce'] ) ) {
		return;
	}
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['folderino_nonce'] ) ), 'folderino_nonce' ) ) {
		return;
	}
	$folder = folderino_get_folder( absint( $_POST['folderino_folder'] ) );
	// phpcs:enable
	if ( $folder ) {
		wp_set_object_terms( $attachment_id, array( (int) $folder->term_id ), FOLDERINO_TAX, false );
	}
}

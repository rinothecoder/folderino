<?php
/**
 * Folderino. AJAX actions: create, rename, delete, move and assign.
 */

defined( 'ABSPATH' ) || exit;

/* ==================================================================
 * 5. AJAX ACTIONS (create, rename, delete, move, assign)
 * ==================================================================
 * Every action: logged-in only (wp_ajax_), upload_files capability,
 * a valid nonce, sanitised input. Each one returns the fresh tree.
 */

add_action( 'wp_ajax_folderino_tree', 'folderino_ajax_tree' );
add_action( 'wp_ajax_folderino_create', 'folderino_ajax_create' );
add_action( 'wp_ajax_folderino_rename', 'folderino_ajax_rename' );
add_action( 'wp_ajax_folderino_delete', 'folderino_ajax_delete' );
add_action( 'wp_ajax_folderino_move_folder', 'folderino_ajax_move_folder' );
add_action( 'wp_ajax_folderino_assign', 'folderino_ajax_assign' );

/**
 * Security gate used by every AJAX action.
 */
function folderino_ajax_guard() {
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_send_json_error( array( 'message' => __( 'You are not allowed to manage media folders.', 'folderino' ) ), 403 );
	}
	check_ajax_referer( 'folderino_nonce', 'nonce' ); // Stops with a 403 when the nonce is wrong.
}

/**
 * Send an error back to the sidebar.
 */
function folderino_ajax_fail( $message ) {
	wp_send_json_error( array( 'message' => $message ), 400 );
}

/**
 * Send success + the fresh folder tree back to the sidebar.
 */
function folderino_ajax_done( $message, $extra = array() ) {
	wp_send_json_success(
		array_merge(
			array(
				'message' => $message,
				'tree'    => folderino_get_tree(),
			),
			$extra
		)
	);
}

/** Return the current tree (used to refresh counts after uploads). */
function folderino_ajax_tree() {
	folderino_ajax_guard();
	folderino_ajax_done( '' );
}

/** Create a folder (optionally inside another folder). */
function folderino_ajax_create() {
	folderino_ajax_guard();
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in folderino_ajax_guard().
	$name   = folderino_clean_name( isset( $_POST['name'] ) ? $_POST['name'] : '' );
	$parent = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;
	// phpcs:enable

	if ( '' === $name ) {
		folderino_ajax_fail( __( 'Please enter a folder name.', 'folderino' ) );
	}
	if ( $parent && ! folderino_get_folder( $parent ) ) {
		folderino_ajax_fail( __( 'The parent folder no longer exists.', 'folderino' ) );
	}
	if ( folderino_name_taken( $name, $parent ) ) {
		folderino_ajax_fail( __( 'A folder with this name already exists here.', 'folderino' ) );
	}

	$order  = folderino_next_order( $parent ); // New folders go to the bottom.
	$result = wp_insert_term( $name, FOLDERINO_TAX, array( 'parent' => $parent ) );
	if ( is_wp_error( $result ) ) {
		folderino_ajax_fail( $result->get_error_message() );
	}
	update_term_meta( $result['term_id'], 'folderino_order', $order );

	folderino_ajax_done( sprintf( 'Folder "%s" created.', $name ), array( 'id' => (int) $result['term_id'] ) );
}

/** Rename a folder. Only the name changes; files are not touched. */
function folderino_ajax_rename() {
	folderino_ajax_guard();
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$folder = folderino_get_folder( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
	$name   = folderino_clean_name( isset( $_POST['name'] ) ? $_POST['name'] : '' );
	// phpcs:enable

	if ( ! $folder ) {
		folderino_ajax_fail( __( 'This folder no longer exists.', 'folderino' ) );
	}
	if ( '' === $name ) {
		folderino_ajax_fail( __( 'Please enter a folder name.', 'folderino' ) );
	}
	if ( folderino_name_taken( $name, $folder->parent, $folder->term_id ) ) {
		folderino_ajax_fail( __( 'A folder with this name already exists here.', 'folderino' ) );
	}

	$result = wp_update_term( $folder->term_id, FOLDERINO_TAX, array( 'name' => $name ) );
	if ( is_wp_error( $result ) ) {
		folderino_ajax_fail( $result->get_error_message() );
	}
	folderino_ajax_done( __( 'Folder renamed.', 'folderino' ) );
}

/**
 * Delete a folder.
 * Only the folder (the term) is removed. Its files lose the folder link and
 * become Uncategorized. Subfolders move up one level (WordPress does this
 * automatically for hierarchical taxonomies). No file is deleted.
 */
function folderino_ajax_delete() {
	folderino_ajax_guard();
	$folder = folderino_get_folder( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	if ( ! $folder ) {
		folderino_ajax_fail( __( 'This folder no longer exists.', 'folderino' ) );
	}
	// Rank Math adds an "SEO Notice: redirect this term's URL" after every term
	// delete. Our folders have no URLs, so we remove that notice again, but only
	// when our delete created it (so we never hide a real notice).
	$rank_math_notice = is_callable( array( '\\RankMath\\Helper', 'has_notification' ) )
		&& \RankMath\Helper::has_notification( 'auto_post_redirection' );

	$result = wp_delete_term( $folder->term_id, FOLDERINO_TAX );

	if ( ! $rank_math_notice && is_callable( array( '\\RankMath\\Helper', 'remove_notification' ) ) ) {
		\RankMath\Helper::remove_notification( 'auto_post_redirection' );
	}
	if ( is_wp_error( $result ) || ! $result ) {
		folderino_ajax_fail( __( 'The folder could not be deleted.', 'folderino' ) );
	}
	folderino_ajax_done( __( 'Folder deleted. Its files are now Uncategorized.', 'folderino' ) );
}

/**
 * Move a folder: change its parent (nesting) and/or its position.
 * The browser sends the new parent and the full new order of that parent's children.
 */
function folderino_ajax_move_folder() {
	folderino_ajax_guard();
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$folder = folderino_get_folder( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
	$parent = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;
	$order  = isset( $_POST['order'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order'] ) ) : array();
	// phpcs:enable

	if ( ! $folder ) {
		folderino_ajax_fail( __( 'This folder no longer exists.', 'folderino' ) );
	}
	if ( $parent ) {
		if ( ! folderino_get_folder( $parent ) ) {
			folderino_ajax_fail( __( 'The target folder no longer exists.', 'folderino' ) );
		}
		// A folder can't go inside itself or inside one of its own subfolders.
		$descendants = get_term_children( $folder->term_id, FOLDERINO_TAX );
		$descendants = is_wp_error( $descendants ) ? array() : array_map( 'intval', $descendants );
		if ( $parent === (int) $folder->term_id || in_array( $parent, $descendants, true ) ) {
			folderino_ajax_fail( __( 'A folder can\'t be moved into itself.', 'folderino' ) );
		}
	}

	// 1. New parent?
	if ( (int) $folder->parent !== $parent ) {
		if ( folderino_name_taken( folderino_plain_name( $folder->name ), $parent, $folder->term_id ) ) {
			folderino_ajax_fail( __( 'A folder with this name already exists there.', 'folderino' ) );
		}
		$result = wp_update_term( $folder->term_id, FOLDERINO_TAX, array( 'parent' => $parent ) );
		if ( is_wp_error( $result ) ) {
			folderino_ajax_fail( $result->get_error_message() );
		}
	}

	// 2. New order among its siblings. Only real siblings are accepted.
	if ( ! in_array( (int) $folder->term_id, $order, true ) ) {
		$order[] = (int) $folder->term_id;
	}
	$position = 0;
	foreach ( array_unique( $order ) as $sibling_id ) {
		$sibling = folderino_get_folder( $sibling_id );
		if ( $sibling && (int) $sibling->parent === $parent ) {
			update_term_meta( $sibling->term_id, 'folderino_order', $position );
			$position++;
		}
	}

	folderino_ajax_done( __( 'Folder moved.', 'folderino' ) );
}

/**
 * Put one or more files into a folder (folder 0 = Uncategorized).
 * A file is in one folder at a time: the new folder replaces the old one.
 * This only changes term relationships, never the file itself.
 */
function folderino_ajax_assign() {
	folderino_ajax_guard();
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$ids       = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
	$folder_id = isset( $_POST['folder'] ) ? absint( $_POST['folder'] ) : 0;
	// phpcs:enable

	$ids = array_slice( array_unique( array_filter( $ids ) ), 0, 1000 );
	if ( ! $ids ) {
		folderino_ajax_fail( __( 'No files selected.', 'folderino' ) );
	}

	$folder = null;
	if ( $folder_id ) {
		$folder = folderino_get_folder( $folder_id );
		if ( ! $folder ) {
			folderino_ajax_fail( __( 'This folder no longer exists.', 'folderino' ) );
		}
	}

	$moved = 0;
	foreach ( $ids as $id ) {
		if ( 'attachment' !== get_post_type( $id ) ) {
			continue;
		}
		// Integer term IDs on purpose: strings would be treated as term names.
		$terms  = $folder ? array( (int) $folder->term_id ) : array();
		$result = wp_set_object_terms( $id, $terms, FOLDERINO_TAX, false );
		if ( ! is_wp_error( $result ) ) {
			$moved++;
		}
	}

	$target = $folder ? folderino_plain_name( $folder->name ) : 'Uncategorized';
	folderino_ajax_done( sprintf( '%1$d %2$s moved to "%3$s".', $moved, 1 === $moved ? 'file' : 'files', $target ) );
}

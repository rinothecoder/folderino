<?php
/**
 * Folderino. Loading the CSS and JavaScript.
 */

defined( 'ABSPATH' ) || exit;

/* ==================================================================
 * 6. LOADING THE CSS AND JAVASCRIPT
 * ==================================================================
 * Only where the media modal loads (the wp_enqueue_media action fires
 * whenever WordPress, Gutenberg or Bricks sets up the media modal),
 * plus Media > Library, which we make load the media scripts too.
 */

add_action( 'admin_enqueue_scripts', 'folderino_library_enqueue' );
add_action( 'wp_enqueue_media', 'folderino_enqueue_assets' );

/**
 * Media > Library in list view doesn't load the media scripts by itself.
 */
function folderino_library_enqueue( $hook ) {
	if ( 'upload.php' === $hook && current_user_can( 'upload_files' ) && ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wp_enqueue_media(); // Fires wp_enqueue_media => folderino_enqueue_assets().
	}
}

/**
 * Is this Media > Library (upload.php), where the sidebar is shown?
 */
function folderino_is_library_screen() {
	global $pagenow;
	return is_admin() && 'upload.php' === $pagenow && ! isset( $_GET['page'] ); // phpcs:ignore WordPress.Security.NonceVerification
}

/**
 * Register our CSS and JavaScript files.
 */
function folderino_enqueue_assets() {
	if ( ! current_user_can( 'upload_files' ) || wp_script_is( 'folderino', 'enqueued' ) ) {
		return;
	}

	$is_library = folderino_is_library_screen();
	$deps       = array( 'jquery', 'underscore', 'media-views' );
	if ( $is_library ) {
		$deps[] = 'jquery-ui-draggable'; // Shipped with WordPress.
		$deps[] = 'jquery-ui-droppable';
	}

	// Current folder in list view comes from the URL.
	$current = 'all';
	if ( ! empty( $_GET['folderino_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$current = sanitize_key( wp_unslash( $_GET['folderino_folder'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	$data = array(
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( 'folderino_nonce' ),
		'isLibrary' => $is_library,
		'current'   => $current,
		'tree'      => folderino_get_tree(),
		'i18n'      => array(
			'folders'       => __( 'Folders', 'folderino' ),
			'all'           => __( 'All files', 'folderino' ),
			'none'          => __( 'Uncategorized', 'folderino' ),
			'newFolder'     => __( 'New folder', 'folderino' ),
			'addSub'        => __( 'New subfolder', 'folderino' ),
			'rename'        => __( 'Rename', 'folderino' ),
			'del'           => __( 'Delete', 'folderino' ),
			'cancel'        => __( 'Cancel', 'folderino' ),
			'folderName'    => __( 'Folder name', 'folderino' ),
			'deleteConfirm' => __( 'Delete the folder "%s"? Its files stay in the Media Library and become Uncategorized. Subfolders move up one level.', 'folderino' ),
			'empty'         => __( 'No folders yet.', 'folderino' ),
			'hint'          => __( 'Drag files onto a folder to move them. Drag folders to reorder or nest them.', 'folderino' ),
			'moveOne'       => __( 'Move 1 file', 'folderino' ),
			'moveMany'      => __( 'Move %d files', 'folderino' ),
			'error'         => __( 'Something went wrong. Please try again.', 'folderino' ),
			'allFolders'    => __( 'All folders', 'folderino' ),
			'filterLabel'   => __( 'Filter by folder', 'folderino' ),
			'toggle'        => __( 'Show or hide subfolders', 'folderino' ),
			'widen'         => __( 'Make the folder panel wider', 'folderino' ),
			'narrow'        => __( 'Make the folder panel narrower', 'folderino' ),
			'uploadingTo'   => __( 'Uploading to "%s"...', 'folderino' ),
			'uploadedOne'   => __( '1 file uploaded', 'folderino' ),
			'uploadedMany'  => __( '%d files uploaded', 'folderino' ),
			'uploadedTo'    => __( ' to "%s".', 'folderino' ),
		),
	);

	wp_register_script( 'folderino', FOLDERINO_URL . 'assets/folderino.js', $deps, FOLDERINO_VERSION . '.' . filemtime( FOLDERINO_DIR . 'assets/folderino.js' ), true );
	wp_enqueue_script( 'folderino' );
	wp_add_inline_script( 'folderino', 'window.folderino = ' . wp_json_encode( $data ) . ';', 'before' );
	
	wp_register_style( 'folderino', FOLDERINO_URL . 'assets/folderino.css', array(), FOLDERINO_VERSION . '.' . filemtime( FOLDERINO_DIR . 'assets/folderino.css' ) );
	wp_enqueue_style( 'folderino' );
}

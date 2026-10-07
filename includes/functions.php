<?php
/**
 * Folderino. The folder taxonomy and small helpers.
 */

defined( 'ABSPATH' ) || exit;

/* ==================================================================
 * 1. THE TAXONOMY (our "folders")
 * ================================================================== */

add_action( 'init', 'folderino_register_taxonomy' );

/**
 * Register the private, hierarchical folder taxonomy for attachments.
 * Not public, no URLs, no REST, no core UI: our sidebar is the only UI.
 */
function folderino_register_taxonomy() {
	register_taxonomy(
		FOLDERINO_TAX,
		'attachment',
		array(
			'labels'                => array(
				'name'          => 'Media Folders',
				'singular_name' => 'Media Folder',
			),
			'hierarchical'          => true,  // Allows subfolders.
			'public'                => false, // No front-end archive pages.
			'publicly_queryable'    => false,
			'show_ui'               => false, // Hides the default checkbox box on the attachment screen.
			'show_in_menu'          => false,
			'show_in_nav_menus'     => false,
			'show_tagcloud'         => false,
			'show_in_quick_edit'    => false,
			'show_admin_column'     => false,
			'show_in_rest'          => false,
			'query_var'             => false, // No ?folderino_folder= on the front end.
			'rewrite'               => false, // No pretty URLs.
			// Count every file in a folder. The default counter skips attachments
			// whose parent post is not published, which would give wrong numbers.
			'update_count_callback' => '_update_generic_term_count',
			// Anyone who may upload files may organise them.
			'capabilities'          => array(
				'manage_terms' => 'upload_files',
				'edit_terms'   => 'upload_files',
				'delete_terms' => 'upload_files',
				'assign_terms' => 'upload_files',
			),
		)
	);
}

/* ==================================================================
 * 2. SMALL HELPERS
 * ================================================================== */

/**
 * Return a folder (WP_Term) by ID, or null if it is not one of our folders.
 */
function folderino_get_folder( $id ) {
	$id = absint( $id );
	if ( ! $id ) {
		return null;
	}
	$term = get_term( $id, FOLDERINO_TAX );
	return ( $term instanceof WP_Term ) ? $term : null;
}

/**
 * Clean a folder name that came from the browser.
 */
function folderino_clean_name( $raw ) {
	$name = sanitize_text_field( wp_unslash( (string) $raw ) );
	return trim( mb_substr( $name, 0, 200 ) );
}

/**
 * Folder names are stored HTML-encoded by WordPress (& becomes &amp;).
 * Decode them for comparing and for sending to JavaScript (which prints them as plain text).
 */
function folderino_plain_name( $name ) {
	return html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
}

/**
 * Does a sibling folder (same parent) already use this name?
 */
function folderino_name_taken( $name, $parent, $exclude_id = 0 ) {
	$siblings = get_terms(
		array(
			'taxonomy'   => FOLDERINO_TAX,
			'hide_empty' => false,
			'parent'     => absint( $parent ),
		)
	);
	if ( is_wp_error( $siblings ) ) {
		return false;
	}
	foreach ( $siblings as $sibling ) {
		if ( (int) $sibling->term_id === (int) $exclude_id ) {
			continue;
		}
		if ( 0 === strcasecmp( folderino_plain_name( $sibling->name ), $name ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The next sort position under a parent (new folders go to the bottom).
 */
function folderino_next_order( $parent ) {
	$max      = -1;
	$siblings = get_terms(
		array(
			'taxonomy'   => FOLDERINO_TAX,
			'hide_empty' => false,
			'parent'     => absint( $parent ),
			'fields'     => 'ids',
		)
	);
	if ( ! is_wp_error( $siblings ) ) {
		foreach ( $siblings as $sibling_id ) {
			$max = max( $max, (int) get_term_meta( $sibling_id, 'folderino_order', true ) );
		}
	}
	return $max + 1;
}

/**
 * Everything the sidebar needs, in one flat list:
 * folders (id, name, parent, order, count) + "All files" and "Uncategorized" counts.
 * Folder order is kept in term meta "folderino_order".
 */
function folderino_get_tree() {
	global $wpdb;

	$folders = array();
	$terms   = get_terms(
		array(
			'taxonomy'   => FOLDERINO_TAX,
			'hide_empty' => false,
		)
	);

	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$folders[] = array(
				'id'     => (int) $term->term_id,
				'name'   => folderino_plain_name( $term->name ),
				'parent' => (int) $term->parent,
				'order'  => (int) get_term_meta( $term->term_id, 'folderino_order', true ),
				'count'  => (int) $term->count,
			);
		}
	}

	// Sort by our saved order, then alphabetically.
	usort(
		$folders,
		function ( $a, $b ) {
			if ( $a['order'] !== $b['order'] ) {
				return $a['order'] - $b['order'];
			}
			return strnatcasecmp( $a['name'], $b['name'] );
		}
	);

	// All files = every attachment the Media Library can show.
	$total = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status IN ('inherit','private')"
	);

	// Uncategorized = attachments without any folder.
	$none = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'attachment' AND p.post_status IN ('inherit','private')
			 AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tr.object_id = p.ID AND tt.taxonomy = %s
			 )",
			FOLDERINO_TAX
		)
	);

	return array(
		'folders' => $folders,
		'total'   => $total,
		'none'    => $none,
	);
}

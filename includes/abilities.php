<?php
/**
 * Folderino abilities, so AI agents can organise the Media Library through MCP.
 * Separate from the sidebar AJAX code, which stays untouched.
 *
 * @package Folderino
 */

defined( 'ABSPATH' ) || exit;

final class Folderino_Abilities {
	public static function init() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	public static function allowed() {
		return current_user_can( 'upload_files' );
	}

	private static function meta( $readonly, $destructive, $idempotent ) {
		return array(
			'public'       => true,
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'readonly'    => $readonly,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
		);
	}

	private static function add( $name, $label, $description, $properties, $required, $meta ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( $required ) {
			$schema['required'] = $required;
		} else {
			$schema['default'] = array();
		}
		wp_register_ability(
			'folderino/' . $name,
			array(
				'label'               => 'Folderino: ' . $label,
				'description'         => $description,
				'category'            => 'site',
				'input_schema'        => $schema,
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, str_replace( '-', '_', $name ) ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
				'meta'                => $meta,
			)
		);
	}

	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		$folder_id  = array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Folder ID, as returned by list-folders.' );
		$name       = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Folder name. Must be unique among the folders that share the same parent.' );
		$parent_opt = array( 'type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Parent folder ID. 0 (default) means top level.' );
		$parent_req = array( 'type' => 'integer', 'minimum' => 0, 'description' => 'New parent folder ID. 0 moves the folder to the top level.' );

		self::add(
			'list-folders',
			'List Folders',
			'List the Media Library folders as a flat list (ID, name, parent ID, sort order, file count) plus the number of all files and of Uncategorized files. A file lives in at most one folder, and folders only link files: no file on disk is ever moved. Optionally pass parent to see only the folders directly under one folder (0 for top level). Requires upload_files.',
			array( 'parent' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Optional. Only return folders directly under this parent folder ID (0 for top level). Omit for the whole tree.' ) ),
			array(),
			self::meta( true, false, true )
		);
		self::add(
			'list-files',
			'List Files',
			'List Media Library files, newest first, with ID, title, filename, MIME type, URL and the ID of the folder each file is in (0 when Uncategorized). Filter by folder: all, none (Uncategorized) or a folder ID. A folder ID returns only the files directly in that folder, not in its subfolders. Requires upload_files.',
			array(
				'folder'   => array( 'type' => 'string', 'pattern' => '^(all|none|[0-9]+)$', 'default' => 'all', 'description' => 'all (default), none for Uncategorized files, or a folder ID.' ),
				'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50 ),
				'search'   => array( 'type' => 'string', 'maxLength' => 200, 'description' => 'Optional. Search in titles and filenames.' ),
				'type'     => array( 'type' => 'string', 'maxLength' => 100, 'description' => 'Optional MIME type or group, for example image or application/pdf.' ),
			),
			array(),
			self::meta( true, false, true )
		);
		self::add(
			'create-folder',
			'Create Folder',
			'Create a media folder, optionally inside another folder. New folders go to the bottom of their level. Requires upload_files.',
			array( 'name' => $name, 'parent' => $parent_opt ),
			array( 'name' ),
			self::meta( false, false, false )
		);
		self::add(
			'rename-folder',
			'Rename Folder',
			'Rename a media folder. Only the name changes; its files are not touched. Requires upload_files.',
			array( 'id' => $folder_id, 'name' => $name ),
			array( 'id', 'name' ),
			self::meta( false, false, true )
		);
		self::add(
			'move-folder',
			'Move Folder',
			'Move a folder to another parent (nesting) and optionally set its position. Pass order as the full list of folder IDs under the new parent in the wanted order. Without order, a folder that gets a new parent goes to the bottom of that level. A folder cannot move into itself or one of its own subfolders. Requires upload_files.',
			array(
				'id'     => $folder_id,
				'parent' => $parent_req,
				'order'  => array( 'type' => 'array', 'maxItems' => 1000, 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'description' => 'Optional. The full new order of the folder IDs under the new parent.' ),
			),
			array( 'id', 'parent' ),
			self::meta( false, false, true )
		);
		self::add(
			'delete-folder',
			'Delete Folder',
			'Delete a media folder. Its files are not deleted: they become Uncategorized. Its subfolders move up one level. Requires upload_files.',
			array( 'id' => $folder_id ),
			array( 'id' ),
			self::meta( false, true, false )
		);
		self::add(
			'assign-files',
			'Assign Files',
			'Put one or more files into a folder, or back to Uncategorized with folder 0. A file is in one folder at a time, so the new folder replaces the old one. Only changes the folder link, never the file itself. Up to 1000 files per call. Requires upload_files.',
			array(
				'ids'    => array( 'type' => 'array', 'minItems' => 1, 'maxItems' => 1000, 'items' => array( 'type' => 'integer', 'minimum' => 1 ), 'description' => 'Attachment IDs, as returned by list-files.' ),
				'folder' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Target folder ID. 0 (default) means Uncategorized.' ),
			),
			array( 'ids' ),
			self::meta( false, false, true )
		);
	}

	/** The folder helpers normally load only in the admin. Load them for MCP requests too. */
	private static function load() {
		if ( function_exists( 'rdb_mf_get_tree' ) ) {
			return new WP_Error( 'folderino_conflict', 'The old media folders snippet is still active. Turn it off so Folderino can take over.' );
		}
		require_once FOLDERINO_DIR . 'includes/functions.php';
		if ( ! taxonomy_exists( FOLDERINO_TAX ) ) {
			folderino_register_taxonomy();
		}
		return true;
	}

	private static function fail( $code, $message ) {
		return new WP_Error( 'folderino_' . $code, $message );
	}

	private static function done( $message, $extra = array() ) {
		return array_merge(
			array(
				'message' => $message,
				'tree'    => folderino_get_tree(),
			),
			$extra
		);
	}

	private static function folder( $id ) {
		$folder = folderino_get_folder( $id );
		return $folder ? $folder : self::fail( 'missing', 'This folder does not exist. Use list-folders for current IDs.' );
	}

	public static function list_folders( $input ) {
		$ready = self::load();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$tree = folderino_get_tree();
		if ( isset( $input['parent'] ) ) {
			$parent          = (int) $input['parent'];
			$tree['folders'] = array_values(
				array_filter(
					$tree['folders'],
					function ( $folder ) use ( $parent ) {
						return $folder['parent'] === $parent;
					}
				)
			);
		}
		return $tree;
	}

	public static function list_files( $input ) {
		$ready = self::load();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$folder = isset( $input['folder'] ) ? (string) $input['folder'] : 'all';
		$args   = array(
			'post_type'      => 'attachment',
			'post_status'    => array( 'inherit', 'private' ),
			'posts_per_page' => isset( $input['per_page'] ) ? min( 100, max( 1, (int) $input['per_page'] ) ) : 50,
			'paged'          => isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( 'none' === $folder ) {
			$args['tax_query'] = array( array( 'taxonomy' => FOLDERINO_TAX, 'operator' => 'NOT EXISTS' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		} elseif ( 'all' !== $folder ) {
			$found = self::folder( absint( $folder ) );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			$args['tax_query'] = array( array( 'taxonomy' => FOLDERINO_TAX, 'field' => 'term_id', 'terms' => array( (int) $found->term_id ), 'include_children' => false ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}
		if ( ! empty( $input['type'] ) ) {
			$args['post_mime_type'] = sanitize_text_field( (string) $input['type'] );
		}
		$query = new WP_Query( $args );
		$files = array();
		foreach ( $query->posts as $post ) {
			$terms   = get_the_terms( $post->ID, FOLDERINO_TAX );
			$files[] = array(
				'id'           => (int) $post->ID,
				'title'        => get_the_title( $post ),
				'filename'     => wp_basename( (string) get_attached_file( $post->ID ) ),
				'mime_type'    => (string) $post->post_mime_type,
				'url'          => (string) wp_get_attachment_url( $post->ID ),
				'uploaded_utc' => $post->post_date_gmt,
				'folder_id'    => ( is_array( $terms ) && $terms ) ? (int) reset( $terms )->term_id : 0,
			);
		}
		return array(
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $args['paged'],
			'files'       => $files,
		);
	}

	public static function create_folder( $input ) {
		$ready = self::load();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$name   = folderino_clean_name( wp_slash( (string) $input['name'] ) );
		$parent = isset( $input['parent'] ) ? absint( $input['parent'] ) : 0;
		if ( '' === $name ) {
			return self::fail( 'name', 'Please enter a folder name.' );
		}
		if ( $parent && ! folderino_get_folder( $parent ) ) {
			return self::fail( 'parent', 'The parent folder no longer exists.' );
		}
		if ( folderino_name_taken( $name, $parent ) ) {
			return self::fail( 'name_taken', 'A folder with this name already exists here.' );
		}
		$order  = folderino_next_order( $parent );
		$result = wp_insert_term( $name, FOLDERINO_TAX, array( 'parent' => $parent ) );
		if ( is_wp_error( $result ) ) {
			return self::fail( 'create', $result->get_error_message() );
		}
		update_term_meta( $result['term_id'], 'folderino_order', $order );
		return self::done( sprintf( 'Folder "%s" created.', $name ), array( 'id' => (int) $result['term_id'] ) );
	}

	public static function rename_folder( $input ) {
		$ready = self::load();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$folder = self::folder( absint( $input['id'] ) );
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}
		$name = folderino_clean_name( wp_slash( (string) $input['name'] ) );
		if ( '' === $name ) {
			return self::fail( 'name', 'Please enter a folder name.' );
		}
		if ( folderino_name_taken( $name, $folder->parent, $folder->term_id ) ) {
			return self::fail( 'name_taken', 'A folder with this name already exists here.' );
		}
		$result = wp_update_term( $folder->term_id, FOLDERINO_TAX, array( 'name' => $name ) );
		if ( is_wp_error( $result ) ) {
			return self::fail( 'rename', $result->get_error_message() );
		}
		return self::done( 'Folder renamed.' );
	}

	public static function move_folder( $input ) {
		$ready = self::load();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$folder = self::folder( absint( $input['id'] ) );
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}
		$parent = absint( $input['parent'] );
		$order  = isset( $input['order'] ) ? array_map( 'absint', (array) $input['order'] ) : array();
		if ( $parent ) {
			if ( ! folderino_get_folder( $parent ) ) {
				return self::fail( 'parent', 'The target folder no longer exists.' );
			}
			$descendants = get_term_children( $folder->term_id, FOLDERINO_TAX );
			$descendants = is_wp_error( $descendants ) ? array() : array_map( 'intval', $descendants );
			if ( $parent === (int) $folder->term_id || in_array( $parent, $descendants, true ) ) {
				return self::fail( 'into_itself', 'A folder cannot be moved into itself or one of its own subfolders.' );
			}
		}
		$new_parent   = (int) $folder->parent !== $parent;
		$append_order = ( $new_parent && ! $order ) ? folderino_next_order( $parent ) : null;
		if ( $new_parent ) {
			if ( folderino_name_taken( folderino_plain_name( $folder->name ), $parent, $folder->term_id ) ) {
				return self::fail( 'name_taken', 'A folder with this name already exists there.' );
			}
			$result = wp_update_term( $folder->term_id, FOLDERINO_TAX, array( 'parent' => $parent ) );
			if ( is_wp_error( $result ) ) {
				return self::fail( 'move', $result->get_error_message() );
			}
		}
		if ( $order ) {
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
		} elseif ( null !== $append_order ) {
			update_term_meta( $folder->term_id, 'folderino_order', $append_order );
		}
		return self::done( 'Folder moved.' );
	}

	public static function delete_folder( $input ) {
		$ready = self::load();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$folder = self::folder( absint( $input['id'] ) );
		if ( is_wp_error( $folder ) ) {
			return $folder;
		}
		// Same Rank Math notice cleanup as the sidebar: our folders have no URLs to redirect.
		$rank_math_notice = is_callable( array( 'RankMath\Helper', 'has_notification' ) )
			&& \RankMath\Helper::has_notification( 'auto_post_redirection' );
		$result = wp_delete_term( $folder->term_id, FOLDERINO_TAX );
		if ( ! $rank_math_notice && is_callable( array( 'RankMath\Helper', 'remove_notification' ) ) ) {
			\RankMath\Helper::remove_notification( 'auto_post_redirection' );
		}
		if ( is_wp_error( $result ) || ! $result ) {
			return self::fail( 'delete', 'The folder could not be deleted.' );
		}
		return self::done( 'Folder deleted. Its files are now Uncategorized.' );
	}

	public static function assign_files( $input ) {
		$ready = self::load();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$ids       = array_slice( array_unique( array_filter( array_map( 'absint', (array) $input['ids'] ) ) ), 0, 1000 );
		$folder_id = isset( $input['folder'] ) ? absint( $input['folder'] ) : 0;
		if ( ! $ids ) {
			return self::fail( 'no_files', 'No files selected.' );
		}
		$folder = null;
		if ( $folder_id ) {
			$folder = folderino_get_folder( $folder_id );
			if ( ! $folder ) {
				return self::fail( 'missing', 'This folder does not exist. Use list-folders for current IDs.' );
			}
		}
		$moved   = 0;
		$skipped = array();
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				$skipped[] = $id;
				continue;
			}
			// Integer term IDs on purpose: strings would be treated as term names.
			$terms  = $folder ? array( (int) $folder->term_id ) : array();
			$result = wp_set_object_terms( $id, $terms, FOLDERINO_TAX, false );
			if ( is_wp_error( $result ) ) {
				$skipped[] = $id;
			} else {
				$moved++;
			}
		}
		$target = $folder ? folderino_plain_name( $folder->name ) : 'Uncategorized';
		return self::done(
			sprintf( '%1$d %2$s moved to "%3$s".', $moved, 1 === $moved ? 'file' : 'files', $target ),
			array(
				'moved'   => $moved,
				'skipped' => $skipped,
			)
		);
	}
}

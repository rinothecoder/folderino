<?php
/**
 * Folderino. The page under Media > Folderino.
 */

defined( 'ABSPATH' ) || exit;

final class Folderino_Admin {
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FOLDERINO_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function menu() {
		add_submenu_page( 'upload.php', 'Folderino', 'Folderino', 'upload_files', 'folderino', array( __CLASS__, 'render' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'upload.php?page=folderino' ) ) . '">' . esc_html__( 'Open', 'folderino' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( 'media_page_folderino' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'folderino-admin', FOLDERINO_URL . 'assets/admin.css', array(), FOLDERINO_VERSION . '.' . filemtime( FOLDERINO_DIR . 'assets/admin.css' ) );
	}

	public static function render() {
		$tree    = function_exists( 'folderino_get_tree' ) ? folderino_get_tree() : array( 'folders' => array(), 'total' => 0, 'none' => 0 );
		$folders = count( $tree['folders'] );
		?>
		<div class="folderino-wrap">
			<header class="folderino-header">
				<a class="folderino-brand" href="<?php echo esc_url( admin_url( 'upload.php?page=folderino' ) ); ?>"><img class="folderino-brand__icon" src="<?php echo esc_url( FOLDERINO_URL . 'assets/folderino-icon.svg' ); ?>" width="32" height="32" alt="" aria-hidden="true"><h1>Folderino</h1></a>
				<span class="folderino-header-note"><?php esc_html_e( 'By Rino de Boer', 'folderino' ); ?></span>
			</header>
			<hr class="wp-header-end">
			<main class="folderino-content">
				<div class="folderino-page-heading">
					<div><h2><?php esc_html_e( 'Media folders', 'folderino' ); ?></h2><p><?php esc_html_e( 'Keep the Media Library tidy without moving a single file.', 'folderino' ); ?></p></div>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php esc_html_e( 'Open the Media Library', 'folderino' ); ?></a>
				</div>
				<section class="folderino-panel folderino-stats">
					<div><strong><?php echo esc_html( number_format_i18n( $folders ) ); ?></strong><span><?php esc_html_e( 'Folders', 'folderino' ); ?></span></div>
					<div><strong><?php echo esc_html( number_format_i18n( $tree['total'] ) ); ?></strong><span><?php esc_html_e( 'Files', 'folderino' ); ?></span></div>
					<div><strong><?php echo esc_html( number_format_i18n( $tree['none'] ) ); ?></strong><span><?php esc_html_e( 'Uncategorized', 'folderino' ); ?></span></div>
				</section>
				<section class="folderino-panel">
					<h2><?php esc_html_e( 'How it works', 'folderino' ); ?></h2>
					<p><?php esc_html_e( 'Folders are virtual. A file is only linked to a folder, so its URL and its place on disk never change. Switch Folderino off and the Media Library is simply flat again.', 'folderino' ); ?></p>
					<p class="folderino-muted"><?php esc_html_e( 'Drag files onto a folder to move them. Drag folders to reorder or nest them. Uploads go into the folder you have open.', 'folderino' ); ?></p>
				</section>
				<?php Folderino_Updates::screen(); ?>
			</main>
		</div>
		<?php
	}
}
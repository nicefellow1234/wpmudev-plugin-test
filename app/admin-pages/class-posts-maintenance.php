<?php
/**
 * Posts Maintenance admin UI.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\Admin_Pages;

defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WPMUDEV\PluginTest\App\Posts_Maintenance\Manager;

/**
 * Posts maintenance admin page.
 */
class Posts_Maintenance extends Base {

	/**
	 * Menu slug.
	 *
	 * @var string
	 */
	private $page_slug = 'wpmudev_posts_maintenance';

	/**
	 * Root DOM ID.
	 *
	 * @var string
	 */
	private $root_id = 'wpmudev_posts_maintenance_app';

	/**
	 * Assets metadata.
	 *
	 * @var array
	 */
	private $page_scripts = array();

	/**
	 * Init hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register submenu under the existing plugin menu.
	 */
	public function register_admin_page() {
		$parent_slug = defined( 'WPMUDEV_PLUGINTEST_MENU_SLUG' ) ? WPMUDEV_PLUGINTEST_MENU_SLUG : 'wpmudev_posts_maintenance';
		$page        = add_submenu_page(
			$parent_slug,
			__( 'Posts Maintenance', 'wpmudev-plugin-test' ),
			__( 'Posts Maintenance', 'wpmudev-plugin-test' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render' )
		);

		add_action( 'load-' . $page, array( $this, 'prepare_assets' ) );
	}

	/**
	 * Prepare localized data + dependencies.
	 */
	public function prepare_assets() {
		$asset_file    = WPMUDEV_PLUGINTEST_DIR . 'assets/js/postsmaintenance.min.asset.php';
		$script_data   = file_exists( $asset_file ) ? include $asset_file : array();
		$dependencies  = $script_data['dependencies'] ?? array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' );
		$version       = $script_data['version'] ?? WPMUDEV_PLUGINTEST_VERSION;
		$status        = Manager::instance()->get_status();
		$handle        = 'wpmudev_posts_maintenance_app';

		$this->page_scripts[ $handle ] = array(
			'src'       => WPMUDEV_PLUGINTEST_ASSETS_URL . '/js/postsmaintenance.min.js',
			'style_src' => WPMUDEV_PLUGINTEST_ASSETS_URL . '/css/postsmaintenance.min.css',
			'deps'      => $dependencies,
			'ver'       => $version,
			'strategy'  => true,
			'localize'  => array(
				'rootId'     => $this->root_id,
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'endpoints'  => array(
					'start'  => 'wpmudev/v1/posts-maintenance/start',
					'status' => 'wpmudev/v1/posts-maintenance/status',
					'cancel' => 'wpmudev/v1/posts-maintenance/cancel',
				),
				'status'     => $status,
			),
		);
	}

	/**
	 * Enqueue React bundle + styles.
	 */
	public function enqueue_assets() {
		foreach ( $this->page_scripts as $handle => $script ) {
			wp_register_script(
				$handle,
				$script['src'],
				$script['deps'],
				$script['ver'],
				$script['strategy']
			);

			if ( ! empty( $script['localize'] ) ) {
				wp_localize_script( $handle, 'wpmudevPostsMaintenance', $script['localize'] );
			}

			wp_enqueue_script( $handle );

			if ( ! empty( $script['style_src'] ) ) {
				wp_enqueue_style( $handle, $script['style_src'], array(), $script['ver'] );
			}
		}
	}

	/**
	 * Render page markup.
	 */
	public function render() {
		echo '<div id="' . esc_attr( $this->root_id ) . '" class="sui-wrap"></div>';
	}
}

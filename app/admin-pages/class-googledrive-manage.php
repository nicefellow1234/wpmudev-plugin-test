<?php
/**
 * Google Drive management page.
 *
 * @link          https://wpmudev.com/
 * @since         1.0.0
 *
 * @package       WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\Admin_Pages;

defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WPMUDEV\PluginTest\App\GoogleDrive\Credentials_Manager;
use WPMUDEV\PluginTest\Endpoints\V1\Drive_API;

class Google_Drive_Manage extends Base {
	/**
	 * The page title.
	 *
	 * @var string
	 */
	private $page_title;

	/**
	 * The page slug.
	 *
	 * @var string
	 */
	private $page_slug = 'wpmudev_plugintest_drive_manage';

	/**
	 * Page assets definition.
	 *
	 * @var array
	 */
	private $page_scripts = array();

	/**
	 * Assets version.
	 *
	 * @var string
	 */
	private $assets_version = '';

	/**
	 * Unique DOM element id for React mount.
	 *
	 * @var string
	 */
	private $unique_id = '';

	/**
	 * Initialize the page.
	 *
	 * @return void
	 */
	public function init() {
		$this->page_title     = __( 'Manage Google Drive', 'wpmudev-plugin-test' );
		$this->assets_version = ! empty( $this->script_data( 'version' ) ) ? $this->script_data( 'version' ) : WPMUDEV_PLUGINTEST_VERSION;
		$this->unique_id      = "wpmudev_plugintest_drive_manage_wrap-{$this->assets_version}";

		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'admin_body_classes' ) );
	}

	/**
	 * Register the submenu page when authenticated.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		if ( ! $this->is_authenticated() ) {
			return;
		}

		$parent_slug = defined( 'WPMUDEV_PLUGINTEST_MENU_SLUG' ) ? WPMUDEV_PLUGINTEST_MENU_SLUG : 'wpmudev_plugintest_drive';

		$page = add_submenu_page(
			$parent_slug,
			$this->page_title,
			__( 'Manage Google Drive', 'wpmudev-plugin-test' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'callback' )
		);

		if ( $page ) {
			add_action( 'load-' . $page, array( $this, 'prepare_assets' ) );
		}
	}

	/**
	 * Render callback.
	 *
	 * @return void
	 */
	public function callback() {
		$this->view();
	}

	/**
	 * Prepare assets for the React app.
	 *
	 * @return void
	 */
	public function prepare_assets() {
		if ( ! is_array( $this->page_scripts ) ) {
			$this->page_scripts = array();
		}

		$handle       = 'wpmudev_plugintest_drivepage';
		$src          = WPMUDEV_PLUGINTEST_ASSETS_URL . '/js/drivetestpage.min.js';
		$style_src    = WPMUDEV_PLUGINTEST_ASSETS_URL . '/css/drivetestpage.min.css';
		$dependencies = ! empty( $this->script_data( 'dependencies' ) )
			? $this->script_data( 'dependencies' )
			: array();

		$this->page_scripts[ $handle ] = array(
			'src'       => $src,
			'style_src' => $style_src,
			'deps'      => $dependencies,
			'ver'       => $this->assets_version,
			'strategy'  => true,
			'localize'  => array(
				'dom_element_id'       => $this->unique_id,
				'restEndpointSave'     => 'wpmudev/v1/drive/save-credentials',
				'restEndpointAuth'     => 'wpmudev/v1/drive/auth',
				'restEndpointFiles'    => 'wpmudev/v1/drive/files',
				'restEndpointUpload'   => 'wpmudev/v1/drive/upload',
				'restEndpointDownload' => 'wpmudev/v1/drive/download',
				'restEndpointCreate'   => 'wpmudev/v1/drive/create-folder',
				'restEndpointRename'   => 'wpmudev/v1/drive/rename',
				'restEndpointDelete'   => 'wpmudev/v1/drive/delete',
				'restEndpointRevoke'   => 'wpmudev/v1/drive/revoke',
				'nonce'                => wp_create_nonce( 'wp_rest' ),
				'authStatus'           => $this->is_authenticated(),
				'redirectUri'          => home_url( '/wp-json/wpmudev/v1/drive/callback' ),
				'hasCredentials'       => Credentials_Manager::has_credentials(),
				'maxUploadSize'        => wp_max_upload_size(),
				'scopes'               => Drive_API::get_scopes_list(),
				'viewMode'             => 'manage',
			),
		);
	}

	/**
	 * Enqueue assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( empty( $this->page_scripts ) ) {
			return;
		}

		foreach ( $this->page_scripts as $handle => $page_script ) {
			wp_register_script(
				$handle,
				$page_script['src'],
				$page_script['deps'],
				$page_script['ver'],
				$page_script['strategy']
			);

			if ( ! empty( $page_script['localize'] ) ) {
				wp_localize_script( $handle, 'wpmudevDriveTest', $page_script['localize'] );
			}

			wp_enqueue_script( $handle );

			if ( ! empty( $page_script['style_src'] ) ) {
				wp_enqueue_style( $handle, $page_script['style_src'], array(), $this->assets_version );
			}
		}
	}

	/**
	 * Output wrapper element.
	 *
	 * @return void
	 */
	protected function view() {
		echo '<div id="' . esc_attr( $this->unique_id ) . '" class="sui-wrap"></div>';
	}

	/**
	 * Add SUI classes when viewing this page.
	 *
	 * @param string $classes Existing classes.
	 *
	 * @return string
	 */
	public function admin_body_classes( $classes = '' ) {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return $classes;
		}

		$current_screen = get_current_screen();

		if ( empty( $current_screen->id ) || false === strpos( $current_screen->id, $this->page_slug ) ) {
			return $classes;
		}

		$classes .= ' sui-' . str_replace( '.', '-', WPMUDEV_PLUGINTEST_SUI_VERSION ) . ' ';

		return $classes;
	}

	/**
	 * Determine if the site currently holds a valid access token.
	 *
	 * @return bool
	 */
	private function is_authenticated(): bool {
		$access_token = get_option( 'wpmudev_drive_access_token', '' );
		$expires_at   = (int) get_option( 'wpmudev_drive_token_expires', 0 );

		return ! empty( $access_token ) && time() < $expires_at;
	}

	/**
	 * Helper to fetch script asset data.
	 *
	 * @param string $key Data key.
	 *
	 * @return mixed
	 */
	protected function script_data( string $key = '' ) {
		$raw_script_data = $this->raw_script_data();

		return ! empty( $key ) && ! empty( $raw_script_data[ $key ] ) ? $raw_script_data[ $key ] : '';
	}

	/**
	 * Load script metadata produced by Webpack.
	 *
	 * @return array
	 */
	protected function raw_script_data(): array {
		static $script_data = null;

		if ( is_null( $script_data ) && file_exists( WPMUDEV_PLUGINTEST_DIR . 'assets/js/drivetestpage.min.asset.php' ) ) {
			$script_data = include WPMUDEV_PLUGINTEST_DIR . 'assets/js/drivetestpage.min.asset.php';
		}

		return (array) $script_data;
	}
}

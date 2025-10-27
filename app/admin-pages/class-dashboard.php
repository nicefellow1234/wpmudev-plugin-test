<?php
/**
 * Main dashboard/settings page for the plugin.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\Admin_Pages;

defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;

/**
 * Dashboard page shown in WP admin menu.
 */
class Dashboard extends Base {

	/**
	 * Last known Google client compatibility.
	 *
	 * @var bool
	 */
	private $google_ready = true;

	/**
	 * Optional error/explanation from the dependency guard.
	 *
	 * @var string
	 */
	private $google_error = '';

	/**
	 * Hook dashboard menu.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 5 );
	}

	/**
	 * Registers the top-level menu and dashboard page.
	 */
	public function register_menu() {
		add_menu_page(
			__( 'WPMU DEV Plugin Test', 'wpmudev-plugin-test' ),
			__( 'WPMU DEV Plugin Test', 'wpmudev-plugin-test' ),
			'edit_posts',
			WPMUDEV_PLUGINTEST_MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-cloud',
			3
		);

		add_submenu_page(
			WPMUDEV_PLUGINTEST_MENU_SLUG,
			__( 'Overview', 'wpmudev-plugin-test' ),
			__( 'Overview', 'wpmudev-plugin-test' ),
			'edit_posts',
			WPMUDEV_PLUGINTEST_MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Pass dependency status down to the dashboard.
	 *
	 * @param bool   $google_ready Whether Google features are enabled.
	 * @param string $google_error Optional error message.
	 */
	public function set_google_status( $google_ready, $google_error = '' ) {
		$this->google_ready = (bool) $google_ready;
		$this->google_error = (string) $google_error;
	}

	/**
	 * Dashboard page markup.
	 */
	public function render() {
		$drive_url        = admin_url( 'admin.php?page=wpmudev_plugintest_drive' );
		$posts_url        = admin_url( 'admin.php?page=wpmudev_posts_maintenance' );
		$docs_url         = 'https://console.cloud.google.com/apis/credentials';
		$cli_example      = esc_html( 'wp wpmudev posts-maintenance scan --post_types=post,page' );
		?>
		<div class="wrap wpmudev-dashboard">
			<h1><?php esc_html_e( 'WPMU DEV Plugin Test', 'wpmudev-plugin-test' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Quick access to the plugin settings, maintenance routines, and Google Drive integration.', 'wpmudev-plugin-test' ); ?>
			</p>

			<?php if ( $this->google_ready && $this->google_error ) : ?>
				<div class="notice notice-warning">
					<p><?php echo esc_html( $this->google_error ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! $this->google_ready ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						echo esc_html(
							$this->google_error ?
								$this->google_error :
								__( 'Google Drive features are temporarily disabled because another plugin loaded an incompatible google/apiclient version. Update the conflicting plugin or remove its bundled Google Client library to re-enable Drive tools.', 'wpmudev-plugin-test' )
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="card-grid">
				<div class="card">
					<h2><?php esc_html_e( 'Google Drive Settings', 'wpmudev-plugin-test' ); ?></h2>
					<p>
						<?php
						printf(
							/* translators: %s - Google Console URL */
							esc_html__( 'Generate OAuth credentials inside the %s, paste them into the plugin, and authenticate to unlock Drive uploads and file browsing.', 'wpmudev-plugin-test' ),
							'<a href="' . esc_url( $docs_url ) . '" target="_blank" rel="noreferrer">' . esc_html__( 'Google Cloud Console', 'wpmudev-plugin-test' ) . '</a>'
						);
						?>
					</p>
					<?php if ( $this->google_ready ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $drive_url ); ?>">
							<?php esc_html_e( 'Open Google Drive Settings', 'wpmudev-plugin-test' ); ?>
						</a>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'Resolve the dependency notice above to re-enable the Google Drive screen.', 'wpmudev-plugin-test' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<div class="card">
					<h2><?php esc_html_e( 'Posts Maintenance', 'wpmudev-plugin-test' ); ?></h2>
					<p><?php esc_html_e( 'Scan all public posts/pages, refresh the last scan meta, and run the same task via WP-Cron or WP-CLI.', 'wpmudev-plugin-test' ); ?></p>
					<a class="button" href="<?php echo esc_url( $posts_url ); ?>">
						<?php esc_html_e( 'Launch Maintenance UI', 'wpmudev-plugin-test' ); ?>
					</a>
					<p class="description cli">
						<?php esc_html_e( 'CLI example:', 'wpmudev-plugin-test' ); ?><br />
						<code><?php echo $cli_example; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></code>
					</p>
				</div>
			</div>
		</div>
		<style>
			.wpmudev-dashboard .card-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
				gap: 20px;
				margin-top: 20px;
			}

			.wpmudev-dashboard .card {
				padding: 20px;
				background: #fff;
				border: 1px solid #dfe1e5;
				box-shadow: 0 3px 12px rgba(30, 41, 59, 0.08);
			}

			.wpmudev-dashboard .card h2 {
				margin-top: 0;
			}

			.wpmudev-dashboard .card .button {
				margin-top: 10px;
			}

			.wpmudev-dashboard .card .cli code {
				display: inline-block;
				margin-top: 6px;
			}
		</style>
		<?php
	}
}

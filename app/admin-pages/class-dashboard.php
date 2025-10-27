<?php
/**
 * Main dashboard/settings page for the plugin.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\App\Admin_Pages;

defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WPMUDEV\PluginTest\Dependency_Guard;

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
		add_action( 'admin_post_wpmudev_plugintest_force_google', array( $this, 'handle_force_google_request' ) );
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
		$manage_url       = admin_url( 'admin.php?page=wpmudev_plugintest_drive_manage' );
		$posts_url        = admin_url( 'admin.php?page=wpmudev_posts_maintenance' );
		$docs_url         = 'https://console.cloud.google.com/apis/credentials';
		$force_override   = (bool) get_option( 'wpmudev_plugin_test_force_google_client', false );
		$forced_active    = $force_override || Dependency_Guard::is_google_client_forced();
		$cli_example      = esc_html( 'wp wpmudev posts-maintenance scan --post_types=post,page' );
		$drive_token      = get_option( 'wpmudev_drive_access_token', '' );
		$drive_expires    = (int) get_option( 'wpmudev_drive_token_expires', 0 );
		$has_drive_auth   = ! empty( $drive_token ) && time() < $drive_expires;
		$force_updated    = isset( $_GET['force-updated'] ) ? sanitize_text_field( wp_unslash( $_GET['force-updated'] ) ) : '';
		?>
		<div class="shadcn-admin dashboard-admin">
			<div class="dashboard-shell">
				<?php if ( '' !== $force_updated ) : ?>
					<div class="dashboard-notice dashboard-notice--<?php echo '1' === $force_updated ? 'success' : 'neutral'; ?>">
						<p>
							<?php
							echo '1' === $force_updated
								? esc_html__( 'Google Drive compatibility override enabled. Proceed with caution.', 'wpmudev-plugin-test' )
								: esc_html__( 'Google Drive compatibility override disabled. Compatibility checks are active again.', 'wpmudev-plugin-test' );
							?>
						</p>
					</div>
				<?php endif; ?>

				<section class="dashboard-hero">
					<span class="dashboard-hero__badge"><?php esc_html_e( 'Overview', 'wpmudev-plugin-test' ); ?></span>
					<h1><?php esc_html_e( 'WPMU DEV Plugin Test', 'wpmudev-plugin-test' ); ?></h1>
					<p><?php esc_html_e( 'Quick access to plugin tools, Google Drive integration, and your background automation workflow.', 'wpmudev-plugin-test' ); ?></p>
				</section>

				<?php if ( $this->google_ready && $this->google_error ) : ?>
					<div class="dashboard-notice dashboard-notice--warning">
						<p><?php echo esc_html( $this->google_error ); ?></p>
					</div>
				<?php endif; ?>

				<?php if ( ! $this->google_ready ) : ?>
					<div class="dashboard-notice dashboard-notice--error">
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

				<div class="dashboard-grid">
					<article class="dashboard-card">
						<div class="dashboard-card__header">
							<h2><?php esc_html_e( 'Google Drive Settings', 'wpmudev-plugin-test' ); ?></h2>
							<span class="dashboard-chip <?php echo $this->google_ready ? 'dashboard-chip--success' : 'dashboard-chip--warning'; ?>">
								<?php echo $this->google_ready ? esc_html__( 'Connected', 'wpmudev-plugin-test' ) : esc_html__( 'Action needed', 'wpmudev-plugin-test' ); ?>
							</span>
						</div>
						<p class="dashboard-card__description">
							<?php
							printf(
								/* translators: %s - Google Console URL */
								esc_html__( 'Generate OAuth credentials inside the %s, paste them here, and authenticate to unlock Drive uploads and file browsing.', 'wpmudev-plugin-test' ),
								'<a href="' . esc_url( $docs_url ) . '" target="_blank" rel="noreferrer">' . esc_html__( 'Google Cloud Console', 'wpmudev-plugin-test' ) . '</a>'
							);
							?>
						</p>
						<?php if ( $this->google_ready ) : ?>
							<a class="dashboard-button dashboard-button--primary" href="<?php echo esc_url( $drive_url ); ?>">
								<?php esc_html_e( 'Open Google Drive Settings', 'wpmudev-plugin-test' ); ?>
							</a>
						<?php else : ?>
							<a class="dashboard-button dashboard-button--outline" href="<?php echo esc_url( $drive_url ); ?>">
								<?php esc_html_e( 'Review Google Drive Setup', 'wpmudev-plugin-test' ); ?>
							</a>
							<p class="dashboard-card__helper">
								<?php esc_html_e( 'Resolve the dependency notice above to re-enable the Google Drive screen.', 'wpmudev-plugin-test' ); ?>
							</p>
						<?php endif; ?>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dashboard-force-toggle">
							<input type="hidden" name="action" value="wpmudev_plugintest_force_google" />
							<?php wp_nonce_field( 'wpmudev_plugintest_force_google', 'wpmudev_plugintest_force_google_nonce' ); ?>

							<label class="dashboard-force-toggle__label">
								<input type="checkbox" name="force_google" value="1" <?php checked( $force_override ); ?> />
								<span>
									<?php esc_html_e( 'Force-enable Google Drive even if another plugin bundles an older google/apiclient.', 'wpmudev-plugin-test' ); ?>
								</span>
							</label>

							<p class="dashboard-force-toggle__helper">
								<?php
								echo esc_html__(
									'Only use this override when you are aware of the risks. Leave it disabled to rely on automatic compatibility checks.',
									'wpmudev-plugin-test'
								);
								?>
							</p>

							<?php if ( $forced_active ) : ?>
								<p class="dashboard-force-toggle__status dashboard-force-toggle__status--warning">
									<?php
									echo esc_html__(
										'Override is currently active. Google Drive tooling will load even if another plugin ships an outdated client.',
										'wpmudev-plugin-test'
									);
									?>
								</p>
							<?php endif; ?>

							<button type="submit" class="dashboard-button dashboard-button--ghost">
								<?php esc_html_e( 'Save override preference', 'wpmudev-plugin-test' ); ?>
							</button>
						</form>
					</article>

					<article class="dashboard-card">
						<div class="dashboard-card__header">
							<h2><?php esc_html_e( 'Posts Maintenance', 'wpmudev-plugin-test' ); ?></h2>
							<span class="dashboard-chip dashboard-chip--neutral"><?php esc_html_e( 'Automation', 'wpmudev-plugin-test' ); ?></span>
						</div>
						<p class="dashboard-card__description">
							<?php esc_html_e( 'Scan all public posts/pages, refresh the last scan meta, and trigger the same job via WP-Cron or WP-CLI.', 'wpmudev-plugin-test' ); ?>
						</p>
						<a class="dashboard-button dashboard-button--secondary" href="<?php echo esc_url( $posts_url ); ?>">
							<?php esc_html_e( 'Open Posts Maintenance', 'wpmudev-plugin-test' ); ?>
						</a>
				<div class="dashboard-cli">
					<span><?php esc_html_e( 'CLI example', 'wpmudev-plugin-test' ); ?></span>
					<code><?php echo $cli_example; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></code>
				</div>
			</article>

					<article class="dashboard-card">
						<div class="dashboard-card__header">
							<h2><?php esc_html_e( 'Manage Google Drive', 'wpmudev-plugin-test' ); ?></h2>
							<span class="dashboard-chip <?php echo $has_drive_auth ? 'dashboard-chip--success' : 'dashboard-chip--warning'; ?>">
								<?php echo $has_drive_auth ? esc_html__( 'Available', 'wpmudev-plugin-test' ) : esc_html__( 'Authorize first', 'wpmudev-plugin-test' ); ?>
							</span>
						</div>
						<p class="dashboard-card__description">
							<?php esc_html_e( 'Upload files, browse folders, rename items, and manage Drive content directly from WordPress once you authenticate.', 'wpmudev-plugin-test' ); ?>
						</p>
						<?php if ( $has_drive_auth && $this->google_ready ) : ?>
							<a class="dashboard-button dashboard-button--secondary" href="<?php echo esc_url( $manage_url ); ?>">
								<?php esc_html_e( 'Open Manage Drive', 'wpmudev-plugin-test' ); ?>
							</a>
						<?php else : ?>
							<a class="dashboard-button dashboard-button--outline" href="<?php echo esc_url( $drive_url ); ?>">
								<?php esc_html_e( 'Connect Google Drive', 'wpmudev-plugin-test' ); ?>
							</a>
							<p class="dashboard-card__helper">
								<?php esc_html_e( 'Authenticate your Google account to access the Manage Drive workspace.', 'wpmudev-plugin-test' ); ?>
							</p>
						<?php endif; ?>
					</article>
				</div>
			</div>
		</div>
		<style>
			.dashboard-admin {
				background: var(--shadcn-background, #f8fafc);
			}

			.dashboard-shell {
				max-width: 960px;
				margin: 0 auto;
				padding: 32px 24px 64px;
				display: flex;
				flex-direction: column;
				gap: 24px;
			}

			.dashboard-hero {
				padding: 28px 32px;
				border-radius: 26px;
				background: linear-gradient(135deg, rgba(59, 130, 246, 0.14), rgba(14, 116, 144, 0.08));
				border: 1px solid rgba(148, 163, 184, 0.22);
				box-shadow: 0 24px 60px rgba(15, 23, 42, 0.12);
				display: flex;
				flex-direction: column;
				gap: 12px;
			}

			.dashboard-hero__badge {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				border-radius: 999px;
				padding: 6px 14px;
				font-size: 12px;
				font-weight: 600;
				letter-spacing: 0.08em;
				text-transform: uppercase;
				color: #1d4ed8;
				background: rgba(59, 130, 246, 0.14);
			}

			.dashboard-hero h1 {
				margin: 0;
				font-size: clamp(28px, 4vw, 36px);
				color: #0f172a;
			}

			.dashboard-hero p {
				margin: 0;
				font-size: 16px;
				color: #475569;
			}

			.dashboard-notice {
				border-radius: 18px;
				padding: 16px 20px;
				border: 1px solid rgba(148, 163, 184, 0.24);
				box-shadow: 0 18px 40px rgba(148, 163, 184, 0.18);
			}

			.dashboard-notice--warning {
				background: rgba(253, 230, 138, 0.25);
				border-color: rgba(234, 179, 8, 0.4);
			}

			.dashboard-notice--error {
				background: rgba(254, 202, 202, 0.3);
				border-color: rgba(239, 68, 68, 0.4);
			}

			.dashboard-notice--success {
				background: rgba(167, 243, 208, 0.25);
				border-color: rgba(16, 185, 129, 0.38);
			}

			.dashboard-notice--neutral {
				background: rgba(226, 232, 240, 0.4);
				border-color: rgba(148, 163, 184, 0.32);
			}

			.dashboard-grid {
				display: flex;
				flex-direction: column;
				gap: 20px;
			}

			.dashboard-card {
				padding: 24px 26px;
				border-radius: 24px;
				border: 1px solid rgba(148, 163, 184, 0.24);
				background: rgba(255, 255, 255, 0.95);
				box-shadow: 0 20px 50px rgba(15, 23, 42, 0.12);
				display: flex;
				flex-direction: column;
				gap: 16px;
			}

			.dashboard-card__header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				gap: 12px;
			}

			.dashboard-card__header h2 {
				margin: 0;
				font-size: 20px;
				color: #0f172a;
			}

			.dashboard-card__description {
				margin: 0;
				font-size: 15px;
				color: #475569;
				line-height: 1.55;
			}

			.dashboard-card__helper {
				margin: 0;
				font-size: 13px;
				color: #64748b;
			}

			.dashboard-button {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				border-radius: 999px;
				padding: 10px 20px;
				font-size: 14px;
				font-weight: 600;
				border: 1px solid transparent;
				text-decoration: none;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
			}

			.dashboard-button--primary {
				background: linear-gradient(135deg, #2563eb, #1d4ed8);
				color: #f8fafc;
				box-shadow: 0 18px 40px rgba(37, 99, 235, 0.28);
			}

			.dashboard-button--primary:hover,
			.dashboard-button--primary:focus {
				color: #f8fafc;
				box-shadow: 0 22px 48px rgba(37, 99, 235, 0.32);
			}

			.dashboard-button--secondary {
				background: rgba(37, 99, 235, 0.12);
				color: #1d4ed8;
				border-color: rgba(37, 99, 235, 0.26);
			}

			.dashboard-button--outline {
				background: rgba(248, 250, 252, 0.85);
				color: #0f172a;
				border-color: rgba(148, 163, 184, 0.32);
			}

			.dashboard-button--ghost {
				background: transparent;
				color: #1d4ed8;
				border-color: rgba(37, 99, 235, 0.3);
			}

			.dashboard-button:hover {
				transform: translateY(-1px);
				box-shadow: 0 20px 40px rgba(37, 99, 235, 0.22);
			}

			.dashboard-chip {
				display: inline-flex;
				align-items: center;
				border-radius: 999px;
				padding: 4px 12px;
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.08em;
			}

			.dashboard-chip--success {
				color: #15803d;
				background: rgba(34, 197, 94, 0.18);
			}

			.dashboard-chip--warning {
				color: #b45309;
				background: rgba(251, 191, 36, 0.2);
			}

			.dashboard-chip--neutral {
				color: #1e3a8a;
				background: rgba(191, 219, 254, 0.3);
			}

			.dashboard-cli {
				display: flex;
				flex-direction: column;
				gap: 8px;
				font-size: 13px;
				color: #64748b;
			}

			.dashboard-cli code {
				background: rgba(15, 23, 42, 0.92);
				color: #f8fafc;
				padding: 10px 14px;
				border-radius: 12px;
				display: inline-block;
				font-size: 12px;
			}

			.dashboard-force-toggle {
				display: flex;
				flex-direction: column;
				gap: 12px;
				padding: 16px 18px;
				border-radius: 18px;
				border: 1px dashed rgba(148, 163, 184, 0.32);
				background: rgba(248, 250, 252, 0.72);
			}

			.dashboard-force-toggle__label {
				display: flex;
				align-items: flex-start;
				gap: 10px;
				font-size: 13px;
				font-weight: 600;
				color: #1f2937;
			}

			.dashboard-force-toggle__label input[type="checkbox"] {
				margin-top: 3px;
			}

			.dashboard-force-toggle__helper {
				margin: 0;
				font-size: 12px;
				color: #64748b;
			}

			.dashboard-force-toggle__status {
				margin: 0;
				font-size: 12px;
				font-weight: 600;
			}

			.dashboard-force-toggle__status--warning {
				color: #b45309;
			}

			@media (max-width: 782px) {
				.dashboard-shell {
					padding-inline: 18px;
				}

				.dashboard-card__header {
					align-items: flex-start;
					flex-direction: column;
				}
			}
		</style>
		<?php
	}

	/**
	 * Handle compatibility override toggle submission.
	 */
	public function handle_force_google_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this setting.', 'wpmudev-plugin-test' ) );
		}

		check_admin_referer( 'wpmudev_plugintest_force_google', 'wpmudev_plugintest_force_google_nonce' );

		$force_enabled = isset( $_POST['force_google'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['force_google'] ) );

		if ( $force_enabled ) {
			update_option( 'wpmudev_plugin_test_force_google_client', 1, false );
		} else {
			delete_option( 'wpmudev_plugin_test_force_google_client' );
		}

		$redirect = admin_url( 'admin.php?page=' . WPMUDEV_PLUGINTEST_MENU_SLUG );
		$redirect = add_query_arg( 'force-updated', $force_enabled ? '1' : '0', $redirect );

		wp_safe_redirect( $redirect );
		exit;
	}
}

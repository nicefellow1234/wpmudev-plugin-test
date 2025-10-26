<?php
/**
 * Simple dependency guard to avoid runtime conflicts.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest;

defined( 'WPINC' ) || die;

/**
 * Guards well-known dependencies.
 */
class Dependency_Guard {

	/**
	 * Minimum Google Client version we rely on.
	 */
	const GOOGLE_CLIENT_MIN_VERSION = '2.15.0';

	/**
	 * Last error message captured while evaluating the Google client.
	 *
	 * @var string
	 */
	private static $google_client_error = '';

	/**
	 * Whether Google Client can be safely used.
	 *
	 * @return bool
	 */
	public static function google_client_supported(): bool {
		self::$google_client_error = '';

		if ( ! class_exists( '\Google_Client', false ) ) {
			return true;
		}

		$detected_version = defined( '\Google_Client::LIBVER' ) ? \Google_Client::LIBVER : null;

		if ( $detected_version && version_compare( $detected_version, self::GOOGLE_CLIENT_MIN_VERSION, '>=' ) ) {
			return true;
		}

		self::$google_client_error = sprintf(
			/* translators: %s - minimum version */
			__( 'Another plugin loaded google/apiclient %1$s or lower. Please upgrade it to %2$s or newer to re-enable Google Drive features.', 'wpmudev-plugin-test' ),
			$detected_version ?: __( 'an unknown version', 'wpmudev-plugin-test' ),
			self::GOOGLE_CLIENT_MIN_VERSION
		);

		add_action( 'admin_notices', array( __CLASS__, 'render_google_notice' ) );

		return false;
	}

	/**
	 * Returns the last Google client compatibility error (if any).
	 *
	 * @return string
	 */
	public static function get_google_client_error(): string {
		return self::$google_client_error;
	}

	/**
	 * Display admin notice about conflicting Google Client versions.
	 */
	public static function render_google_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<?php
				printf(
					/* translators: %s - minimum Google Client version */
					esc_html__( 'WPMU DEV Plugin Test detected another plugin loading an outdated Google API Client library. Google Drive features were paused to avoid conflicts. Please ensure you are running google/apiclient version %s or newer on this site.', 'wpmudev-plugin-test' ),
					esc_html( self::GOOGLE_CLIENT_MIN_VERSION )
				);
				?>
			</p>
		</div>
		<?php
	}
}

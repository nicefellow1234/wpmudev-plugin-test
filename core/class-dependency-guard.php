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
	 * Whether Google support is forced even if a legacy client is detected.
	 *
	 * @var bool
	 */
	private static $google_client_forced = false;

	/**
	 * Cached version string detected from an externally loaded Google Client.
	 *
	 * @var string|null
	 */
	private static $google_client_detected_version = null;

	/**
	 * Whether Google Client can be safely used.
	 *
	 * @return bool
	 */
	public static function google_client_supported(): bool {
		self::$google_client_error = '';
		self::$google_client_forced = false;
		self::$google_client_detected_version = null;

		if ( ! class_exists( '\Google_Client', false ) ) {
			return true;
		}

		$detected_version  = defined( '\Google_Client::LIBVER' ) ? \Google_Client::LIBVER : null;
		$detected_readable = $detected_version ?: __( 'an unknown version', 'wpmudev-plugin-test' );
		self::$google_client_detected_version = $detected_readable;

		if ( $detected_version && version_compare( $detected_version, self::GOOGLE_CLIENT_MIN_VERSION, '>=' ) ) {
			return true;
		}

		/**
		 * Filters whether to force-enable Google Drive tools when an outdated
		 * google/apiclient build is detected.
		 *
		 * Returning true keeps the features enabled (default) while still
		 * displaying a warning to administrators. Returning false restores the
		 * previous behaviour which pauses Google Drive functionality.
		 *
		 * @since 1.0.0
		 *
		 * @param bool        $force_enable    Whether to keep Google Drive enabled.
		 * @param string|null $detected_version Detected google/apiclient version.
		 * @param string      $minimum_version  Recommended minimum version.
		 */
		$force_enable = apply_filters(
			'wpmudev_plugin_test/google_client_force_enable',
			true,
			$detected_version,
			self::GOOGLE_CLIENT_MIN_VERSION
		);

		if ( $force_enable ) {
			self::$google_client_forced = true;
			self::$google_client_error  = '';

			return true;
		}

		self::$google_client_error = '';

		return true;
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
		// Deprecated no-op; notices no longer shown when forcing support.
	}
}

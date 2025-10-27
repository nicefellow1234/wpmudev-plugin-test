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

		if ( ! class_exists( '\Google_Client' ) ) {
			return true;
		}

		$detected_version = defined( '\Google_Client::LIBVER' ) ? (string) \Google_Client::LIBVER : null;
		self::$google_client_detected_version = $detected_version ?: __( 'an unknown version', 'wpmudev-plugin-test' );

		if ( $detected_version && version_compare( $detected_version, self::GOOGLE_CLIENT_MIN_VERSION, '>=' ) ) {
			return true;
		}

		$force_flag = (
			( defined( 'WPMUDEV_PLUGINTEST_FORCE_GOOGLE_CLIENT' ) && WPMUDEV_PLUGINTEST_FORCE_GOOGLE_CLIENT )
			|| (bool) get_option( 'wpmudev_plugin_test_force_google_client', false )
		);

		$force_enable = apply_filters(
			'wpmudev_plugin_test/google_client_force_enable',
			$force_flag,
			$detected_version,
			self::GOOGLE_CLIENT_MIN_VERSION
		);

		if ( $force_enable ) {
			self::$google_client_forced = true;
			self::$google_client_error  = sprintf(
				/* translators: %s: Detected google/apiclient version. */
				__( 'Google Drive support forced on while another plugin provides google/apiclient %s. Double-check for compatibility issues.', 'wpmudev-plugin-test' ),
				self::$google_client_detected_version
			);

			return true;
		}

		self::$google_client_error = sprintf(
			/* translators: 1: Detected google/apiclient version, 2: Minimum supported version. */
			__( 'Google Drive features are disabled because google/apiclient %1$s is loaded (requires %2$s or newer).', 'wpmudev-plugin-test' ),
			self::$google_client_detected_version,
			self::GOOGLE_CLIENT_MIN_VERSION
		);

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
	 * Whether support was forced when an outdated Google Client was detected.
	 */
	public static function is_google_client_forced(): bool {
		return self::$google_client_forced;
	}
}

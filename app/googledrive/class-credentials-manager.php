<?php
/**
 * Helper responsible for persisting Google credentials securely.
 *
 * @package WPMUDEV\PluginTest\App\GoogleDrive
 */

namespace WPMUDEV\PluginTest\App\GoogleDrive;

defined( 'WPINC' ) || die;

/**
 * Credential manager utility.
 */
final class Credentials_Manager {

	/**
	 * Option name that stores the credentials.
	 */
	const OPTION_NAME = 'wpmudev_plugin_tests_auth';

	/**
	 * Cipher used for encrypting credentials at rest.
	 */
	const ENCRYPTION_METHOD = 'aes-256-cbc';

	/**
	 * Save sanitized credentials.
	 *
	 * @param string $client_id Google OAuth client ID.
	 * @param string $client_secret Google OAuth client secret.
	 *
	 * @return bool
	 */
	public static function save( string $client_id, string $client_secret ): bool {
		$payload = array(
			'client_id'     => self::maybe_encrypt_value( $client_id ),
			'client_secret' => self::maybe_encrypt_value( $client_secret ),
			'encrypted'     => self::supports_encryption(),
			'updated_at'    => time(),
		);

		return update_option( self::OPTION_NAME, $payload, false );
	}

	/**
	 * Return decrypted credentials or an empty array.
	 *
	 * @return array
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( empty( $stored['client_id'] ) || empty( $stored['client_secret'] ) ) {
			return array();
		}

		$use_encryption = ! empty( $stored['encrypted'] ) && self::supports_encryption();

		$client_id     = $use_encryption ? self::decrypt_value( $stored['client_id'] ) : $stored['client_id'];
		$client_secret = $use_encryption ? self::decrypt_value( $stored['client_secret'] ) : $stored['client_secret'];

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return array();
		}

		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
	}

	/**
	 * Whether credentials exist.
	 *
	 * @return bool
	 */
	public static function has_credentials(): bool {
		$creds = self::get();

		return ! empty( $creds['client_id'] ) && ! empty( $creds['client_secret'] );
	}

	/**
	 * Encrypt value when supported, otherwise store plaintext.
	 *
	 * @param string $value Value to encrypt.
	 *
	 * @return string
	 */
	private static function maybe_encrypt_value( string $value ): string {
		if ( ! self::supports_encryption() ) {
			return $value;
		}

		$iv_length = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
		$iv        = self::random_bytes( $iv_length );

		$payload = openssl_encrypt(
			$value,
			self::ENCRYPTION_METHOD,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false === $payload ) {
			return $value;
		}

		return base64_encode( $iv . $payload );
	}

	/**
	 * Decrypt stored value.
	 *
	 * @param string $value Encrypted value.
	 *
	 * @return string
	 */
	private static function decrypt_value( string $value ): string {
		$decoded = base64_decode( $value, true );
		if ( false === $decoded ) {
			return '';
		}

		$iv_length = openssl_cipher_iv_length( self::ENCRYPTION_METHOD );
		if ( strlen( $decoded ) <= $iv_length ) {
			return '';
		}

		$iv       = substr( $decoded, 0, $iv_length );
		$cipher   = substr( $decoded, $iv_length );
		$decrypted = openssl_decrypt(
			$cipher,
			self::ENCRYPTION_METHOD,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv
		);

		return false === $decrypted ? '' : $decrypted;
	}

	/**
	 * Whether OpenSSL is available.
	 *
	 * @return bool
	 */
	private static function supports_encryption(): bool {
		return function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& function_exists( 'openssl_cipher_iv_length' );
	}

	/**
	 * Build encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function get_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}

	/**
	 * Generate cryptographically secure bytes.
	 *
	 * @param int $length Byte length.
	 *
	 * @return string
	 */
	private static function random_bytes( int $length ): string {
		if ( function_exists( 'random_bytes' ) ) {
			return random_bytes( $length );
		}

		return openssl_random_pseudo_bytes( $length );
	}
}

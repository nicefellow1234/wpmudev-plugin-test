<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Wpmudev_Plugin_Test
 */

$plugin_root = dirname( __DIR__ );
$autoloader  = $plugin_root . '/vendor/autoload.php';

if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$polyfills_autoload = $plugin_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

	if ( file_exists( $polyfills_autoload ) ) {
		define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_autoload );
	}
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

tests_add_filter(
	'rest_api_init',
	static function () {
		register_rest_route(
			'wpmudev/v1/auth',
			'/auth-url',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => '__return_true',
					'callback'            => static function () {
						return new \WP_Error(
							'missing_credentials',
							__( 'Google OAuth credentials are not configured yet.', 'wpmudev-plugin-test' ),
							array( 'status' => 400 )
						);
					},
				),
			)
		);
	},
	20
);

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/wpmudev-plugin-test.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

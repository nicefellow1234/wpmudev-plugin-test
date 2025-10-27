<?php
/**
 * WP-CLI integration for posts maintenance.
 */

namespace WPMUDEV\PluginTest\App\CLI;

use WPMUDEV\PluginTest\App\Posts_Maintenance\Manager;
use WP_CLI;
use WP_CLI_Command;

class Posts_Maintenance_Command extends WP_CLI_Command {
	/**
	 * Run the posts maintenance scan synchronously.
	 *
	 * ## OPTIONS
	 *
	 * [--post_types=<post_types>]
	 * : Comma separated list of public post types to include (e.g. post,page,product).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpmudev posts-maintenance scan
	 *     wp wpmudev posts-maintenance scan --post_types=post,page,product
	 */
	public function scan( $args, $assoc_args ) {
		$post_types = array();
		if ( ! empty( $assoc_args['post_types'] ) ) {
			$post_types = array_map( 'sanitize_key', explode( ',', $assoc_args['post_types'] ) );
		}

		$manager = Manager::instance();

		WP_CLI::log( 'Starting posts maintenance scan...' );

		$processed = $manager->run_manual_scan( $post_types, function ( $current, $total ) {
			WP_CLI::log( sprintf( 'Processed %1$d of %2$d items...', $current, $total ) );
		} );

		WP_CLI::success( sprintf( 'Scan finished. %d posts updated.', $processed ) );
	}
}

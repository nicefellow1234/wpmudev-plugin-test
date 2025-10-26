<?php
/**
 * Posts maintenance REST endpoints.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\Endpoints\V1;

defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WPMUDEV\PluginTest\App\Posts_Maintenance\Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for posts maintenance.
 */
class Posts_Maintenance_API extends Base {

	/**
	 * Register routes.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Routes registration.
	 */
	public function register_routes() {
		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/start',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'check_permissions' ),
					'callback'            => array( $this, 'start_scan' ),
					'args'                => array(
						'post_types' => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'check_permissions' ),
					'callback'            => array( $this, 'get_status' ),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'check_permissions' ),
					'callback'            => array( $this, 'save_settings' ),
					'args'                => array(
						'post_types'   => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
						'cron_enabled' => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'cron_time'    => array(
							'type'    => 'string',
							'default' => '00:00',
						),
						'cron_times'   => array(
							'type'    => 'array',
							'items'   => array( 'type' => 'string' ),
							'default' => array(),
						),
					),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/cancel',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'check_permissions' ),
					'callback'            => array( $this, 'cancel_scan' ),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/posts-maintenance',
			'/reset',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'check_permissions' ),
					'callback'            => array( $this, 'reset_scan' ),
				),
			)
		);
	}

	/**
	 * Start scan endpoint.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_scan( WP_REST_Request $request ) {
		$post_types = (array) $request->get_param( 'post_types' );
		$manager    = Manager::instance();
		$result     = $manager->start_job( $post_types );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'job'     => $result,
			)
		);
	}

	/**
	 * Cancel running scan.
	 *
	 * @return WP_REST_Response
	 */
	public function cancel_scan() {
		$job = Manager::instance()->cancel_job();

		return new WP_REST_Response(
			array(
				'success' => true,
				'job'     => $job,
			)
		);
	}

	/**
	 * Current status.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status() {
		return new WP_REST_Response(
			array(
				'success' => true,
				'status'  => Manager::instance()->get_status(),
			)
		);
	}

	/**
	 * Reset stuck job state.
	 *
	 * @return WP_REST_Response
	 */
	public function reset_scan() {
		Manager::instance()->clear_job();

		return new WP_REST_Response(
			array(
				'success' => true,
			)
		);
	}

	/**
	 * Persist preferred settings.
	 *
	 * @param WP_REST_Request $request Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_settings( WP_REST_Request $request ) {
		$post_types   = (array) $request->get_param( 'post_types' );
		$cron_enabled = rest_sanitize_boolean( $request->get_param( 'cron_enabled' ) );
		$cron_time    = sanitize_text_field( $request->get_param( 'cron_time' ) );
		$cron_times   = $request->get_param( 'cron_times' );
		$cron_times   = is_array( $cron_times ) ? array_map( 'sanitize_text_field', $cron_times ) : null;

		$manager = Manager::instance();
		$manager->save_settings( $post_types, $cron_enabled, $cron_time, $cron_times );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'settings' => $manager->get_settings(),
			)
		);
	}

	/**
	 * Capability check helper.
	 *
	 * @return bool
	 */
	public function check_permissions(): bool {
		return current_user_can( 'manage_options' );
	}
}

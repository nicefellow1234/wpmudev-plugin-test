<?php
/**
 * Posts Maintenance manager.
 *
 * @package WPMUDEV\PluginTest\App\Posts_Maintenance
 */

namespace WPMUDEV\PluginTest\App\Posts_Maintenance;

defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WP_Error;
use WP_Query;

/**
 * Coordinates scan jobs, cron hooks, and status tracking.
 */
class Manager extends Base {

	const JOB_OPTION      = 'wpmudev_posts_maintenance_job';
	const SETTINGS_OPTION = 'wpmudev_posts_maintenance_settings';
	const LAST_RUN_OPTION = 'wpmudev_posts_maintenance_last_run';
	const CRON_HOOK       = 'wpmudev_posts_maintenance_process';
	const DAILY_HOOK      = 'wpmudev_posts_maintenance_daily';
	const BATCH_SIZE      = 25;
	const MAX_CRON_RUNS   = 6;

	/**
	 * Whether we are currently processing inline (synchronously).
	 *
	 * @var bool
	 */
	private $inline_processing = false;
	/**
	 * Whether we've already registered the deferred inline runner.
	 *
	 * @var bool
	 */
	private $inline_runner_registered = false;

	/**
	 * Bootstraps hooks.
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );
		add_action( self::DAILY_HOOK, array( $this, 'handle_daily_event' ), 10, 1 );
		add_action( 'init', array( $this, 'maybe_schedule_daily' ) );
	}

	/**
	 * Start a new background job.
	 *
	 * @param array $post_types Requested post types.
	 *
	 * @return array|WP_Error
	 */
	public function start_job( array $post_types = array() ) {
		if ( $this->is_job_active() ) {
			return new WP_Error(
				'post_scan_running',
				__( 'A scan is already running. Please wait for it to finish or cancel it.', 'wpmudev-plugin-test' )
			);
		}

		$filtered = $this->filter_allowed_post_types( $post_types );
		if ( empty( $filtered ) ) {
			$filtered = $this->get_default_post_types();
		}

		$total        = $this->count_posts( $filtered );
		$settings     = $this->get_settings();
		$cron_status  = ! empty( $settings['cron_enabled'] );
		$cron_time    = ! empty( $settings['cron_time'] ) ? $settings['cron_time'] : '00:00';
		$cron_times   = ! empty( $settings['cron_times'] ) ? (array) $settings['cron_times'] : array( $cron_time );
		$prefer_async = $this->is_rest_request() && ! $this->is_cron_disabled();

		$message = __( 'Queued background scan...', 'wpmudev-plugin-test' );
		$status  = $total ? 'pending' : 'completed';

		$job = array(
			'job_id'      => uniqid( 'wpmudev_scan_', true ),
			'post_types'  => $filtered,
			'total'       => $total,
			'processed'   => 0,
			'status'      => $status,
			'cancel_requested' => false,
			'batch'       => array(
				'page'      => 1,
				'per_page'  => self::BATCH_SIZE,
			),
			'started_at'  => time(),
			'updated_at'  => time(),
			'message'     => $message,
		);

		if ( 0 === $total ) {
			$job['completed_at'] = time();
			$job['message']      = __( 'No content matches the selected post types yet.', 'wpmudev-plugin-test' );
		}

		$this->save_settings( $filtered, $cron_status, $cron_time, $cron_times );
		$this->save_job( $job );

		if ( $total > 0 ) {
			if ( $this->should_process_inline_immediately() && ! $prefer_async ) {
				$this->run_inline_until_complete();
			} else {
				$this->queue_processing();

				if ( $prefer_async ) {
					$this->register_deferred_runner();
				}
			}
		}

		return $job;
	}

	/**
	 * Cancel the current job (if any).
	 *
	 * @return array
	 */
	public function cancel_job(): array {
		$job = $this->get_current_job();
		if ( empty( $job ) ) {
			return array(
				'status'     => 'cancelled',
				'total'      => 0,
				'processed'  => 0,
				'message'    => __( 'No active scan to cancel.', 'wpmudev-plugin-test' ),
			);
		}

		if ( ! empty( $job['status'] ) && in_array( $job['status'], array( 'completed', 'cancelled', 'failed' ), true ) ) {
			return $job;
		}

		if ( ! empty( $job['cancel_requested'] ) ) {
			return $job;
		}

		$job['cancel_requested'] = true;
		$job['status']           = 'cancelling';
		$job['message']          = __( 'Cancellation requested. Wrapping up the current batch...', 'wpmudev-plugin-test' );

		$this->save_job( $job );

		return $job;
	}

	/**
	 * Current job status + meta.
	 *
	 * @return array
	 */
	public function get_status(): array {
		return array(
			'job'       => $this->get_current_job(),
			'settings'  => $this->get_settings(),
			'last_run'  => get_option( self::LAST_RUN_OPTION, array() ),
			'postTypes' => $this->get_available_post_types(),
		);
	}

	/**
	 * Process queued job (cron callback).
	 */
	public function process_queue() {
		$job = $this->get_current_job();

		if ( empty( $job ) || in_array( $job['status'], array( 'cancelled', 'completed', 'failed' ), true ) ) {
			return;
		}

		if ( ! empty( $job['cancel_requested'] ) ) {
			$this->finalize_cancellation( $job );
			return;
		}

		$job['status']    = 'running';
		$job['updated_at'] = time();

		$query = $this->query_posts( $job['post_types'], $job['batch']['page'], self::BATCH_SIZE );
		$ids   = $query->posts;

		foreach ( $ids as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'timestamp' ) );
			$job['processed'] ++;
		}

		$this->refresh_cancel_flag( $job, true );
		if ( ! empty( $job['cancel_requested'] ) ) {
			$this->finalize_cancellation( $job );
			return;
		}

		if ( empty( $ids ) || $job['processed'] >= $job['total'] ) {
			$this->refresh_cancel_flag( $job, true );
			if ( ! empty( $job['cancel_requested'] ) ) {
				$this->finalize_cancellation( $job );
				return;
			}

			$job['status']       = 'completed';
			$job['completed_at'] = time();
			$job['message']      = __( 'Maintenance scan finished.', 'wpmudev-plugin-test' );

			update_option(
				self::LAST_RUN_OPTION,
				array(
					'post_types'   => $job['post_types'],
					'completed_at' => $job['completed_at'],
					'processed'    => $job['processed'],
				),
				false
			);

			$this->save_job( $job );

			return;
		}

		$job['batch']['page'] ++;
		$job['message'] = sprintf(
			/* translators: 1: processed posts, 2: total posts */
			__( 'Processed %1$d of %2$d posts...', 'wpmudev-plugin-test' ),
			$job['processed'],
			$job['total']
		);

		$this->save_job( $job );

		if ( ! $this->inline_processing ) {
			$this->queue_processing();
		}
	}

	/**
	 * Manual scan (CLI/tests).
	 *
	 * @param array    $post_types Post types.
	 * @param callable $callback   Progress callback.
	 *
	 * @return int Processed posts.
	 */
	public function run_manual_scan( array $post_types, callable $callback = null ): int {
		$filtered = $this->filter_allowed_post_types( $post_types );
		if ( empty( $filtered ) ) {
			$filtered = $this->get_default_post_types();
		}

		$total     = $this->count_posts( $filtered );
		$processed = 0;
		$page      = 1;

		do {
			$query = $this->query_posts( $filtered, $page, self::BATCH_SIZE );
			$ids   = $query->posts;

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $post_id ) {
				update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'timestamp' ) );
			}

			$processed += count( $ids );
			$page ++;

			if ( $callback ) {
				call_user_func( $callback, $processed, $total );
			}
		} while ( $processed < $total );

		update_option(
			self::LAST_RUN_OPTION,
			array(
				'post_types'   => $filtered,
				'completed_at' => time(),
				'processed'    => $processed,
			),
			false
		);

		return $processed;
	}

	/**
	 * Schedule the recurring daily event if needed.
	 */
	public function maybe_schedule_daily() {
		$settings = $this->get_settings();

		if ( empty( $settings['cron_enabled'] ) ) {
			wp_clear_scheduled_hook( self::DAILY_HOOK );
			return;
		}

		$this->schedule_cron_slots( $settings['cron_times'] );
	}

	/**
	 * Triggered by WP-Cron daily schedule.
	 */
	public function handle_daily_event( $args = array() ) {
		$settings = $this->get_settings();
		if ( empty( $settings['cron_enabled'] ) ) {
			return;
		}

		if ( $this->is_job_active() ) {
			return;
		}

		$post_types = ! empty( $settings['post_types'] ) ? $settings['post_types'] : $this->get_default_post_types();

		$this->start_job( $post_types );
	}

	/**
	 * Retrieve option-backed settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		$defaults = array(
			'post_types'   => $this->get_default_post_types(),
			'cron_enabled' => true,
			'cron_time'    => '00:00',
			'cron_times'   => array( '00:00' ),
		);

		$settings = wp_parse_args(
			get_option( self::SETTINGS_OPTION, array() ),
			$defaults
		);

		$settings['cron_times'] = $this->sanitize_cron_times( (array) $settings['cron_times'] );

		if ( empty( $settings['cron_times'] ) ) {
			$settings['cron_times'] = array( $this->sanitize_time( $settings['cron_time'] ) );
		}

		$settings['cron_time'] = $settings['cron_times'][0];

		return $settings;
	}

	/**
	 * Persist preferred settings.
	 *
	 * @param array  $post_types   Array of post type slugs.
	 * @param bool   $cron_enabled Whether the daily cron job is enabled.
	 * @param string $cron_time    Preferred run time in HH:MM.
	 */
	public function save_settings( array $post_types, bool $cron_enabled = true, string $cron_time = '00:00', array $cron_times = null ) {
		$post_types = $this->filter_allowed_post_types( $post_types );

		if ( empty( $post_types ) ) {
			$post_types = $this->get_default_post_types();
		}

		$cron_time = $this->sanitize_time( $cron_time );

		if ( empty( $cron_times ) ) {
			$cron_times = array( $cron_time );
		}

		$cron_times = $this->sanitize_cron_times( $cron_times );
		$cron_time  = $cron_times[0];

		update_option(
			self::SETTINGS_OPTION,
			array(
				'post_types'   => $post_types,
				'cron_enabled' => $cron_enabled,
				'cron_time'    => $cron_time,
				'cron_times'   => $cron_times,
			),
			false
		);

		$this->refresh_daily_schedule( $cron_enabled, $cron_times );
	}

	/**
	 * Returns current job state from DB.
	 *
	 * @return array
	 */
	private function get_current_job(): array {
		return get_option( self::JOB_OPTION, array() );
	}

	/**
	 * Persist job state.
	 *
	 * @param array $job Job data.
	 */
	private function save_job( array $job ) {
		if ( ! isset( $job['cancel_requested'] ) ) {
			$job['cancel_requested'] = false;
		}

		update_option( self::JOB_OPTION, $job, false );
	}

	/**
	 * Sync the cancel flag from the live option store.
	 *
	 * @param array $job          Current job data (passed by reference).
	 * @param bool  $force_lookup Whether to fetch the latest option and merge the flag.
	 *
	 * @return void
	 */
	private function refresh_cancel_flag( array &$job, bool $force_lookup = false ) {
		if ( ! empty( $job['cancel_requested'] ) ) {
			return;
		}

		if ( ! $force_lookup ) {
			return;
		}

		$current = $this->get_current_job();
		if ( ! empty( $current['cancel_requested'] ) ) {
			$job['cancel_requested'] = true;
		}
	}

	/**
	 * Remove current job state.
	 *
	 * @return void
	 */
	public function clear_job() {
		delete_option( self::JOB_OPTION );
	}

	/**
	 * Finalize a cancelled job state.
	 *
	 * @param array $job Job data.
	 *
	 * @return void
	 */
	private function finalize_cancellation( array $job ) {
		$job['status']           = 'cancelled';
		$job['cancel_requested'] = false;
		$job['completed_at']     = time();
		$job['message']          = __( 'Scan cancelled by user.', 'wpmudev-plugin-test' );

		$this->save_job( $job );
	}

	/**
	 * Ensure the daily cron event matches current preferences.
	 *
	 * @param bool  $cron_enabled Whether the daily job is enabled.
	 * @param array $cron_times   List of HH:MM slots.
	 *
	 * @return void
	 */
	private function refresh_daily_schedule( bool $cron_enabled, array $cron_times ) {
		wp_clear_scheduled_hook( self::DAILY_HOOK );

		if ( empty( $cron_enabled ) ) {
			return;
		}

		$this->schedule_cron_slots( $cron_times );
	}

	/**
	 * Ensure cron events exist for the requested times.
	 *
	 * @param array $cron_times List of HH:MM strings.
	 */
	private function schedule_cron_slots( array $cron_times ) {
		$cron_times = $this->sanitize_cron_times( $cron_times );

		foreach ( $cron_times as $time ) {
			$args = array( 'slot' => $time );

			if ( wp_next_scheduled( self::DAILY_HOOK, $args ) ) {
				continue;
			}

			$timestamp = $this->next_cron_timestamp( $time );

			wp_schedule_event( $timestamp, 'daily', self::DAILY_HOOK, $args );
		}
	}

	/**
	 * Whether a job is pending or running.
	 *
	 * @return bool
	 */
	private function is_job_active(): bool {
		$current = $this->get_current_job();

		return ! empty( $current ) && in_array( $current['status'], array( 'pending', 'running' ), true );
	}

	/**
	 * Schedule background runner.
	 */
	private function queue_processing() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}

		$spawned = false;

		if ( function_exists( 'spawn_cron' ) && ! $this->is_cron_disabled() ) {
			$result = spawn_cron( time() );
			$spawned = ! is_wp_error( $result );
		}

		if ( ! $spawned ) {
			$spawned = $this->trigger_async_cron_request();
		}

		if ( ! $spawned ) {
			$this->run_inline_until_complete();
		}
	}

	/**
	 * Register inline runner to execute after the HTTP response is flushed.
	 *
	 * @return void
	 */
	private function register_deferred_runner() {
		if ( $this->inline_runner_registered ) {
			return;
		}

		$this->inline_runner_registered = true;
		add_action( 'shutdown', array( $this, 'finish_job_after_response' ), PHP_INT_MAX );
	}

	/**
	 * Called on shutdown to process the queue after the response is sent.
	 *
	 * @return void
	 */
	public function finish_job_after_response() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			@fastcgi_finish_request();
		}

		$this->run_inline_until_complete();
	}

	/**
	 * Runs the queue synchronously when WP-Cron isn't available.
	 *
	 * @return void
	 */
	private function run_inline_until_complete() {
		$this->inline_processing = true;

		$attempts = 0;
		while ( $attempts < 200 ) {
			$attempts ++;
			$job = $this->get_current_job();

			if ( empty( $job ) || in_array( $job['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
				break;
			}

			$this->process_queue();
			usleep( 10000 ); // Sleep 10ms to avoid hogging CPU
		}

		$this->inline_processing = false;
		$this->inline_runner_registered = false;
	}

	/**
	 * Whether WP Cron is disabled.
	 *
	 * @return bool
	 */
	private function is_cron_disabled(): bool {
		return ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	private function next_cron_timestamp( string $time ): int {
		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time ) + array( 0, 0 ) );

		$now      = current_time( 'timestamp' );
		$next_run = mktime( $hours, $minutes, 0, date( 'n', $now ), date( 'j', $now ), date( 'Y', $now ) );

		if ( $next_run <= $now ) {
			$next_run = strtotime( '+1 day', $next_run );
		}

		return $next_run;
	}

	private function sanitize_time( string $time ): string {
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', trim( $time ), $matches ) ) {
			$hours   = str_pad( min( 23, (int) $matches[1] ), 2, '0', STR_PAD_LEFT );
			$minutes = str_pad( min( 59, (int) $matches[2] ), 2, '0', STR_PAD_LEFT );
			return $hours . ':' . $minutes;
		}

		return '00:00';
	}

	/**
	 * Normalize and limit cron run times.
	 *
	 * @param array $times Raw HH:MM values.
	 *
	 * @return array
	 */
	private function sanitize_cron_times( array $times ): array {
		$normalized = array();

		foreach ( $times as $time ) {
			$normalized[] = $this->sanitize_time( (string) $time );
		}

		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized );

		if ( empty( $normalized ) ) {
			$normalized = array( '00:00' );
		}

		if ( count( $normalized ) > self::MAX_CRON_RUNS ) {
			$normalized = array_slice( $normalized, 0, self::MAX_CRON_RUNS );
		}

		return $normalized;
	}

	/**
	 * Should we process the job inline immediately?
	 *
	 * @return bool
	 */
	private function should_process_inline_immediately(): bool {
		if ( $this->is_cron_disabled() ) {
			return true;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return true;
		}

		if ( php_sapi_name() === 'cli' ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_php_sapi_name
			return true;
		}

		return false;
	}

	/**
	 * Detect REST context.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Attempt to fire wp-cron.php asynchronously using HTTP request.
	 *
	 * @return bool
	 */
	private function trigger_async_cron_request(): bool {
		if ( $this->is_cron_disabled() ) {
			return false;
		}

		$url = add_query_arg(
			'doing_wp_cron',
			sprintf( '%.22F', microtime( true ) ),
			site_url( 'wp-cron.php' )
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		return ! is_wp_error( $response );
	}

	/**
	 * Get a unique list of statuses required for the provided post types.
	 *
	 * @param array $post_types Post type slugs.
	 *
	 * @return array
	 */
	private function get_statuses_for_post_types( array $post_types ): array {
		$statuses = array();

		foreach ( $post_types as $type ) {
			$statuses = array_merge( $statuses, $this->get_supported_statuses_for_type( $type ) );
		}

		if ( empty( $statuses ) ) {
			$statuses = array( 'publish' );
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Supported statuses for a single post type.
	 *
	 * @param string $type Post type slug.
	 *
	 * @return array
	 */
	private function get_supported_statuses_for_type( string $type ): array {
		if ( 'attachment' === $type ) {
			return array( 'inherit' );
		}

		/**
		 * Filter supported post statuses for a specific type.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $statuses Default statuses.
		 * @param string $type     Post type slug.
		 */
		return apply_filters( 'wpmudev_posts_maintenance_statuses', array( 'publish' ), $type );
	}

	/**
	 * Count total posts for given types.
	 *
	 * @param array $post_types Post types.
	 *
	 * @return int
	 */
	private function count_posts( array $post_types ): int {
		$total = 0;

		foreach ( $post_types as $type ) {
			$counts   = wp_count_posts( $type );
			$statuses = $this->get_statuses_for_post_types( array( $type ) );

			foreach ( $statuses as $status ) {
				$total += (int) ( $counts->{$status} ?? 0 );
			}
		}

		return $total;
	}

	/**
	 * Query a single batch of posts.
	 *
	 * @param array $post_types Post types.
	 * @param int   $page       Page number.
	 * @param int   $per_page   Batch size.
	 *
	 * @return WP_Query
	 */
	private function query_posts( array $post_types, int $page, int $per_page ): WP_Query {
		$post_statuses = $this->get_statuses_for_post_types( $post_types );

		return new WP_Query(
			array(
				'post_type'           => $post_types,
				'post_status'         => $post_statuses,
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'fields'              => 'ids',
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);
	}

	/**
	 * Allowed post types (public).
	 *
	 * @return array[] Array of arrays with slug+label.
	 */
	public function get_available_post_types(): array {
		$objects = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		$list = array();
		foreach ( $objects as $slug => $object ) {
			$list[] = array(
				'slug'  => $slug,
				'label' => $object->labels->singular_name ?? $object->label ?? $slug,
			);
		}

		return $list;
	}

	/**
	 * Filter requested post types down to allowed values.
	 *
	 * @param array $post_types Post types.
	 *
	 * @return array
	 */
	private function filter_allowed_post_types( array $post_types ): array {
		$allowed = wp_list_pluck( $this->get_available_post_types(), 'slug' );

		return array_values(
			array_intersect(
				array_map( 'sanitize_key', $post_types ),
				$allowed
			)
		);
	}

	/**
	 * Default fallback types.
	 *
	 * @return array
	 */
	private function get_default_post_types(): array {
		$allowed = wp_list_pluck( $this->get_available_post_types(), 'slug' );

		$defaults = array( 'post', 'page' );
		$filtered = array_values( array_intersect( $defaults, $allowed ) );

		if ( empty( $filtered ) && ! empty( $allowed ) ) {
			return array( $allowed[0] );
		}

		return $filtered;
	}
}

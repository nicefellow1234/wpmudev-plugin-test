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

	/**
	 * Whether we are currently processing inline (synchronously).
	 *
	 * @var bool
	 */
	private $inline_processing = false;

	/**
	 * Bootstraps hooks.
	 */
	public function init() {
		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );
    add_action( self::DAILY_HOOK, array( $this, 'handle_daily_event' ) );
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

		$total       = $this->count_posts( $filtered );
		$settings    = $this->get_settings();
		$cron_status = ! empty( $settings['cron_enabled'] );
		$cron_time   = ! empty( $settings['cron_time'] ) ? $settings['cron_time'] : '00:00';

		$message = __( 'Queued background scan…', 'wpmudev-plugin-test' );
		$status  = $total ? 'pending' : 'completed';

		$job = array(
			'job_id'      => uniqid( 'wpmudev_scan_', true ),
			'post_types'  => $filtered,
			'total'       => $total,
			'processed'   => 0,
			'status'      => $status,
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

		$this->save_settings( $filtered, $cron_status, $cron_time );
		$this->save_job( $job );

		if ( $total > 0 ) {
			if ( $this->should_process_inline_immediately() ) {
				$this->run_inline_until_complete();
			} else {
				$this->queue_processing();
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

		$job['status']       = 'cancelled';
		$job['completed_at'] = time();
		$job['message']      = __( 'Scan cancelled by user.', 'wpmudev-plugin-test' );

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

		$job['status']    = 'running';
		$job['updated_at'] = time();

		$query = $this->query_posts( $job['post_types'], $job['batch']['page'], self::BATCH_SIZE );
		$ids   = $query->posts;

		foreach ( $ids as $post_id ) {
			update_post_meta( $post_id, 'wpmudev_test_last_scan', current_time( 'timestamp' ) );
			$job['processed'] ++;
		}

		if ( empty( $ids ) || $job['processed'] >= $job['total'] ) {
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
			__( 'Processed %1$d of %2$d posts…', 'wpmudev-plugin-test' ),
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

		$timestamp = $this->next_cron_timestamp( $settings['cron_time'] );

		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( $timestamp, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Triggered by WP-Cron daily schedule.
	 */
	public function handle_daily_event() {
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
		);

		$settings = get_option( self::SETTINGS_OPTION, array() );

		return wp_parse_args( $settings, $defaults );
	}

	/**
	 * Persist preferred settings.
	 *
	 * @param array $post_types Array of post type slugs.
	 */
	public function save_settings( array $post_types, bool $cron_enabled = true, string $cron_time = '00:00' ) {
		$cron_time = $this->sanitize_time( $cron_time );

		update_option(
			self::SETTINGS_OPTION,
			array(
				'post_types'   => $post_types,
				'cron_enabled' => $cron_enabled,
				'cron_time'    => $cron_time,
			),
			false
		);

		$this->maybe_schedule_daily();
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
		update_option( self::JOB_OPTION, $job, false );
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
			$this->run_inline_until_complete();
		}
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
	 * Should we process the job inline immediately?
	 *
	 * @return bool
	 */
	private function should_process_inline_immediately(): bool {
		if ( $this->is_cron_disabled() ) {
			return true;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
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

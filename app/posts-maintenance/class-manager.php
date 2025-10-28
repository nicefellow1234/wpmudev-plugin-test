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
	const BATCH_SIZE      = 200;
	const MAX_CRON_RUNS   = 6;
	const CRON_HISTORY_OPTION = 'wpmudev_posts_maintenance_cron_history';
	const MAX_CRON_HISTORY    = 10;

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
		add_action( 'init', array( $this, 'maybe_resume_pending_job' ), 20 );
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
			$this->maybe_clear_stale_cron_lock();
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
			'job'         => $this->get_current_job(),
			'settings'    => $this->get_settings(),
			'last_run'    => get_option( self::LAST_RUN_OPTION, array() ),
			'postTypes'   => $this->get_available_post_types(),
			'cron_events' => $this->get_cron_events(),
			'cron_history' => $this->get_cron_history(),
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

		$per_page = isset( $job['batch']['per_page'] ) && $job['batch']['per_page'] > 0
			? (int) $job['batch']['per_page']
			: self::BATCH_SIZE;

		$query = $this->query_posts( $job['post_types'], $job['batch']['page'], $per_page );
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

			if ( ! empty( $job['cron_run_id'] ) ) {
				$this->update_cron_history_entry(
					$job['cron_run_id'],
					array(
						'status'       => 'completed',
						'completed_at' => $job['completed_at'],
						'processed'    => $job['processed'],
					)
				);
			}

			return;
		}

		$job['batch']['page'] ++;
		$job['batch']['per_page'] = $per_page;
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
	public function handle_daily_event( $slot = '', $args = array() ) {
		$settings = $this->get_settings();
		if ( empty( $settings['cron_enabled'] ) ) {
			return;
		}

		if ( $this->is_job_active() ) {
			return;
		}

		if ( is_array( $slot ) ) {
			$args = $slot;
			$slot = '';
		}

		if ( empty( $slot ) && is_array( $args ) && isset( $args['slot'] ) ) {
			$slot = $args['slot'];
		}

		if ( ! is_string( $slot ) ) {
			$slot = '';
		}

		$slot       = '' !== $slot ? $this->sanitize_time( $slot ) : '';
		$post_types = ! empty( $settings['post_types'] ) ? $settings['post_types'] : $this->get_default_post_types();

		$result = $this->start_job( $post_types );

		if ( is_wp_error( $result ) ) {
			return;
		}

		if ( ! is_array( $result ) ) {
			return;
		}

		$run_id              = $this->record_cron_history_start( $result, $slot );
		$result['cron_run_id'] = $run_id;

		$this->save_job( $result );

		if ( 'completed' === ( $result['status'] ?? '' ) ) {
			$this->update_cron_history_entry(
				$run_id,
				array(
					'status'       => 'completed',
					'completed_at' => $result['completed_at'] ?? time(),
					'processed'    => $result['processed'] ?? 0,
				)
			);
		}
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

		if ( ! empty( $job['cron_run_id'] ) ) {
			$this->update_cron_history_entry(
				$job['cron_run_id'],
				array(
					'status'    => $job['status'] ?? 'pending',
					'processed' => $job['processed'] ?? 0,
				)
			);
		}
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
		$job = $this->get_current_job();

		delete_option( self::JOB_OPTION );

		if ( ! empty( $job['cron_run_id'] ) ) {
			$this->update_cron_history_entry(
				$job['cron_run_id'],
				array(
					'status'       => 'reset',
					'completed_at' => time(),
				)
			);
		}
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

		if ( ! empty( $job['cron_run_id'] ) ) {
			$this->update_cron_history_entry(
				$job['cron_run_id'],
				array(
					'status'       => 'cancelled',
					'completed_at' => $job['completed_at'],
					'processed'    => $job['processed'] ?? 0,
				)
			);
		}
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
	 * List upcoming WP-Cron executions for the maintenance job.
	 *
	 * @return array
	 */
	public function get_cron_events(): array {
		$events = array();
		$cron   = _get_cron_array();

		if ( empty( $cron ) ) {
			return $events;
		}

		$timezone      = wp_timezone();
		$timezone_name = wp_timezone_string() ? wp_timezone_string() : 'UTC';
		$now           = time();

		foreach ( $cron as $timestamp => $hooks ) {
			if ( empty( $hooks[ self::DAILY_HOOK ] ) ) {
				continue;
			}

			foreach ( $hooks[ self::DAILY_HOOK ] as $event ) {
				$args = is_array( $event['args'] ) ? reset( $event['args'] ) : array();
				$slot = empty( $args['slot'] ) ? '' : $this->sanitize_time( (string) $args['slot'] );

				$events[] = array(
					'timestamp' => (int) $timestamp,
					'slot'      => $slot,
					'local'     => wp_date( 'Y-m-d g:i a', $timestamp, $timezone ),
					'timezone'  => $timezone_name,
					'relative'  => $timestamp >= $now
						? sprintf(
							/* translators: %s - human readable time difference */
							__( 'In %s', 'wpmudev-plugin-test' ),
							human_time_diff( $now, $timestamp )
						)
						: sprintf(
							/* translators: %s - human readable time difference */
							__( '%s ago', 'wpmudev-plugin-test' ),
							human_time_diff( $timestamp, $now )
						),
				);
			}
		}

		usort(
			$events,
			static function ( $a, $b ) {
				return $a['timestamp'] <=> $b['timestamp'];
			}
		);

		return $events;
	}

	/**
	 * Retrieve stored cron history entries.
	 *
	 * @return array
	 */
	private function get_cron_history_store(): array {
		$history = get_option( self::CRON_HISTORY_OPTION, array() );

		return is_array( $history ) ? $history : array();
	}

	/**
	 * Persist cron history entries.
	 *
	 * @param array $history Entries to store.
	 *
	 * @return void
	 */
	private function persist_cron_history( array $history ) {
		update_option( self::CRON_HISTORY_OPTION, array_values( $history ), false );
	}

	/**
	 * Record a newly triggered cron run.
	 *
	 * @param array  $job  Job data.
	 * @param string $slot Slot string (HH:MM).
	 *
	 * @return string Run identifier.
	 */
	private function record_cron_history_start( array $job, string $slot ): string {
		$history        = $this->get_cron_history_store();
		$timestamp      = time();
		$scheduled_for  = null;
		$normalizedSlot = '';

		if ( ! empty( $slot ) ) {
			$normalizedSlot = $this->sanitize_time( $slot );
			$scheduled_for  = $this->estimate_previous_slot_timestamp( $normalizedSlot );
		}

		$entry = array(
			'run_id'        => uniqid( 'cron_run_', true ),
			'job_id'        => $job['job_id'] ?? '',
			'slot'          => $normalizedSlot,
			'scheduled_for' => $scheduled_for,
			'triggered_at'  => $timestamp,
			'status'        => 'pending',
			'processed'     => 0,
		);

		array_unshift( $history, $entry );

		if ( count( $history ) > self::MAX_CRON_HISTORY ) {
			$history = array_slice( $history, 0, self::MAX_CRON_HISTORY );
		}

		$this->persist_cron_history( $history );

		return $entry['run_id'];
	}

	/**
	 * Update stored cron history entry.
	 *
	 * @param string $run_id  Run identifier.
	 * @param array  $changes Values to merge.
	 *
	 * @return void
	 */
	private function update_cron_history_entry( string $run_id, array $changes ): void {
		if ( empty( $run_id ) ) {
			return;
		}

		$history = $this->get_cron_history_store();
		$updated = false;

		foreach ( $history as $index => $entry ) {
			if ( empty( $entry['run_id'] ) || $entry['run_id'] !== $run_id ) {
				continue;
			}

			$history[ $index ]             = array_merge( $entry, $changes );
			$history[ $index ]['processed'] = isset( $history[ $index ]['processed'] ) ? (int) $history[ $index ]['processed'] : 0;
			$history[ $index ]['updated_at'] = time();
			$updated                        = true;
			break;
		}

		if ( $updated ) {
			$this->persist_cron_history( $history );
		}
	}

	/**
	 * Public accessor for cron history (formatted).
	 *
	 * @return array
	 */
	public function get_cron_history(): array {
		$history = $this->get_cron_history_store();

		if ( empty( $history ) ) {
			return array();
		}

		$timezone = wp_timezone();
		$now      = time();

		return array_map(
			function ( $entry ) use ( $timezone, $now ) {
				$scheduled = ! empty( $entry['scheduled_for'] ) ? (int) $entry['scheduled_for'] : null;
				$triggered = ! empty( $entry['triggered_at'] ) ? (int) $entry['triggered_at'] : null;
				$completed = ! empty( $entry['completed_at'] ) ? (int) $entry['completed_at'] : null;

				return array(
					'run_id'          => $entry['run_id'],
					'job_id'          => $entry['job_id'] ?? '',
					'slot'            => $entry['slot'] ?? '',
					'status'          => $entry['status'] ?? 'pending',
					'status_label'    => $this->get_cron_history_status_label( $entry['status'] ?? 'pending' ),
					'scheduled_for'   => $scheduled,
					'scheduled_local' => $scheduled ? wp_date( 'Y-m-d g:i a', $scheduled, $timezone ) : '',
					'triggered_at'    => $triggered,
					'triggered_local' => $triggered ? wp_date( 'Y-m-d g:i a', $triggered, $timezone ) : '',
					'completed_at'    => $completed,
					'completed_local' => $completed ? wp_date( 'Y-m-d g:i a', $completed, $timezone ) : '',
					'processed'       => isset( $entry['processed'] ) ? (int) $entry['processed'] : 0,
					'relative'        => $this->get_cron_history_relative( $entry, $now ),
				);
			},
			$history
		);
	}

	/**
	 * Convert status slug to label.
	 *
	 * @param string $status Status key.
	 *
	 * @return string
	 */
	private function get_cron_history_status_label( string $status ): string {
		switch ( $status ) {
			case 'running':
				return __( 'Running', 'wpmudev-plugin-test' );
			case 'completed':
				return __( 'Completed', 'wpmudev-plugin-test' );
			case 'cancelled':
				return __( 'Cancelled', 'wpmudev-plugin-test' );
			case 'failed':
				return __( 'Failed', 'wpmudev-plugin-test' );
			case 'reset':
				return __( 'Reset', 'wpmudev-plugin-test' );
			case 'pending':
			default:
				return __( 'Pending', 'wpmudev-plugin-test' );
		}
	}

	/**
	 * Build a relative description for a cron history entry.
	 *
	 * @param array $entry Entry data.
	 * @param int   $now   Current timestamp.
	 *
	 * @return string
	 */
	private function get_cron_history_relative( array $entry, int $now ): string {
		if ( ! empty( $entry['completed_at'] ) ) {
			$status = $entry['status'] ?? 'completed';
			$diff   = human_time_diff( (int) $entry['completed_at'], $now );

			switch ( $status ) {
				case 'cancelled':
					return sprintf( __( 'Cancelled %s ago', 'wpmudev-plugin-test' ), $diff );
				case 'failed':
					return sprintf( __( 'Failed %s ago', 'wpmudev-plugin-test' ), $diff );
				case 'reset':
					return sprintf( __( 'Reset %s ago', 'wpmudev-plugin-test' ), $diff );
				default:
					return sprintf( __( 'Completed %s ago', 'wpmudev-plugin-test' ), $diff );
			}
		}

		if ( ! empty( $entry['status'] ) && 'running' === $entry['status'] && ! empty( $entry['triggered_at'] ) ) {
			return sprintf(
				/* translators: %s - human readable time difference */
				__( 'Started %s ago', 'wpmudev-plugin-test' ),
				human_time_diff( (int) $entry['triggered_at'], $now )
			);
		}

		if ( ! empty( $entry['scheduled_for'] ) ) {
			$scheduled = (int) $entry['scheduled_for'];

			if ( $scheduled > $now ) {
				return sprintf(
					/* translators: %s - human readable time difference */
					__( 'Scheduled in %s', 'wpmudev-plugin-test' ),
					human_time_diff( $now, $scheduled )
				);
			}

			return sprintf(
				/* translators: %s - human readable time difference */
				__( 'Scheduled %s ago', 'wpmudev-plugin-test' ),
				human_time_diff( $scheduled, $now )
			);
		}

		return '';
	}

	/**
	 * Estimate latest occurrence of a slot before now.
	 *
	 * @param string $slot Time in HH:MM.
	 *
	 * @return int|null
	 */
	private function estimate_previous_slot_timestamp( string $slot ) {
		if ( empty( $slot ) ) {
			return null;
		}

		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $slot ) + array( 0, 0 ) );
		$timezone                = wp_timezone();
		$now                     = new \DateTimeImmutable( 'now', $timezone );
		$candidate               = $now->setTime( $hours, $minutes, 0 );

		if ( $candidate > $now ) {
			$candidate = $candidate->modify( '-1 day' );
		}

		return $candidate->getTimestamp();
	}

	public function delete_cron_event( int $timestamp, string $slot = '' ): bool {
		$timestamp = absint( $timestamp );
		if ( $timestamp <= 0 ) {
			return false;
		}

		$cron = _get_cron_array();
		if ( empty( $cron[ $timestamp ][ self::DAILY_HOOK ] ) ) {
			return false;
		}

		$slot = '' !== $slot ? $this->sanitize_time( sanitize_text_field( $slot ) ) : '';

		foreach ( $cron[ $timestamp ][ self::DAILY_HOOK ] as $event ) {
			$args       = is_array( $event['args'] ) ? reset( $event['args'] ) : array();
			$event_slot = empty( $args['slot'] ) ? '' : $this->sanitize_time( (string) $args['slot'] );

			if ( '' !== $slot && $event_slot !== $slot ) {
				continue;
			}

			wp_unschedule_event( $timestamp, self::DAILY_HOOK, $event['args'] );

			return true;
		}

		return false;
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
		$this->maybe_clear_stale_cron_lock();

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

	/**
	 * Remove stale cron locks so scheduled events can run again.
	 *
	 * @return void
	 */
	private function maybe_clear_stale_cron_lock(): void {
		$lock = get_option( '_transient_doing_cron' );

		if ( empty( $lock ) ) {
			return;
		}

		$timestamp = (float) $lock;

		if ( $timestamp <= 0 ) {
			return;
		}

		// If the lock is older than 60 seconds consider it stale.
		if ( $timestamp < ( microtime( true ) - 60 ) ) {
			delete_option( '_transient_doing_cron' );
		}
	}

	/**
	 * Ensure there is always a worker scheduled for pending jobs.
	 *
	 * @return void
	 */
	public function maybe_resume_pending_job(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$job = $this->get_current_job();

		if ( empty( $job ) ) {
			return;
		}

		$status = isset( $job['status'] ) ? $job['status'] : '';

		if ( ! in_array( $status, array( 'pending', 'running' ), true ) ) {
			return;
		}

		$this->maybe_clear_stale_cron_lock();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	private function next_cron_timestamp( string $time ): int {
		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time ) + array( 0, 0 ) );

		$timezone = wp_timezone();
		$now      = new \DateTimeImmutable( 'now', $timezone );

		$next_run = $now->setTime( $hours, $minutes, 0 );

		if ( $next_run <= $now ) {
			$next_run = $next_run->modify( '+1 day' );
		}

		return $next_run->getTimestamp();
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

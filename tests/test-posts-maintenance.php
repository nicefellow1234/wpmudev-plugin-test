<?php

use WPMUDEV\PluginTest\App\Posts_Maintenance\Manager;

class Test_Posts_Maintenance extends WP_UnitTestCase {

	/**
	 * @var Manager
	 */
	protected $manager;

	protected function setUp(): void {
		parent::setUp();

		$this->manager = Manager::instance();
		$this->manager->clear_job();

		delete_option( Manager::JOB_OPTION );
		delete_option( Manager::SETTINGS_OPTION );
		delete_option( Manager::LAST_RUN_OPTION );
		delete_option( Manager::CRON_HISTORY_OPTION );

		wp_clear_scheduled_hook( Manager::CRON_HOOK );
		wp_clear_scheduled_hook( Manager::DAILY_HOOK );
	}

	public function tearDown(): void {
		parent::tearDown();

		delete_option( Manager::JOB_OPTION );
		delete_option( Manager::SETTINGS_OPTION );
		delete_option( Manager::LAST_RUN_OPTION );
		delete_option( Manager::CRON_HISTORY_OPTION );

		wp_clear_scheduled_hook( Manager::CRON_HOOK );
		wp_clear_scheduled_hook( Manager::DAILY_HOOK );
	}

	/**
	 * @testdox Manual scan updates last-run option and post meta timestamps
	 */
	public function test_run_manual_scan_updates_meta_and_last_run() {
		$post_ids = self::factory()->post->create_many( 3 );

		$processed = $this->manager->run_manual_scan( array( 'post' ) );

		$this->assertSame( 3, $processed );

		foreach ( $post_ids as $post_id ) {
			$this->assertNotEmpty( get_post_meta( $post_id, 'wpmudev_test_last_scan', true ) );
		}

		$last_run = get_option( Manager::LAST_RUN_OPTION );
		$this->assertSame( array( 'post' ), $last_run['post_types'] );
		$this->assertSame( 3, $last_run['processed'] );
		$this->assertNotEmpty( $last_run['completed_at'] );
	}

	/**
	 * @testdox Manual scan returns zero processed when no posts match the selected type
	 */
	public function test_run_manual_scan_without_matches_returns_zero() {
		register_post_type( 'book', array( 'public' => true, 'label' => 'Books' ) );

		try {
			$processed = $this->manager->run_manual_scan( array( 'book' ) );
			$this->assertSame( 0, $processed );

			$last_run = get_option( Manager::LAST_RUN_OPTION );
			$this->assertSame( array( 'book' ), $last_run['post_types'] );
			$this->assertSame( 0, $last_run['processed'] );
		} finally {
			unregister_post_type( 'book' );
		}
	}

	/**
	 * @testdox Manual scan invokes the progress callback with cumulative counts
	 */
	public function test_run_manual_scan_triggers_progress_callback() {
		self::factory()->post->create_many( 4 );

		$progress_updates = array();

		$processed = $this->manager->run_manual_scan(
			array( 'post' ),
			function ( $processed, $total ) use ( &$progress_updates ) {
				$progress_updates[] = array( $processed, $total );
			}
		);

		$this->assertSame( 4, $processed );
		$this->assertNotEmpty( $progress_updates );

		$last = end( $progress_updates );
		$this->assertSame( array( 4, 4 ), $last );
	}

	/**
	 * @testdox Manual scan only touches the post types that were requested
	 */
	public function test_manual_scan_respects_requested_post_types() {
		register_post_type( 'book', array( 'public' => true, 'label' => 'Books' ) );

		$post_id = self::factory()->post->create();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$book_id = self::factory()->post->create( array( 'post_type' => 'book' ) );

		try {
			$this->manager->run_manual_scan( array( 'page' ) );
		} finally {
			unregister_post_type( 'book' );
		}

		$this->assertEmpty( get_post_meta( $post_id, 'wpmudev_test_last_scan', true ) );
		$this->assertNotEmpty( get_post_meta( $page_id, 'wpmudev_test_last_scan', true ) );
		$this->assertEmpty( get_post_meta( $book_id, 'wpmudev_test_last_scan', true ) );
	}

	/**
	 * @testdox Starting a new job while one is active returns a WP_Error
	 */
	public function test_start_job_returns_error_when_job_active() {
		update_option(
			Manager::JOB_OPTION,
			array(
				'job_id'     => 'demo',
				'post_types' => array( 'post' ),
				'total'      => 5,
				'processed'  => 2,
				'status'     => 'running',
			)
		);

		$result = $this->manager->start_job( array( 'post' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'post_scan_running', $result->get_error_code() );
	}

	/**
	 * @testdox Jobs auto-complete immediately when no posts are available
	 */
	public function test_start_job_auto_completes_when_no_posts_found() {
		register_post_type( 'book', array( 'public' => true, 'label' => 'Books' ) );

		try {
			$job = $this->manager->start_job( array( 'book' ) );

			$this->assertSame( 'completed', $job['status'] );
			$this->assertSame( 0, $job['total'] );
			$this->assertArrayHasKey( 'completed_at', $job );
			$this->assertSame(
				'No content matches the selected post types yet.',
				$job['message']
			);
		} finally {
			unregister_post_type( 'book' );
		}
	}

	/**
	 * @testdox Jobs process every matching post and update last-run metadata
	 */
	public function test_start_job_processes_posts_and_updates_meta() {
		$post_ids = self::factory()->post->create_many( 5 );

		$job = $this->manager->start_job( array( 'post' ) );
		$stored_job = get_option( Manager::JOB_OPTION );

		$this->assertSame( 'completed', $stored_job['status'] );
		$this->assertSame( 5, $stored_job['total'] );
		$this->assertSame( 5, $stored_job['processed'] );
		$this->assertArrayHasKey( 'completed_at', $stored_job );

		foreach ( $post_ids as $post_id ) {
			$this->assertNotEmpty( get_post_meta( $post_id, 'wpmudev_test_last_scan', true ) );
		}

		$last_run = get_option( Manager::LAST_RUN_OPTION );
		$this->assertSame( array( 'post' ), $last_run['post_types'] );
		$this->assertSame( 5, $last_run['processed'] );
	}

	/**
	 * @testdox Queue processing advances a single batch when running inline
	 */
	public function test_process_queue_processes_single_batch_without_requeue() {
		$post_ids = self::factory()->post->create_many( 5 );

		update_option(
			Manager::JOB_OPTION,
			array(
				'job_id'           => 'demo',
				'post_types'       => array( 'post' ),
				'total'            => 5,
				'processed'        => 0,
				'status'           => 'pending',
				'cancel_requested' => false,
				'batch'            => array(
					'page'     => 1,
					'per_page' => 2,
				),
			)
		);

		$reflection = new ReflectionClass( $this->manager );
		$inline     = $reflection->getProperty( 'inline_processing' );
		$inline->setAccessible( true );
		$inline->setValue( $this->manager, true );

		try {
			$this->manager->process_queue();
		} finally {
			$inline->setValue( $this->manager, false );
			$inline->setAccessible( false );
		}

		$job = get_option( Manager::JOB_OPTION );

		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( 2, $job['processed'] );
		$this->assertSame( 2, $job['batch']['page'] );

		$processed_ids = array_slice( $post_ids, 0, 2 );
		$pending_ids   = array_slice( $post_ids, 2 );

		foreach ( $processed_ids as $post_id ) {
			$this->assertNotEmpty( get_post_meta( $post_id, 'wpmudev_test_last_scan', true ) );
		}

		foreach ( $pending_ids as $post_id ) {
			$this->assertEmpty( get_post_meta( $post_id, 'wpmudev_test_last_scan', true ) );
		}
	}

	/**
	 * @testdox Custom status filters expand the set of posts included in scans
	 */
	public function test_scan_honors_custom_status_filter() {
		register_post_type( 'resource', array( 'public' => true, 'label' => 'Resources' ) );

		$draft_id   = self::factory()->post->create( array( 'post_type' => 'resource', 'post_status' => 'draft' ) );
		$publish_id = self::factory()->post->create( array( 'post_type' => 'resource', 'post_status' => 'publish' ) );

		$filter = function ( $statuses, $type ) {
			if ( 'resource' === $type ) {
				$statuses[] = 'draft';
			}

			return $statuses;
		};

		add_filter( 'wpmudev_posts_maintenance_statuses', $filter, 10, 2 );

		try {
			$this->manager->start_job( array( 'resource' ) );
			$job = get_option( Manager::JOB_OPTION );

			$this->assertSame( 'completed', $job['status'] );
			$this->assertSame( 2, $job['processed'] );

			$this->assertNotEmpty( get_post_meta( $draft_id, 'wpmudev_test_last_scan', true ) );
			$this->assertNotEmpty( get_post_meta( $publish_id, 'wpmudev_test_last_scan', true ) );
		} finally {
			remove_filter( 'wpmudev_posts_maintenance_statuses', $filter, 10 );
			unregister_post_type( 'resource' );
		}
	}

	/**
	 * @testdox Attachments are scanned using the inherit status
	 */
	public function test_scan_handles_attachment_status_inherit() {
		$attachment_id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		try {
			$this->manager->start_job( array( 'attachment' ) );
			$job = get_option( Manager::JOB_OPTION );

			$this->assertSame( 'completed', $job['status'] );
			$this->assertSame( 1, $job['processed'] );
			$this->assertNotEmpty( get_post_meta( $attachment_id, 'wpmudev_test_last_scan', true ) );
		} finally {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * @testdox Saving settings sanitizes input and schedules cron slots
	 */
	public function test_save_settings_sanitizes_values_and_schedules_cron() {
		register_post_type( 'book', array( 'public' => true, 'label' => 'Books' ) );

		try {
			$this->manager->save_settings( array( 'book', 'invalid_type' ), true, '27:72' );
			$settings = $this->manager->get_settings();

			$this->assertSame( array( 'book' ), $settings['post_types'] );
			$this->assertSame( '23:59', $settings['cron_time'] );
			$this->assertSame( array( '23:59' ), $settings['cron_times'] );
			$this->assertNotFalse(
				wp_next_scheduled( Manager::DAILY_HOOK, array( 'slot' => '23:59' ) )
			);
		} finally {
			unregister_post_type( 'book' );
		}
	}

	/**
	 * @testdox Disabling cron clears any scheduled automation events
	 */
	public function test_disabling_cron_clears_schedule() {
		$this->manager->save_settings( array( 'post' ), true, '04:00', array( '04:00', '16:00' ) );
		$this->assertNotFalse( wp_next_scheduled( Manager::DAILY_HOOK, array( 'slot' => '04:00' ) ) );
		$this->assertNotFalse( wp_next_scheduled( Manager::DAILY_HOOK, array( 'slot' => '16:00' ) ) );

		$this->manager->save_settings( array( 'post' ), false, '04:00' );
		$this->assertFalse( wp_next_scheduled( Manager::DAILY_HOOK ) );
	}

	/**
	 * @testdox Multiple cron slots each register a scheduled event
	 */
	public function test_multiple_cron_times_schedule_events() {
		$this->manager->save_settings( array( 'post' ), true, '01:00', array( '01:00', '13:30' ) );

		$this->assertNotFalse( wp_next_scheduled( Manager::DAILY_HOOK, array( 'slot' => '01:00' ) ) );
		$this->assertNotFalse( wp_next_scheduled( Manager::DAILY_HOOK, array( 'slot' => '13:30' ) ) );
	}

	/**
	 * @testdox Cancelling a job flips it into the cancelling state
	 */
	public function test_cancel_job_marks_cancelling() {
		update_option(
			Manager::JOB_OPTION,
			array(
				'job_id'           => 'demo',
				'post_types'       => array( 'post' ),
				'total'            => 10,
				'processed'        => 2,
				'status'           => 'running',
				'cancel_requested' => false,
				'batch'            => array(
					'page'     => 1,
					'per_page' => 25,
				),
			)
		);

		$job = $this->manager->cancel_job();

		$this->assertTrue( $job['cancel_requested'] );
		$this->assertSame( 'cancelling', $job['status'] );
	}

	/**
	 * @testdox Queue processing respects cancel requests mid-run
	 */
	public function test_process_queue_honors_cancel_request() {
		update_option(
			Manager::JOB_OPTION,
			array(
				'job_id'           => 'demo',
				'post_types'       => array( 'post' ),
				'total'            => 10,
				'processed'        => 0,
				'status'           => 'pending',
				'cancel_requested' => true,
				'batch'            => array(
					'page'     => 1,
					'per_page' => 25,
				),
			)
		);

		$this->manager->process_queue();
		$job = get_option( Manager::JOB_OPTION );

		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertFalse( $job['cancel_requested'] );
	}
}

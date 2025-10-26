<?php

class Test_Posts_Maintenance extends WP_UnitTestCase {

	public function tearDown(): void {
		parent::tearDown();
		delete_option( 'wpmudev_posts_maintenance_job' );
		delete_option( 'wpmudev_posts_maintenance_settings' );
		delete_option( 'wpmudev_posts_maintenance_last_run' );
		wp_clear_scheduled_hook( \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::DAILY_HOOK );
	}

	public function test_run_manual_scan_updates_meta() {
		$post_ids = self::factory()->post->create_many( 3 );

		$manager = \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::instance();
		$processed = $manager->run_manual_scan( array( 'post' ) );

		$this->assertSame( 3, $processed );

		foreach ( $post_ids as $post_id ) {
			$this->assertNotEmpty( get_post_meta( $post_id, 'wpmudev_test_last_scan', true ) );
		}
	}

	public function test_manual_scan_respects_post_types() {
		register_post_type( 'book', array( 'public' => true, 'label' => 'Books' ) );

		$post_id = self::factory()->post->create();
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$book_id = self::factory()->post->create( array( 'post_type' => 'book' ) );

		$manager = \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::instance();
		$manager->run_manual_scan( array( 'page' ) );
		unregister_post_type( 'book' );

		$this->assertEmpty( get_post_meta( $post_id, 'wpmudev_test_last_scan', true ) );
		$this->assertNotEmpty( get_post_meta( $page_id, 'wpmudev_test_last_scan', true ) );
		$this->assertEmpty( get_post_meta( $book_id, 'wpmudev_test_last_scan', true ) );
	}

	public function test_start_job_creates_state() {
		$manager = \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::instance();
		$job = $manager->start_job( array( 'post' ) );

		$this->assertArrayHasKey( 'job_id', $job );
		$this->assertSame( 'pending', $job['status'] );
	}

	public function test_save_settings_sanitizes_values_and_schedules_cron() {
		$manager = \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::instance();
		register_post_type( 'book', array( 'public' => true, 'label' => 'Books' ) );

		$manager->save_settings( array( 'book', 'invalid_type' ), true, '27:72' );
		$settings = $manager->get_settings();

		$this->assertSame( array( 'book' ), $settings['post_types'] );
		$this->assertSame( '23:59', $settings['cron_time'] );
		$this->assertSame( array( '23:59' ), $settings['cron_times'] );
		$this->assertNotFalse( wp_next_scheduled( \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::DAILY_HOOK, array( 'slot' => '23:59' ) ) );

		unregister_post_type( 'book' );
	}

	public function test_disabling_cron_clears_schedule() {
		$manager = \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::instance();

		$manager->save_settings( array( 'post' ), true, '04:00', array( '04:00', '16:00' ) );
		$this->assertNotFalse( wp_next_scheduled( \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::DAILY_HOOK, array( 'slot' => '04:00' ) ) );
		$this->assertNotFalse( wp_next_scheduled( \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::DAILY_HOOK, array( 'slot' => '16:00' ) ) );

		$manager->save_settings( array( 'post' ), false, '04:00' );
		$this->assertFalse( wp_next_scheduled( \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::DAILY_HOOK ) );
	}

	public function test_multiple_cron_times_schedule_events() {
		$manager = \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::instance();

		$manager->save_settings( array( 'post' ), true, '01:00', array( '01:00', '13:30' ) );

		$this->assertNotFalse( wp_next_scheduled( \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::DAILY_HOOK, array( 'slot' => '01:00' ) ) );
		$this->assertNotFalse( wp_next_scheduled( \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::DAILY_HOOK, array( 'slot' => '13:30' ) ) );
	}

	public function test_cancel_job_marks_cancelling() {
		$manager = \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::instance();

		update_option(
			\WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::JOB_OPTION,
			array(
				'job_id'      => 'demo',
				'post_types'  => array( 'post' ),
				'total'       => 10,
				'processed'   => 2,
				'status'      => 'running',
				'cancel_requested' => false,
				'batch'       => array(
					'page'     => 1,
					'per_page' => 25,
				),
			)
		);

		$job = $manager->cancel_job();

		$this->assertTrue( $job['cancel_requested'] );
		$this->assertSame( 'cancelling', $job['status'] );
	}

	public function test_process_queue_honors_cancel_request() {
		$manager = \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::instance();

		update_option(
			\WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::JOB_OPTION,
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

		$manager->process_queue();
		$job = get_option( \WPMUDEV\PluginTest\App\Posts_Maintenance\Manager::JOB_OPTION );

		$this->assertSame( 'cancelled', $job['status'] );
		$this->assertFalse( $job['cancel_requested'] );
	}
}

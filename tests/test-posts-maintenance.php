<?php

class Test_Posts_Maintenance extends WP_UnitTestCase {

	public function tearDown(): void {
		parent::tearDown();
		delete_option( 'wpmudev_posts_maintenance_job' );
		delete_option( 'wpmudev_posts_maintenance_settings' );
		delete_option( 'wpmudev_posts_maintenance_last_run' );
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
}

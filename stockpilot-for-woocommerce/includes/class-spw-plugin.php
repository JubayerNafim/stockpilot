<?php
/**
 * Core plugin singleton: wiring, tables, schedules.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Plugin {

	const QUEUE_TABLE = 'spw_outbound_queue';
	const LOG_TABLE   = 'spw_sync_log';
	const OPTIONS_KEY = 'spw_options';

	/** @var SPW_Plugin|null */
	private static $instance = null;

	/** @return SPW_Plugin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Load every plugin class. Must be callable from the activation hook too
	 * (before WooCommerce is available), which is why it is not just in the
	 * constructor.
	 */
	public static function load_classes() {
		require_once SPW_DIR . 'includes/class-spw-encryption.php';
		require_once SPW_DIR . 'includes/class-spw-settings.php';
		require_once SPW_DIR . 'includes/class-spw-payload.php';
		require_once SPW_DIR . 'includes/class-spw-queue.php';
		require_once SPW_DIR . 'includes/class-spw-sender.php';
		require_once SPW_DIR . 'includes/class-spw-capture.php';
		require_once SPW_DIR . 'includes/class-spw-cron.php';
		require_once SPW_DIR . 'admin/class-spw-admin.php';
	}

	private function __construct() {
		self::load_classes();

		SPW_Settings::instance();
		SPW_Capture::instance();
		SPW_Cron::instance();
		SPW_Admin::instance();
	}

	/** @return string */
	public static function queue_table() {
		global $wpdb;
		return $wpdb->prefix . self::QUEUE_TABLE;
	}

	/** @return string */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . self::LOG_TABLE;
	}

	/** Create the custom tables. */
	public static function install_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$queue = "CREATE TABLE {$wpdb->prefix}" . self::QUEUE_TABLE . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			payload_id CHAR(36) NOT NULL,
			entity_id VARCHAR(64) NULL,
			event_id VARCHAR(64) NOT NULL,
			event_type VARCHAR(32) NOT NULL,
			payload LONGTEXT NOT NULL,
			nonce VARCHAR(64) NOT NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			max_attempts INT UNSIGNED NOT NULL DEFAULT 12,
			next_attempt_at DATETIME NULL,
			locked_until DATETIME NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			last_error TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY payload_id (payload_id),
			KEY event_id (event_id),
			KEY status_next (status, next_attempt_at),
			KEY entity (event_type, entity_id)
		) $charset;";

		$log = "CREATE TABLE {$wpdb->prefix}" . self::LOG_TABLE . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id VARCHAR(64) NOT NULL,
			entity_type VARCHAR(16) NOT NULL,
			entity_id VARCHAR(64) NULL,
			payload_hash CHAR(64) NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'sent',
			pushed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			date_modified DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			KEY entity (entity_type, entity_id)
		) $charset;";

		dbDelta( $queue );
		dbDelta( $log );
	}

	public static function install_schedules() {
		if ( ! wp_next_scheduled( 'spw_process_queue' ) ) {
			wp_schedule_event( time() + 60, 'spw_every_minute', 'spw_process_queue' );
		}
		if ( ! wp_next_scheduled( 'spw_reconcile' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'spw_reconcile' );
		}
	}

	public static function clear_schedules() {
		wp_clear_scheduled_hook( 'spw_process_queue' );
		wp_clear_scheduled_hook( 'spw_reconcile' );
	}

	/** On activation, push the full product catalog to seed the portal. */
	public static function queue_catalog_sync() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		SPW_Payload::enqueue_catalog();
	}

	/** Add the custom 1-minute cron interval. */
	public static function register_interval( $schedules ) {
		$schedules['spw_every_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every minute', 'stockpilot-wc' ),
		);
		return $schedules;
	}
}
add_filter( 'cron_schedules', array( 'SPW_Plugin', 'register_interval' ) );

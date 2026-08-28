<?php
/**
 * Admin settings screen: connection config + health + test connection.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Admin {

	/** @var SPW_Admin|null */
	private static $instance = null;

	/** @return SPW_Admin */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_notices', array( $this, 'print_notices' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'StockPilot', 'stockpilot-wc' ),
			__( 'StockPilot', 'stockpilot-wc' ),
			'manage_options',
			'stockpilot',
			array( $this, 'render_page' ),
			'dashicons-admin-site-alt3',
			56
		);
	}

	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$has_action = isset( $_POST['spw_save'] )
			|| isset( $_POST['spw_sync_products'] )
			|| isset( $_POST['spw_sync_orders'] )
			|| isset( $_POST['spw_sync_orders_force'] );

		if ( ! $has_action ) {
			return;
		}

		check_admin_referer( 'spw_settings' );

		// Manual sync buttons (work even when WP-Cron is disabled).
		if ( isset( $_POST['spw_sync_products'] ) ) {
			SPW_Payload::enqueue_catalog();
			SPW_Queue::process();
			add_settings_error( 'spw', 'spw_sync', __( 'Product catalog sync sent.', 'stockpilot-wc' ), 'success' );
			return;
		}

		if ( isset( $_POST['spw_sync_orders'] ) ) {
			SPW_Cron::sync_orders_now();
			SPW_Queue::process();
			add_settings_error( 'spw', 'spw_sync', __( 'Order sync sent.', 'stockpilot-wc' ), 'success' );
			return;
		}

		if ( isset( $_POST['spw_sync_orders_force'] ) ) {
			// Re-push EVERY order with a fresh event id, so orders that were
			// deleted from the portal (or never made it) are re-imported.
			SPW_Cron::sync_orders_now( true );
			SPW_Queue::process();
			add_settings_error( 'spw', 'spw_sync', __( 'All orders re-synced (forced).', 'stockpilot-wc' ), 'success' );
			return;
		}

		$portal_url = isset( $_POST['spw_portal_url'] ) ? esc_url_raw( wp_unslash( $_POST['spw_portal_url'] ) ) : '';
		$api_key    = isset( $_POST['spw_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['spw_api_key'] ) ) : '';
		$api_secret = isset( $_POST['spw_api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['spw_api_secret'] ) ) : '';

		$save_values = array(
			'portal_url' => untrailingslashit( $portal_url ),
			'api_key'    => $api_key,
			'disabled'   => 'no',
		);

		// If the secret field still shows the masked placeholder, keep the stored secret.
		if ( '' !== $api_secret && 0 !== strpos( $api_secret, '••' ) ) {
			$save_values['api_secret'] = $api_secret;
		}

		SPW_Settings::instance()->save( $save_values );

		$tested = SPW_Sender::test_connection();

		if ( $tested['ok'] ) {
			// First successful connection: push the whole product catalog and any
			// pending events right away, so the portal populates without waiting
			// for WP-Cron.
			SPW_Payload::enqueue_catalog();
			SPW_Queue::process();
		}

		add_settings_error(
			'spw',
			'spw_saved',
			$tested['ok'] ? __( 'Settings saved and connection verified. Product catalog sync sent.', 'stockpilot-wc' ) : __( 'Settings saved, but the connection test failed: ', 'stockpilot-wc' ) . $tested['message'],
			$tested['ok'] ? 'success' : 'error'
		);
	}

	public function print_notices() {
		$queue_dead = $this->dead_count();

		if ( $queue_dead > 0 ) {
			echo '<div class="notice notice-warning"><p>StockPilot: ' . esc_html( $queue_dead ) . ' event(s) failed to sync and are waiting for attention.</p></div>';
		}

		if ( SPW_Settings::instance()->is_connected() && ! $this->heartbeat_recent() ) {
			echo '<div class="notice notice-info"><p>StockPilot is configured but the portal has not confirmed a connection yet. Use the Test Connection button.</p></div>';
		}
	}

	public function render_page() {
		$settings = SPW_Settings::instance();
		$all      = $settings->all();
		$status   = $this->status_summary();

		include SPW_DIR . 'admin/views/settings.php';
	}

	/** @return array */
	public function status_summary() {
		global $wpdb;

		return array(
			'connected'      => SPW_Settings::instance()->is_connected(),
			'queue_pending'  => SPW_Queue::pending_count(),
			'dead'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . SPW_Plugin::queue_table() . " WHERE status='dead'" ),
			'last_sent'      => $wpdb->get_var( "SELECT MAX(pushed_at) FROM " . SPW_Plugin::log_table() ),
			'last_synced'    => get_option( 'spw_last_order_reconcile', '—' ),
		);
	}

	private function dead_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . SPW_Plugin::queue_table() . " WHERE status='dead'" );
	}

	private function heartbeat_recent() {
		return ! empty( $this->status_summary()['last_sent'] );
	}

	/** Queued admin notice (e.g. connection disabled, sync failing). */
	public static function notify( $title, $message ) {
		set_transient(
			'spw_admin_notice',
			array( 'title' => $title, 'message' => $message ),
			7 * DAY_IN_SECONDS
		);
	}
}

<?php
/**
 * Scheduled jobs: drain the queue and reconcile orders/products from the WP DB
 * (safety net for missed hooks). Reconciliation is read-only on Woo data.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Cron {

	/** @var SPW_Cron|null */
	private static $instance = null;

	/** @return SPW_Cron */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'spw_process_queue', array( 'SPW_Queue', 'process' ) );
		add_action( 'spw_reconcile', array( $this, 'reconcile' ) );
	}

	/**
	 * Hourly safety net: enqueue any order/product whose last payload differs
	 * from what we already pushed (matched by post_modified date).
	 */
	public function reconcile() {
		if ( ! SPW_Settings::instance()->is_connected() ) {
			return;
		}

		$this->reconcile_orders();
		$this->reconcile_products();
	}

	private function reconcile_orders() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$last = (string) get_option( 'spw_last_order_reconcile', date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3600 ) );

		// wc_get_orders works with both classic CPT storage and High-Performance
		// Order Storage (HPOS); the queue dedups unchanged orders by event_id.
		$order_ids = wc_get_orders( array(
			'limit'         => -1,
			'type'          => 'shop_order',
			'status'        => array_keys( wc_get_order_statuses() ),
			'date_modified' => '>=' . $last,
			'return'        => 'ids',
		) );

		foreach ( $order_ids as $order_id ) {
			SPW_Payload::enqueue_order( (int) $order_id, 'reconcile' );
		}

		update_option( 'spw_last_order_reconcile', current_time( 'mysql' ) );
	}

	private function reconcile_products() {
		global $wpdb;

		$last = (string) get_option( 'spw_last_product_reconcile', date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 3600 ) );

		$product_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'product'
			   AND post_modified_gmt > %s",
			gmdate( 'Y-m-d H:i:s', strtotime( $last ) )
		) );

		foreach ( $product_ids as $product_id ) {
			SPW_Payload::enqueue_product( (int) $product_id );
		}

		update_option( 'spw_last_product_reconcile', current_time( 'mysql' ) );
	}

	/**
	 * Manual "sync orders now": enqueue every order. Unchanged orders are
	 * deduplicated by the sync log, so this only pushes new/changed ones —
	 * unless $force is true, which mints fresh event ids and re-imports every
	 * order (use it to pull back orders that were deleted from the portal).
	 *
	 * @param bool $force
	 */
	public static function sync_orders_now( $force = false ) {
		if ( ! SPW_Settings::instance()->is_connected() ) {
			return;
		}

		if ( ! function_exists( 'wc_get_order_statuses' ) ) {
			return;
		}

		// wc_get_orders works with both classic CPT storage and HPOS; the queue
		// dedups unchanged orders by event_id unless forced.
		$order_ids = wc_get_orders( array(
			'limit'  => -1,
			'type'   => 'shop_order',
			'status' => array_keys( wc_get_order_statuses() ),
			'return' => 'ids',
		) );

		foreach ( $order_ids as $order_id ) {
			SPW_Payload::enqueue_order( (int) $order_id, 'manual', $force );
		}
	}
}

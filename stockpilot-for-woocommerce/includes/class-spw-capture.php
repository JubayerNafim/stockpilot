<?php
/**
 * Captures WooCommerce order + product events and enqueues them for sync.
 * READ-ONLY: never writes order/product data, never touches stock functions,
 * never calls $order->update_status().
 */

defined( 'ABSPATH' ) || exit;

class SPW_Capture {

	/** @var SPW_Capture|null */
	private static $instance = null;

	/** @return SPW_Capture */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Orders
		add_action( 'woocommerce_new_order', array( $this, 'on_order_new' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 1 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'on_order_refunded' ), 10, 1 );
		add_action( 'woocommerce_rest_insert_shop_order', array( $this, 'on_rest_order' ), 10, 1 );

		// Products
		add_action( 'woocommerce_new_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'woocommerce_rest_insert_product_object', array( $this, 'on_product_saved' ), 10, 1 );
		add_action( 'trashed_post', array( $this, 'on_post_trashed' ), 10, 1 );
		add_action( 'untrashed_post', array( $this, 'on_post_untrashed' ), 10, 1 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ), 10, 1 );
	}

	public function on_order_new( $order_id ) {
		SPW_Payload::enqueue_order( $order_id, 'new_order' );
	}

	public function on_order_status_changed( $order_id, $old_status, $new_status ) {
		SPW_Payload::enqueue_order( $order_id, "status:$old_status->$new_status" );
	}

	public function on_order_refunded( $order_id ) {
		SPW_Payload::enqueue_order( $order_id, 'refunded' );
	}

	public function on_rest_order( $order ) {
		SPW_Payload::enqueue_order( $order->get_id(), 'rest' );
	}

	public function on_product_saved( $product_id ) {
		if ( $this->is_product( $product_id ) ) {
			SPW_Payload::enqueue_product( (int) $product_id );
		}
	}

	public function on_post_trashed( $post_id ) {
		if ( $this->is_product( $post_id ) ) {
			SPW_Payload::enqueue_product( (int) $post_id );
		}
	}

	public function on_post_untrashed( $post_id ) {
		if ( $this->is_product( $post_id ) ) {
			SPW_Payload::enqueue_product( (int) $post_id );
		}
	}

	public function on_post_deleted( $post_id ) {
		if ( $this->is_product( $post_id ) ) {
			SPW_Payload::enqueue_product( (int) $post_id, true );
		}
	}

	private function is_product( $post_id ) {
		return 'product' === get_post_type( $post_id );
	}
}

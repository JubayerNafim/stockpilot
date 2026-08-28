<?php
/**
 * Builds the JSON payloads sent to the portal and enqueues events.
 * This class is strictly read-only on WooCommerce data.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Payload {

	/**
	 * @param int $order_id
	 * @return array The full order payload (wrapped in an 'order' key).
	 */
	public static function order_payload( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return null;
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$qty     = (float) $item->get_quantity();
			$items[] = array(
				'id'         => (int) $item->get_id(),
				'product_id' => (int) $item->get_product_id(),
				'name'       => $item->get_name(),
				'sku'        => $product ? (string) $product->get_sku() : '',
				'quantity'   => $qty,
				'price'      => $qty > 0 ? round( (float) $item->get_subtotal() / $qty, 4 ) : 0.0,
				'line_total' => (float) $item->get_total(),
			);
		}

		$refunds = array();
		foreach ( $order->get_refunds() as $refund ) {
			$refund_items = array();
			foreach ( $refund->get_items() as $refund_item ) {
				$refund_items[] = array(
					'item_id'  => (int) $refund_item->get_meta( '_refunded_item_id', true ),
					'quantity' => abs( (float) $refund_item->get_quantity() ),
					'total'    => abs( (float) $refund_item->get_total() ),
				);
			}
			$refunds[] = array(
				'id'     => (int) $refund->get_id(),
				'reason' => $refund->get_reason(),
				'total'  => abs( (float) $refund->get_amount() ),
				'items'  => $refund_items,
			);
		}

		return array(
			'order' => array(
				'id'            => (int) $order->get_id(),
				'number'        => (string) $order->get_order_number(),
				'status'        => $order->get_status(),
				'date_created'  => self::iso( $order->get_date_created() ),
				'date_modified' => self::iso( $order->get_date_modified() ),
				'customer'      => array(
					'name'  => $order->get_formatted_billing_full_name(),
					'email' => $order->get_billing_email(),
				),
				'currency'      => $order->get_currency(),
				'totals'        => array(
					'subtotal'       => (float) $order->get_subtotal(),
					'discount_total' => (float) $order->get_discount_total(),
					'shipping_total' => (float) $order->get_shipping_total(),
					'tax_total'      => (float) $order->get_total_tax(),
					'total'          => (float) $order->get_total(),
					'refund_total'   => abs( (float) $order->get_total_refunded() ),
				),
				'items'         => $items,
				'refunds'       => $refunds,
			),
		);
	}

	/**
	 * @param int    $product_id
	 * @param bool   $delete   true → only id/date_modified needed
	 * @return array
	 */
	public static function product_payload( $product_id, $delete = false ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return null;
		}

		if ( $delete ) {
			return array(
				'product' => array(
					'id'            => (int) $product->get_id(),
					'date_modified' => self::post_iso( $product_id ),
				),
				'delete'  => true,
			);
		}

		$categories = array();
		foreach ( wp_get_post_terms( $product->get_id(), 'product_cat' ) as $term ) {
			if ( is_wp_error( $term ) || ! is_object( $term ) ) {
				continue;
			}
			$categories[] = array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			);
		}

		return array(
			'product' => array(
				'id'             => (int) $product->get_id(),
				'name'           => $product->get_name(),
				'slug'           => $product->get_slug(),
				'sku'            => (string) $product->get_sku(),
				'type'           => $product->get_type(),
				'status'         => get_post_status( $product->get_id() ),
				'price'          => (float) $product->get_price(),
				'regular_price'  => '' !== $product->get_regular_price() ? (float) $product->get_regular_price() : null,
				'sale_price'     => '' !== $product->get_sale_price() ? (float) $product->get_sale_price() : null,
				'stock_status'   => $product->get_stock_status(),
				'stock_quantity' => null !== $product->get_stock_quantity() ? (float) $product->get_stock_quantity() : null,
				'categories'     => $categories,
				'image'          => $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'full' ) : null,
				'date_modified'  => self::post_iso( $product_id ),
			),
		);
	}

	/**
	 * Enqueue an order event if it isn't already in the sync log.
	 *
	 * @param int    $order_id
	 * @param string $source   hook name for debugging
	 * @param bool   $force    true → mint a fresh event_id so the order is pushed
	 *                         again even if unchanged (re-imports orders that were
	 *                         deleted from the portal; bypasses the plugin sync-log
	 *                         and the portal's webhook dedup)
	 */
	public static function enqueue_order( $order_id, $source = 'hook', $force = false ) {
		if ( ! SPW_Settings::instance()->is_connected() ) {
			return;
		}

		$payload = self::order_payload( (int) $order_id );

		if ( ! $payload ) {
			return;
		}

		$order = $payload['order'];

		// Woo fires woocommerce_new_order before line items are attached, so the
		// first event for a brand-new order can have an empty items list. A
		// payload without items would create an empty order row in the portal;
		// skip it and let the status-change / reconcile events (which carry the
		// full order) create it properly.
		if ( empty( $order['items'] ) ) {
			return;
		}

		$event_id = 'ord-' . md5( $order['id'] . '|' . $order['status'] . '|' . (string) $order['date_modified'] );

		if ( $force ) {
			$event_id .= '|' . current_time( 'timestamp' ) . '|' . wp_rand( 10000, 99999 );
		}

		SPW_Queue::enqueue( array(
			'entity_id'  => (string) $order['id'],
			'event_id'   => $event_id,
			'event_type' => 'order.sync',
			'payload'    => wp_json_encode( $payload ),
			'date_modified' => $order['date_modified'],
		) );
	}

	/**
	 * Enqueue a product catalog event.
	 *
	 * @param int  $product_id
	 * @param bool $delete
	 */
	public static function enqueue_product( $product_id, $delete = false ) {
		if ( ! SPW_Settings::instance()->is_connected() ) {
			return;
		}

		$payload = self::product_payload( (int) $product_id, $delete );

		if ( ! $payload ) {
			return;
		}

		$product = $payload['product'];
		$event_id = 'prod-' . md5( $product['id'] . '|' . (string) $product['date_modified'] );

		SPW_Queue::enqueue( array(
			'entity_id'  => (string) $product['id'],
			'event_id'   => $event_id,
			'event_type' => $delete ? 'product.delete' : 'product.sync',
			'payload'    => wp_json_encode( $payload ),
			'date_modified' => $product['date_modified'],
		) );
	}

	/** Queue the whole product catalog (used on activation). */
	public static function enqueue_catalog() {
		if ( ! SPW_Settings::instance()->is_connected() ) {
			return;
		}

		// Include variations so order lines for variable products map by SKU /
		// variation id (variation line items carry the variation id, not the parent's).
		$products = get_posts( array(
			'post_type'   => array( 'product', 'product_variation' ),
			'numberposts' => -1,
			'post_status' => array( 'publish', 'draft', 'private' ),
			'fields'      => 'ids',
		) );

		foreach ( $products as $product_id ) {
			self::enqueue_product( (int) $product_id );
		}
	}

	/** @return string|null ISO-8601 from a WC date object. */
	private static function iso( $date ) {
		if ( ! $date ) {
			return null;
		}
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/** @return string|null ISO-8601 from a post's modified time (UTC). */
	private static function post_iso( $post_id ) {
		$modified = get_post_modified_time( 'Y-m-d\TH:i:s\Z', true, $post_id );
		return $modified ? $modified : null;
	}
}

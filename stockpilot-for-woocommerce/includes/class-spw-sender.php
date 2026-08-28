<?php
/**
 * HTTPS + HMAC-SHA256 signed HTTP client for the portal API.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Sender {

	/**
	 * @param string $event_type order.sync | product.sync | product.delete
	 * @param string $event_id
	 * @param string $nonce
	 * @param string $body_json
	 * @return array { code, body }
	 */
	public static function send( $event_type, $event_id, $nonce, $body_json ) {
		$settings = SPW_Settings::instance();

		$portal_url = untrailingslashit( $settings->get( 'portal_url' ) );
		$api_key    = $settings->get( 'api_key' );
		$secret     = $settings->get( 'api_secret' );

		if ( '' === $portal_url || '' === $api_key || '' === $secret ) {
			return array( 'code' => 0, 'body' => 'Not configured' );
		}

		if ( 0 !== stripos( $portal_url, 'https://' ) && ! defined( 'SPW_ALLOW_HTTP' ) ) {
			return array( 'code' => 0, 'body' => 'Portal URL must use HTTPS' );
		}

		$endpoint = 0 === strpos( $event_type, 'prod' ) ? '/api/webhooks/products' : '/api/webhooks/orders';
		$url      = $portal_url . $endpoint;

		$timestamp = (string) time();
		$signature = base64_encode(
			hash_hmac( 'sha256', $timestamp . ':' . $nonce . ':' . $event_id . ':' . $body_json, $secret, true )
		);

		$response = wp_remote_post( $url, array(
			'timeout'     => 15,
			'redirection' => 3,
			'httpversion' => '1.1',
			'sslverify'   => true,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'X-Api-Key'    => $api_key,
				'X-Timestamp'  => $timestamp,
				'X-Nonce'      => $nonce,
				'X-Event-Id'   => $event_id,
				'X-Signature'  => $signature,
			),
			'body'        => $body_json,
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'code' => 0, 'body' => $response->get_error_message() );
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Test the connection against the portal's test endpoint.
	 *
	 * @return array { ok, message }
	 */
	public static function test_connection() {
		$settings = SPW_Settings::instance();
		$portal_url = untrailingslashit( $settings->get( 'portal_url' ) );

		if ( '' === $portal_url ) {
			return array( 'ok' => false, 'message' => 'Enter the portal URL first.' );
		}

		$event_id = 'test-' . wp_generate_password( 8, false );
		$nonce    = bin2hex( random_bytes( 16 ) );
		$timestamp = (string) time();

		// The test endpoint is a GET, so no body is sent — the portal verifies
		// the HMAC over an empty body (trailing colon, nothing after it).
		$url      = $portal_url . '/api/webhooks/test';
		$signature = base64_encode(
			hash_hmac( 'sha256', $timestamp . ':' . $nonce . ':' . $event_id . ':', $settings->get( 'api_secret' ), true )
		);

		$response = wp_remote_get( $url, array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array(
				'X-Api-Key'    => $settings->get( 'api_key' ),
				'X-Timestamp'  => $timestamp,
				'X-Nonce'      => $nonce,
				'X-Event-Id'   => $event_id,
				'X-Signature'  => $signature,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 === $code ) {
			return array( 'ok' => true, 'message' => 'Connected successfully. Server responded: ' . $body );
		}

		return array( 'ok' => false, 'message' => "Portal returned HTTP {$code}: {$body}" );
	}
}

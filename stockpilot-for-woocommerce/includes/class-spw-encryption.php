<?php
/**
 * AES-256-CBC encryption for the API secret at rest.
 * Key is derived from WP_AUTH_KEY / WP_AUTH_SALT so it is site-specific.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Encryption {

	/**
	 * @param string $plaintext
	 * @return string base64(iv + ciphertext + mac)
	 */
	public static function encrypt( $plaintext ) {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
			return $plaintext;
		}

		$key = hash( 'sha256', AUTH_KEY . AUTH_SALT, true );
		$iv  = random_bytes( 16 );
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $ciphertext, $key, true );

		return base64_encode( $iv . $mac . $ciphertext );
	}

	/**
	 * @param string $payload
	 * @return string
	 */
	public static function decrypt( $payload ) {
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'AUTH_SALT' ) ) {
			return $payload;
		}

		$key   = hash( 'sha256', AUTH_KEY . AUTH_SALT, true );
		$raw   = base64_decode( $payload, true );

		if ( false === $raw || strlen( $raw ) < 48 ) {
			return '';
		}

		$iv  = substr( $raw, 0, 16 );
		$mac = substr( $raw, 16, 32 );
		$ciphertext = substr( $raw, 48 );
		$calc = hash_hmac( 'sha256', $iv . $ciphertext, $key, true );

		if ( ! hash_equals( $calc, $mac ) ) {
			return '';
		}

		$plain = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}
}

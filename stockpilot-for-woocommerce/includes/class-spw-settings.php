<?php
/**
 * Settings storage: portal URL + API key (plain) + API secret (encrypted).
 */

defined( 'ABSPATH' ) || exit;

class SPW_Settings {

	const PREFIX = 'spw_';

	/** @var SPW_Settings|null */
	private static $instance = null;

	/** @return SPW_Settings */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** @return array Default settings. */
	public function defaults() {
		return array(
			'portal_url' => '',
			'api_key'    => '',
			'api_secret' => '',
			'disabled'   => 'no',
		);
	}

	/** @return array Decrypted settings. */
	public function all() {
		$saved = get_option( self::PREFIX . 'settings', array() );
		$saved = is_array( $saved ) ? $saved : array();
		$merged = wp_parse_args( $saved, $this->defaults() );

		if ( ! empty( $merged['api_secret'] ) ) {
			$stored = $merged['api_secret'];

			// Values saved by this plugin carry a 'spw1:' version prefix.
			if ( 0 === strpos( $stored, 'spw1:' ) ) {
				$stored = substr( $stored, 5 );
			}

			$merged['api_secret'] = SPW_Encryption::decrypt( $stored );
		}

		return $merged;
	}

	/** @return string */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	/** Persist settings, encrypting the secret. */
	public function save( array $values ) {
		$current = get_option( self::PREFIX . 'settings', array() );
		$current = is_array( $current ) ? $current : array();

		$new = wp_parse_args( $values, $current );

		if ( isset( $new['api_secret'] ) && '' !== $new['api_secret'] && 0 !== strpos( $new['api_secret'], 'spw1:' ) ) {
			$new['api_secret'] = 'spw1:' . SPW_Encryption::encrypt( $new['api_secret'] );
		}

		$new = wp_parse_args( $new, $this->defaults() );

		update_option( self::PREFIX . 'settings', $new );
	}

	/** @return bool True when a portal connection is fully configured. */
	public function is_connected() {
		$all = $this->all();
		return 'yes' !== $all['disabled']
			&& '' !== $all['portal_url']
			&& '' !== $all['api_key']
			&& '' !== $all['api_secret'];
	}
}

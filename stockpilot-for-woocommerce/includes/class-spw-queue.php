<?php
/**
 * Reliable outbound queue: dedup, claim, backoff, dead-letter.
 */

defined( 'ABSPATH' ) || exit;

class SPW_Queue {

	/**
	 * Insert a queue row unless this event was already sent (sync log) or is
	 * already queued.
	 *
	 * @param array $args entity_id, event_id, event_type, payload, date_modified
	 */
	public static function enqueue( array $args ) {
		global $wpdb;

		$event_id = $args['event_id'];

		$already_sent = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM " . SPW_Plugin::log_table() . " WHERE event_id = %s LIMIT 1",
			$event_id
		) );

		if ( $already_sent ) {
			return;
		}

		$queued = $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM " . SPW_Plugin::queue_table() . " WHERE event_id = %s AND status IN ('pending','sending') LIMIT 1",
			$event_id
		) );

		if ( $queued ) {
			return;
		}

		$wpdb->insert(
			SPW_Plugin::queue_table(),
			array(
				'payload_id'   => self::uuid(),
				'entity_id'    => $args['entity_id'],
				'event_id'     => $event_id,
				'event_type'   => $args['event_type'],
				'payload'      => $args['payload'],
				'nonce'        => bin2hex( random_bytes( 16 ) ),
				'status'       => 'pending',
				'max_attempts' => 12,
				'next_attempt_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * WP-Cron callback (every minute): claim a batch and send it.
	 */
	public static function process() {
		if ( ! SPW_Settings::instance()->is_connected() ) {
			return;
		}

		$rows = self::claim( 15 );

		foreach ( $rows as $row ) {
			self::send_row( $row );
		}
	}

	/**
	 * Claim up to $limit rows that are due, with a lock to prevent double
	 * delivery from overlapping cron runs.
	 *
	 * @param int $limit
	 * @return array Rows
	 */
	public static function claim( $limit ) {
		global $wpdb;
		$table = SPW_Plugin::queue_table();
		$now   = current_time( 'mysql' );
		$lock  = date( 'Y-m-d H:i:s', strtotime( '+120 seconds', current_time( 'timestamp' ) ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status='sending', locked_until=%s
				 WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
				 LIMIT %d",
				$lock,
				$now,
				$limit
			)
		);

		// Also reclaim rows whose lock expired (previous run died mid-send).
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status='pending', locked_until=NULL
				 WHERE status='sending' AND locked_until IS NOT NULL AND locked_until < %s",
				$now
			)
		);

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE status='sending' AND locked_until >= %s LIMIT %d",
				$now,
				$limit
			),
			ARRAY_A
		);
	}

	/**
	 * Send one row and update its state based on the portal response.
	 */
	private static function send_row( array $row ) {
		$response = SPW_Sender::send(
			$row['event_type'],
			$row['event_id'],
			$row['nonce'],
			$row['payload']
		);

		$code = isset( $response['code'] ) ? (int) $response['code'] : 0;

		if ( 200 === $code ) {
			self::mark_sent( $row, $response );
			return;
		}

		if ( 409 === $code ) {
			// Duplicate already processed by the portal — drop cleanly.
			self::mark_sent( $row, $response );
			return;
		}

		if ( 422 === $code ) {
			self::mark_dead( $row, 'Portal rejected payload (422): ' . substr( (string) $response['body'], 0, 200 ) );
			return;
		}

		if ( 401 === $code || 403 === $code ) {
			// Key revoked or invalid — disable the connection and notify.
			SPW_Settings::instance()->save( array( 'disabled' => 'yes' ) );
			self::mark_dead( $row, 'Portal auth failed (' . $code . '). Connection disabled.' );
			SPW_Admin::notify( 'StockPilot connection disabled', $response['body'] );
			return;
		}

		self::retry( $row, 'HTTP ' . $code . ': ' . substr( (string) $response['body'], 0, 200 ) );
	}

	private static function mark_sent( array $row, array $response ) {
		global $wpdb;
		$table = SPW_Plugin::queue_table();

		$wpdb->update( $table, array( 'status' => 'done' ), array( 'id' => $row['id'] ), array( '%s' ), array( '%d' ) );

		$wpdb->replace(
			SPW_Plugin::log_table(),
			array(
				'event_id'      => $row['event_id'],
				'entity_type'   => 0 === strpos( $row['event_type'], 'prod' ) ? 'product' : 'order',
				'entity_id'     => $row['entity_id'],
				'payload_hash'  => hash( 'sha256', $row['payload'] ),
				'status'        => 'sent',
				'date_modified' => isset( $row['date_modified'] ) ? $row['date_modified'] : current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	private static function retry( array $row, $error ) {
		global $wpdb;
		$table = SPW_Plugin::queue_table();

		$attempts = (int) $row['attempts'] + 1;
		$max      = (int) $row['max_attempts'];

		if ( $attempts >= $max ) {
			$wpdb->update(
				$table,
				array( 'status' => 'dead', 'attempts' => $attempts, 'last_error' => $error ),
				array( 'id' => $row['id'] ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);
			SPW_Admin::notify( 'StockPilot sync failing', $error );
			return;
		}

		$delay   = min( 600, 15 * pow( 2, $attempts - 1 ) ) + wp_rand( 0, 5 );
		$next_at = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $delay );

		$wpdb->update(
			$table,
			array(
				'status'          => 'pending',
				'attempts'        => $attempts,
				'last_error'      => $error,
				'next_attempt_at' => $next_at,
				'locked_until'    => null,
			),
			array( 'id' => $row['id'] ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	private static function mark_dead( array $row, $error ) {
		global $wpdb;
		$wpdb->update(
			SPW_Plugin::queue_table(),
			array( 'status' => 'dead', 'last_error' => $error ),
			array( 'id' => $row['id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/** @return int Count of rows currently pending/sending. */
	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM " . SPW_Plugin::queue_table() . " WHERE status IN ('pending','sending')"
		);
	}

	/** @return string RFC4122 v4 UUID. */
	private static function uuid() {
		$data = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}

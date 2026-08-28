<?php
/**
 * Settings page view. Data provided by SPW_Admin::render_page().
 *
 * @var array $all     decrypted settings
 * @var array $status  status summary
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
	<h1>StockPilot for WooCommerce</h1>

	<?php settings_errors( 'spw' ); ?>

	<div class="notice notice-info">
		<p>
			<strong>How this works:</strong> StockPilot watches your orders and products and
			forwards them to your inventory portal. It <strong>never modifies</strong> anything in
			WooCommerce — this plugin is a read-only bridge.
		</p>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field( 'spw_settings' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="spw_portal_url">Portal URL</label></th>
				<td>
					<input name="spw_portal_url" id="spw_portal_url" type="url"
						value="<?php echo esc_attr( $all['portal_url'] ); ?>"
						class="regular-text code" placeholder="https://inventory.example.com"
						required />
					<p class="description">Your StockPilot web portal address (HTTPS).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="spw_api_key">API key</label></th>
				<td>
					<input name="spw_api_key" id="spw_api_key" type="text"
						value="<?php echo esc_attr( $all['api_key'] ); ?>"
						class="regular-text code" autocomplete="off" required />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="spw_api_secret">API secret</label></th>
				<td>
					<input name="spw_api_secret" id="spw_api_secret" type="password"
						value="<?php echo esc_attr( '' !== $all['api_secret'] ? '••••••••••••••••' : '' ); ?>"
						class="regular-text code" autocomplete="off" required />
					<p class="description">
						Paste the key &amp; secret from the portal's <em>Stores</em> page. The secret is stored
						encrypted. Leave the secret field as-is to keep the current one.
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save and test connection', 'stockpilot-wc' ), 'primary', 'spw_save' ); ?>
	</form>

	<form method="post" action="" style="margin: 12px 0 24px;">
		<?php wp_nonce_field( 'spw_settings' ); ?>
		<input type="submit" name="spw_sync_products" class="button button-secondary"
			value="<?php esc_attr_e( 'Sync products now', 'stockpilot-wc' ); ?>" />
		<input type="submit" name="spw_sync_orders" class="button button-secondary"
			value="<?php esc_attr_e( 'Sync orders now', 'stockpilot-wc' ); ?>" />
		<input type="submit" name="spw_sync_orders_force" class="button button-secondary"
			value="<?php esc_attr_e( 'Force re-sync all orders', 'stockpilot-wc' ); ?>" onclick="return confirm('Re-push every order to the portal, even unchanged ones? Use this to re-import orders that were deleted from the portal.');" />
		<span class="description">
			<?php esc_html_e( 'Push the catalog / orders to the portal immediately — no WP-Cron needed. "Force" re-imports every order (safe; the portal re-processes idempotently).', 'stockpilot-wc' ); ?>
		</span>
	</form>

	<hr />

	<h2>Connection status</h2>
	<table class="widefat striped" style="max-width: 640px;">
		<tbody>
			<tr>
				<th>Connection</th>
				<td>
					<?php if ( $status['connected'] ) : ?>
						<span style="color: #1e7e34; font-weight: 600;">● Configured</span>
					<?php else : ?>
						<span style="color: #b32d2e; font-weight: 600;">● Not configured</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>Events waiting to send</th>
				<td><?php echo esc_html( $status['queue_pending'] ); ?></td>
			</tr>
			<tr>
				<th>Failed events (dead)</th>
				<td><?php echo esc_html( $status['dead'] ); ?></td>
			</tr>
			<tr>
				<th>Last successful push</th>
				<td><?php echo esc_html( $status['last_sent'] ? $status['last_sent'] : '—' ); ?></td>
			</tr>
			<tr>
				<th>Last reconciliation</th>
				<td><?php echo esc_html( $status['last_synced'] ); ?></td>
			</tr>
		</tbody>
	</table>

	<p class="description">
		Events are drained every minute by WP-Cron, and a full reconciliation of changed
		orders &amp; products runs hourly as a safety net.
		<strong>Tip:</strong> enable a real server cron and set <code>DISABLE_WP_CRON</code> for
		reliable delivery on low-traffic stores.
	</p>
</div>

<?php
/**
 * Plugin Name:       StockPilot for WooCommerce
 * Plugin URI:        https://example.com/stockpilot
 * Description:       Read-only bridge between your WooCommerce store and the StockPilot inventory portal. Pushes orders, order status changes and product catalog data. Never modifies your store.
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            StockPilot
 * License:           GPL-2.0-or-later
 * Text Domain:       stockpilot-wc
 */

defined( 'ABSPATH' ) || exit;

define( 'SPW_VERSION', '1.0.2' );
define( 'SPW_FILE', __FILE__ );
define( 'SPW_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPW_URL', plugin_dir_url( __FILE__ ) );

require_once SPW_DIR . 'includes/class-spw-plugin.php';

/**
 * Boot the plugin once WooCommerce is loaded (it needs WC classes at runtime,
 * but not at activation).
 */
function spw_boot() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return; // WooCommerce not active — stay dormant.
    }

    SPW_Plugin::instance();
}
add_action( 'plugins_loaded', 'spw_boot' );

/**
 * Activation: create tables, seed defaults, register cron schedules.
 */
function spw_activate() {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    require_once SPW_DIR . 'includes/class-spw-plugin.php';

    // Load every class before touching them (activation runs before plugins_loaded).
    SPW_Plugin::load_classes();

    SPW_Plugin::install_tables();
    SPW_Plugin::install_schedules();
    SPW_Plugin::queue_catalog_sync();
}
register_activation_hook( __FILE__, 'spw_activate' );

/**
 * Deactivation: clear cron schedules (keep the data tables so re-activation
 * is seamless).
 */
function spw_deactivate() {
    SPW_Plugin::clear_schedules();
}
register_deactivation_hook( __FILE__, 'spw_deactivate' );

<?php
/**
 * Plugin Name:       Lejek
 * Description:       Produkty z płatnością Stripe, zamówienia na żywo, licznik miejsc i statystyki lejka sprzedażowego.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Text Domain:       lejek
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'LEJEK_VERSION', '0.1.0' );
define( 'LEJEK_FILE', __FILE__ );
define( 'LEJEK_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEJEK_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'crypto', 'db', 'settings', 'stripe', 'products', 'orders', 'events', 'checkout', 'webhook', 'frontend', 'admin' ) as $lejek_part ) {
	require_once LEJEK_DIR . 'includes/class-lejek-' . $lejek_part . '.php';
}
unset( $lejek_part );

register_activation_hook( __FILE__, array( 'Lejek_DB', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		Lejek_DB::maybe_upgrade();
		Lejek_Settings::init();
		Lejek_Products::init();
		Lejek_Orders::init();
		Lejek_Events::init();
		Lejek_Checkout::init();
		Lejek_Webhook::init();
		Lejek_Frontend::init();
		Lejek_Admin::init();
	}
);

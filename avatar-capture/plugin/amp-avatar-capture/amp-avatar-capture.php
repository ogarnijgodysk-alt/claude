<?php
/**
 * Plugin Name:       AI Models Partners – Avatar Capture
 * Description:       Nagrywanie ujęć do avatarów AI. Wersja testowa: strona /record-test do sprawdzenia kamery, nagrywania i wyciszania mikrofonu podczas pytań.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            AI Models Partners
 * License:           GPL-2.0-or-later
 * Text Domain:       amp-avatar-capture
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AMPAC_VERSION', '0.1.0' );
define( 'AMPAC_FILE', __FILE__ );
define( 'AMPAC_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Adres /record-test kieruje do strony testowej wtyczki.
 */
function ampac_add_rewrite_rules() {
	add_rewrite_rule( '^record-test/?$', 'index.php?ampac_test=1', 'top' );
}
add_action( 'init', 'ampac_add_rewrite_rules' );

function ampac_query_vars( $vars ) {
	$vars[] = 'ampac_test';
	return $vars;
}
add_filter( 'query_vars', 'ampac_query_vars' );

function ampac_activate() {
	ampac_add_rewrite_rules();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'ampac_activate' );

function ampac_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ampac_deactivate' );

/**
 * Adres strony testowej – działa z ładnymi linkami i bez nich.
 */
function ampac_test_url() {
	if ( get_option( 'permalink_structure' ) ) {
		return home_url( '/record-test/' );
	}
	return add_query_arg( 'ampac_test', '1', home_url( '/' ) );
}

/**
 * Wyświetla stronę testową bez motywu WordPressa.
 */
function ampac_render_test_page() {
	if ( ! get_query_var( 'ampac_test' ) ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Permissions-Policy: camera=(self), microphone=(self)' );
	header( 'X-Robots-Tag: noindex, nofollow', true );

	$ampac_assets = plugins_url( 'assets/', AMPAC_FILE );
	include AMPAC_DIR . 'templates/test-page.php';
	exit;
}
add_action( 'template_redirect', 'ampac_render_test_page', 0 );

/**
 * Link „Otwórz stronę testową” na liście wtyczek.
 */
function ampac_action_links( $links ) {
	$links[] = '<a href="' . esc_url( ampac_test_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Otwórz stronę testową', 'amp-avatar-capture' ) . '</a>';
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ampac_action_links' );

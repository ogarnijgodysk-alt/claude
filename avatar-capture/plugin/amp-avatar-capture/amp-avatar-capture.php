<?php
/**
 * Plugin Name:       AI Models Partners – Avatar Capture
 * Description:       Nagrywanie ujęć klientów do avatarów AI (HeyGen): kontrola kadru, podpowiedzi głosowe, start na hasło, pytania w trakcie nagrania i zapis na Dysk Google. Strona dla klientów: /record, tryb testowy: /record-test.
 * Version:           0.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            AI Models Partners
 * License:           GPL-2.0-or-later
 * Text Domain:       amp-avatar-capture
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AMPAC_VERSION', '0.2.0' );
define( 'AMPAC_FILE', __FILE__ );
define( 'AMPAC_DIR', plugin_dir_path( __FILE__ ) );

require_once AMPAC_DIR . 'includes/settings.php';

/**
 * /record – aplikacja dla klientów, /record-test – ta sama aplikacja z diagnostyką.
 */
function ampac_add_rewrite_rules() {
	add_rewrite_rule( '^record/?$', 'index.php?ampac_app=1', 'top' );
	add_rewrite_rule( '^record-test/?$', 'index.php?ampac_app=1&ampac_debug=1', 'top' );
}
add_action( 'init', 'ampac_add_rewrite_rules' );

/**
 * Po aktualizacji wtyczki (wgranie nowego .zip) odświeża reguły adresów.
 */
function ampac_maybe_flush_rules() {
	if ( get_option( 'ampac_rules_version' ) !== AMPAC_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'ampac_rules_version', AMPAC_VERSION );
	}
}
add_action( 'init', 'ampac_maybe_flush_rules', 20 );

function ampac_query_vars( $vars ) {
	$vars[] = 'ampac_app';
	$vars[] = 'ampac_debug';
	return $vars;
}
add_filter( 'query_vars', 'ampac_query_vars' );

function ampac_activate() {
	ampac_add_rewrite_rules();
	flush_rewrite_rules();
	update_option( 'ampac_rules_version', AMPAC_VERSION );
}
register_activation_hook( __FILE__, 'ampac_activate' );

function ampac_deactivate() {
	flush_rewrite_rules();
	delete_option( 'ampac_rules_version' );
}
register_deactivation_hook( __FILE__, 'ampac_deactivate' );

/**
 * Adres aplikacji – działa z ładnymi linkami i bez nich.
 */
function ampac_app_url( $debug = false ) {
	if ( get_option( 'permalink_structure' ) ) {
		return home_url( $debug ? '/record-test/' : '/record/' );
	}
	$args = array( 'ampac_app' => '1' );
	if ( $debug ) {
		$args['ampac_debug'] = '1';
	}
	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Adresy biblioteki MediaPipe (rozpoznawanie sylwetki w przeglądarce).
 * Do testów lokalnych można je nadpisać stałymi AMPAC_MP_BASE i AMPAC_MP_MODEL.
 */
function ampac_mediapipe_urls() {
	$base  = defined( 'AMPAC_MP_BASE' ) ? AMPAC_MP_BASE : 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@1.2.3';
	$model = defined( 'AMPAC_MP_MODEL' ) ? AMPAC_MP_MODEL : 'https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task';
	return array(
		'bundle' => trailingslashit( $base ) . 'vision_bundle.mjs',
		'wasm'   => trailingslashit( $base ) . 'wasm',
		'model'  => $model,
	);
}

/**
 * Wyświetla aplikację bez motywu WordPressa.
 */
function ampac_render_app() {
	if ( ! get_query_var( 'ampac_app' ) ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	header( 'Permissions-Policy: camera=(self), microphone=(self), accelerometer=(self), gyroscope=(self), screen-wake-lock=(self)' );
	header( 'X-Robots-Tag: noindex, nofollow', true );

	$ampac_debug  = (bool) get_query_var( 'ampac_debug' );
	$ampac_assets = plugins_url( 'assets/', AMPAC_FILE );
	$ampac_config = array(
		'version'   => AMPAC_VERSION,
		'debug'     => $ampac_debug,
		'audioBase' => $ampac_assets . 'audio/',
		'keepAwake' => $ampac_assets . 'keepawake.mp4',
		'scriptUrl' => ampac_get_script_url(),
		'minSec'    => (int) get_option( 'ampac_min_sec', 120 ),
		'maxSec'    => (int) get_option( 'ampac_max_sec', 300 ),
		'mp'        => ampac_mediapipe_urls(),
	);
	include AMPAC_DIR . 'templates/app.php';
	exit;
}
add_action( 'template_redirect', 'ampac_render_app', 0 );

/**
 * Linki na liście wtyczek.
 */
function ampac_action_links( $links ) {
	array_unshift(
		$links,
		'<a href="' . esc_url( admin_url( 'options-general.php?page=ampac' ) ) . '">' . esc_html__( 'Ustawienia', 'amp-avatar-capture' ) . '</a>',
		'<a href="' . esc_url( ampac_app_url( true ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Tryb testowy', 'amp-avatar-capture' ) . '</a>'
	);
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ampac_action_links' );

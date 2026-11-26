<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zdarzenia do statystyk: wejście na stronę (z przeglądarki), rozpoczęcie płatności i zakup (z serwera).
 */
class Lejek_Events {

	const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse|pingdom|uptime/i';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'lejek/v1',
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'track' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function track( WP_REST_Request $request ) {
		$none = new WP_REST_Response( null, 204 );
		if ( ! Lejek_Settings::get( 'tracking' ) ) {
			return $none;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $ua || preg_match( self::BOTS, $ua ) ) {
			return $none;
		}

		$p = $request->get_json_params();
		if ( ! is_array( $p ) ) {
			$p = json_decode( $request->get_body(), true );
		}
		if ( ! is_array( $p ) ) {
			return $none;
		}
		$vid = isset( $p['vid'] ) ? strtolower( (string) $p['vid'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $vid ) ) {
			return $none;
		}
		$page = isset( $p['page'] ) ? absint( $p['page'] ) : 0;

		global $wpdb;
		$t      = Lejek_DB::events();
		$recent = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE type = 'view' AND visitor = %s AND page_id = %d AND created_at > %s LIMIT 1", $vid, $page, gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $recent ) {
			return $none;
		}

		self::log(
			'view',
			array(
				'page_id'      => $page,
				'visitor'      => $vid,
				'utm_source'   => isset( $p['source'] ) ? $p['source'] : '',
				'utm_campaign' => isset( $p['campaign'] ) ? $p['campaign'] : '',
				'referrer'     => isset( $p['ref'] ) ? $p['ref'] : '',
			)
		);
		return $none;
	}

	public static function log( $type, $data ) {
		global $wpdb;
		$wpdb->insert(
			Lejek_DB::events(),
			array(
				'created_at'   => current_time( 'mysql', true ),
				'type'         => substr( sanitize_key( $type ), 0, 20 ),
				'page_id'      => isset( $data['page_id'] ) ? absint( $data['page_id'] ) : 0,
				'product_id'   => isset( $data['product_id'] ) ? absint( $data['product_id'] ) : 0,
				'visitor'      => ( isset( $data['visitor'] ) && preg_match( '/^[a-f0-9]{32}$/', $data['visitor'] ) ) ? $data['visitor'] : '',
				'utm_source'   => self::clean( isset( $data['utm_source'] ) ? $data['utm_source'] : '' ),
				'utm_campaign' => self::clean( isset( $data['utm_campaign'] ) ? $data['utm_campaign'] : '' ),
				'referrer'     => self::clean( isset( $data['referrer'] ) ? $data['referrer'] : '' ),
			)
		);
	}

	private static function clean( $value ) {
		return mb_substr( strtolower( sanitize_text_field( (string) $value ) ), 0, 100 );
	}
}

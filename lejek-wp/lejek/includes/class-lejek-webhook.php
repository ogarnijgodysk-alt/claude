<?php
defined( 'ABSPATH' ) || exit;

/**
 * Webhook Stripe: zapisuje opłacone zamówienia i zwroty. Każde zdarzenie jest weryfikowane podpisem.
 */
class Lejek_Webhook {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'lejek/v1',
			'/stripe/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Autoryzacja = podpis Stripe poniżej.
			)
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$payload  = $request->get_body();
		$header   = $request->get_header( 'stripe-signature' );
		$verified = false;
		foreach ( array( 'live', 'test' ) as $mode ) {
			$secret = Lejek_Settings::webhook_secret( $mode );
			if ( $secret && Lejek_Stripe::verify_signature( $payload, $header, $secret ) ) {
				$verified = true;
				break;
			}
		}
		if ( ! $verified ) {
			return new WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_payload' ), 400 );
		}
		$mode   = empty( $event['livemode'] ) ? 'test' : 'live';
		$object = isset( $event['data']['object'] ) && is_array( $event['data']['object'] ) ? $event['data']['object'] : array();

		switch ( $event['type'] ) {
			case 'checkout.session.completed':
			case 'checkout.session.async_payment_succeeded':
				$paid = isset( $object['payment_status'] ) && in_array( $object['payment_status'], array( 'paid', 'no_payment_required' ), true );
				if ( $paid ) {
					Lejek_Orders::create_from_session( $object, $mode );
				}
				break;

			case 'charge.refunded':
				Lejek_Orders::mark_refunded(
					isset( $object['payment_intent'] ) && is_string( $object['payment_intent'] ) ? $object['payment_intent'] : '',
					isset( $object['amount_refunded'] ) ? (int) $object['amount_refunded'] : 0,
					isset( $object['amount'] ) ? (int) $object['amount'] : 0,
					isset( $object['currency'] ) ? $object['currency'] : 'usd'
				);
				break;
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}

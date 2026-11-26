<?php
defined( 'ABSPATH' ) || exit;

/**
 * Minimalny klient API Stripe (bez zewnętrznych bibliotek) i weryfikacja podpisu webhooka.
 */
class Lejek_Stripe {

	const API = 'https://api.stripe.com/v1/';

	/**
	 * @param string      $method GET albo POST.
	 * @param string      $path   np. 'checkout/sessions'.
	 * @param array       $params Parametry (zagnieżdżone tablice dozwolone).
	 * @param string|null $mode   'test' albo 'live'; domyślnie tryb z ustawień.
	 * @param string|null $idempotency_key Klucz chroniący przed podwójnym wykonaniem.
	 * @return array|WP_Error
	 */
	public static function request( $method, $path, $params = array(), $mode = null, $idempotency_key = null ) {
		$key = Lejek_Settings::secret_key( $mode );
		if ( '' === $key ) {
			return new WP_Error( 'lejek_no_key', 'Brak klucza Stripe w ustawieniach wtyczki Lejek.' );
		}

		$url  = self::API . ltrim( $path, '/' );
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
		);
		if ( 'GET' === $method ) {
			if ( $params ) {
				$url .= '?' . self::encode( $params );
			}
		} else {
			$args['body']                    = self::encode( $params );
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
		}
		if ( $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key;
		}

		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Błąd Stripe (HTTP ' . $code . ')';
			return new WP_Error( 'lejek_stripe', $msg, $data );
		}
		return $data;
	}

	/** Koduje parametry tak, jak oczekuje Stripe: a[b][0][c]=d, wartości logiczne jako "true"/"false". */
	public static function encode( $params ) {
		return http_build_query( self::normalize( $params ), '', '&' );
	}

	private static function normalize( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( null === $v ) {
					continue;
				}
				$out[ $k ] = self::normalize( $v );
			}
			return $out;
		}
		if ( true === $value ) {
			return 'true';
		}
		if ( false === $value ) {
			return 'false';
		}
		return (string) $value;
	}

	/** Sprawdza nagłówek Stripe-Signature (HMAC SHA-256, tolerancja 5 minut). */
	public static function verify_signature( $payload, $header, $secret, $tolerance = 300 ) {
		if ( ! $header || ! $secret ) {
			return false;
		}
		$timestamp  = 0;
		$signatures = array();
		foreach ( explode( ',', (string) $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$timestamp = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$signatures[] = $kv[1];
			}
		}
		if ( ! $timestamp || ! $signatures || abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}

	/** Link do płatności w panelu Stripe. */
	public static function dashboard_payment_url( $payment_intent, $mode ) {
		return 'https://dashboard.stripe.com/' . ( 'test' === $mode ? 'test/' : '' ) . 'payments/' . rawurlencode( $payment_intent );
	}
}

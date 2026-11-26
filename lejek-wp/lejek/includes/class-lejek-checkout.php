<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kasa: link /?lejek_checkout=adres-produktu tworzy sesję Stripe Checkout i przekierowuje do płatności.
 * Endpoint REST z liczbą wolnych miejsc dla licznika na stronie sprzedażowej.
 */
class Lejek_Checkout {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'lejek/v1',
			'/availability/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'availability' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function availability( WP_REST_Request $request ) {
		$product = Lejek_Products::get_by_slug( $request['slug'] );
		if ( ! $product ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}
		$a   = Lejek_Products::availability( $product->ID );
		$res = new WP_REST_Response(
			array(
				'capacity'     => $a['capacity'],
				'sold'         => $a['sold'],
				'left'         => $a['left'],
				'open'         => null !== $a['slot'],
				'reservation'  => null !== $a['slot'] && $a['slot'] !== $a['month'],
				'month'        => $a['month'],
				'next_month'   => $a['next_month'],
				'checkout_url' => Lejek_Products::buy_url( $product->ID ),
			)
		);
		$res->header( 'Cache-Control', 'no-store, max-age=0' );
		return $res;
	}

	public static function maybe_start() {
		if ( empty( $_GET['lejek_checkout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		nocache_headers();

		// Przeglądarki i komunikatory czasem „podglądają” linki z wyprzedzeniem; nie zakładamy wtedy płatności.
		$purpose = strtolower( ( isset( $_SERVER['HTTP_SEC_PURPOSE'] ) ? $_SERVER['HTTP_SEC_PURPOSE'] : '' ) . ( isset( $_SERVER['HTTP_PURPOSE'] ) ? $_SERVER['HTTP_PURPOSE'] : '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( false !== strpos( $purpose, 'prefetch' ) ) {
			status_header( 204 );
			exit;
		}

		$slug    = sanitize_title( wp_unslash( $_GET['lejek_checkout'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$product = Lejek_Products::get_by_slug( $slug );
		if ( ! $product ) {
			wp_die( esc_html__( 'This product is not available.', 'lejek' ), '', array( 'response' => 404 ) );
		}

		$back = wp_get_referer();
		if ( ! $back ) {
			$back = home_url( '/' );
		}

		$av = Lejek_Products::availability( $product->ID );
		if ( null === $av['slot'] ) {
			wp_safe_redirect( add_query_arg( 'lejek_soldout', '1', $back ) );
			exit;
		}

		$session = self::create_session( $product->ID, $av['slot'], $back );
		if ( is_wp_error( $session ) ) {
			error_log( 'Lejek checkout: ' . $session->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			wp_die( esc_html__( 'Checkout is temporarily unavailable. Please try again in a few minutes.', 'lejek' ), '', array( 'response' => 503, 'back_link' => true ) );
		}

		$utm = Lejek_Frontend::utm();
		Lejek_Events::log(
			'checkout_start',
			array(
				'product_id'   => $product->ID,
				'visitor'      => Lejek_Frontend::visitor(),
				'utm_source'   => $utm['source'],
				'utm_campaign' => $utm['campaign'],
			)
		);

		wp_redirect( $session['url'], 303 ); // phpcs:ignore WordPress.Security.SafeRedirect -- adres płatności Stripe.
		exit;
	}

	/**
	 * @return array|WP_Error Sesja Stripe Checkout.
	 */
	public static function create_session( $product_id, $slot, $back, $retry = true ) {
		$price = Lejek_Products::ensure_stripe_price( $product_id );
		if ( is_wp_error( $price ) ) {
			return $price;
		}

		$thanks = Lejek_Products::thankyou_url( $product_id );
		$utm    = Lejek_Frontend::utm();
		$site   = Lejek_Orders::site_host();

		$params = array(
			'mode'                       => 'payment',
			'line_items'                 => array(
				array(
					'price'    => $price,
					'quantity' => 1,
				),
			),
			'success_url'                => $thanks . ( false === strpos( $thanks, '?' ) ? '?' : '&' ) . 'session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'                 => $back,
			'customer_creation'          => 'always',
			'phone_number_collection'    => array( 'enabled' => true ),
			'billing_address_collection' => 'auto',
			'locale'                     => 'auto',
			'client_reference_id'        => 'lejek-' . $product_id,
			// Zapisana karta pozwoli na upsell jednym kliknięciem zaraz po zakupie.
			'payment_intent_data'        => array(
				'setup_future_usage' => 'off_session',
				'metadata'           => array(
					'lejek_product' => $product_id,
					'lejek_site'    => $site,
				),
			),
			'metadata'                   => array(
				'lejek_product' => $product_id,
				'lejek_slot'    => $slot,
				'lejek_site'    => $site,
				'lejek_vid'     => Lejek_Frontend::visitor(),
				'utm_source'    => $utm['source'],
				'utm_medium'    => $utm['medium'],
				'utm_campaign'  => $utm['campaign'],
			),
		);
		$params = apply_filters( 'lejek_checkout_params', $params, $product_id );

		$session = Lejek_Stripe::request( 'POST', 'checkout/sessions', $params );
		if ( is_wp_error( $session ) && $retry && false !== stripos( $session->get_error_message(), 'No such price' ) ) {
			// Np. po zmianie konta Stripe: zakładamy cenę od nowa.
			Lejek_Products::reset_stripe( $product_id );
			return self::create_session( $product_id, $slot, $back, false );
		}
		if ( ! is_wp_error( $session ) && empty( $session['url'] ) ) {
			return new WP_Error( 'lejek_stripe', 'Stripe nie zwrócił adresu płatności.' );
		}
		return $session;
	}
}

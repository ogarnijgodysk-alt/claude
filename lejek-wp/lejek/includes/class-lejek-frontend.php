<?php
defined( 'ABSPATH' ) || exit;

/**
 * Strona dla klienta: śledzenie wejść i źródeł, piksel Meta, Clarity, strona podziękowania,
 * krótkie kody i „pusta strona” bez nagłówka i stopki motywu (pod strony sprzedażowe z własnym HTML).
 */
class Lejek_Frontend {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'head_scripts' ), 5 );
		add_action( 'wp_footer', array( __CLASS__, 'footer_scripts' ), 20 );
		add_shortcode( 'lejek_thankyou', array( __CLASS__, 'shortcode_thankyou' ) );
		add_shortcode( 'lejek_buy_url', array( __CLASS__, 'shortcode_buy_url' ) );
		add_shortcode( 'lejek_spots_left', array( __CLASS__, 'shortcode_spots_left' ) );

		add_action( 'add_meta_boxes_page', array( __CLASS__, 'blank_meta_box' ) );
		add_action( 'save_post_page', array( __CLASS__, 'save_blank' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_action( 'wp', array( __CLASS__, 'raw_content_on_blank' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'strip_theme_styles' ), 9999 );
	}

	/* ---------- Odwiedzający i źródło ---------- */

	public static function visitor() {
		$vid = isset( $_COOKIE['lejek_vid'] ) ? strtolower( sanitize_text_field( wp_unslash( $_COOKIE['lejek_vid'] ) ) ) : '';
		return preg_match( '/^[a-f0-9]{32}$/', $vid ) ? $vid : '';
	}

	public static function utm() {
		$utm = array(
			'source'   => '',
			'medium'   => '',
			'campaign' => '',
		);
		if ( ! empty( $_COOKIE['lejek_utm'] ) ) {
			$c = json_decode( wp_unslash( $_COOKIE['lejek_utm'] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $c ) ) {
				foreach ( $utm as $k => $v ) {
					if ( isset( $c[ $k ] ) && is_string( $c[ $k ] ) ) {
						$utm[ $k ] = $c[ $k ];
					}
				}
			}
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['utm_source'] ) ) {
			$utm['source']   = wp_unslash( $_GET['utm_source'] );
			$utm['medium']   = isset( $_GET['utm_medium'] ) ? wp_unslash( $_GET['utm_medium'] ) : '';
			$utm['campaign'] = isset( $_GET['utm_campaign'] ) ? wp_unslash( $_GET['utm_campaign'] ) : '';
		}
		// phpcs:enable
		foreach ( $utm as $k => $v ) {
			$utm[ $k ] = mb_substr( strtolower( sanitize_text_field( (string) $v ) ), 0, 100 );
		}
		return $utm;
	}

	private static function should_track() {
		return Lejek_Settings::get( 'tracking' ) && ! current_user_can( 'edit_posts' ) && ! is_preview();
	}

	/* ---------- Skrypty ---------- */

	public static function head_scripts() {
		$pixel = Lejek_Settings::get( 'pixel_id' );
		if ( $pixel && Lejek_Settings::get( 'pixel_inject' ) ) {
			?>
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',<?php echo wp_json_encode( (string) $pixel ); ?>);fbq('track','PageView');</script>
			<?php
		}
		$clarity = Lejek_Settings::get( 'clarity_id' );
		if ( $clarity ) {
			?>
<script>(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);})(window,document,"clarity","script",<?php echo wp_json_encode( (string) $clarity ); ?>);</script>
			<?php
		}
	}

	public static function footer_scripts() {
		if ( isset( $_GET['lejek_soldout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div role="status" style="position:fixed;left:16px;right:16px;bottom:16px;z-index:99999;max-width:520px;margin:0 auto;background:#0f172a;color:#fff;padding:14px 18px;border-radius:12px;font:15px/1.4 system-ui,sans-serif;text-align:center">' . esc_html__( 'Sorry, all spots for this month are taken.', 'lejek' ) . '</div>';
		}
		if ( ! self::should_track() ) {
			return;
		}
		$obj = get_queried_object();
		$cfg = array(
			'url'  => rest_url( 'lejek/v1/track' ),
			'page' => ( is_singular() || is_front_page() ) ? (int) get_queried_object_id() : 0,
			'slug' => ( $obj && isset( $obj->post_name ) ) ? $obj->post_name : ( is_front_page() ? 'home' : '' ),
		);
		?>
<script>(function(){var cfg=<?php echo wp_json_encode( $cfg ); ?>;
function gc(n){var m=document.cookie.match('(?:^|; )'+n+'=([^;]*)');return m?decodeURIComponent(m[1]):'';}
function sc(n,v,d){document.cookie=n+'='+encodeURIComponent(v)+';path=/;max-age='+(d*86400)+';samesite=lax'+(location.protocol==='https:'?';secure':'');}
var vid=gc('lejek_vid');if(!/^[a-f0-9]{32}$/.test(vid)){vid='';var a=new Uint8Array(16);window.crypto.getRandomValues(a);for(var i=0;i<16;i++){vid+=('0'+a[i].toString(16)).slice(-2);}sc('lejek_vid',vid,365);}
var q=new URLSearchParams(location.search),ref='';try{if(document.referrer){var h=new URL(document.referrer).hostname;if(h&&h!==location.hostname){ref=h.replace(/^www\./,'');}}}catch(e){}
var t={};try{t=JSON.parse(gc('lejek_utm')||'{}')||{};}catch(e){t={};}
if(q.get('utm_source')){t={source:q.get('utm_source'),medium:q.get('utm_medium')||'',campaign:q.get('utm_campaign')||''};sc('lejek_utm',JSON.stringify(t),30);}
else if(ref&&!t.source){t={source:ref,medium:'referral',campaign:''};sc('lejek_utm',JSON.stringify(t),30);}
var body=JSON.stringify({page:cfg.page,vid:vid,source:t.source||'',campaign:t.campaign||'',ref:ref});
try{if(!(navigator.sendBeacon&&navigator.sendBeacon(cfg.url,new Blob([body],{type:'application/json'})))){fetch(cfg.url,{method:'POST',body:body,headers:{'Content-Type':'application/json'},keepalive:true,credentials:'omit'});}}catch(e){}
if(window.clarity){clarity('set','lejek_page',cfg.slug||'other');clarity('set','lejek_source',t.source||'direct');}
})();</script>
		<?php
	}

	/* ---------- Krótkie kody ---------- */

	/**
	 * [lejek_thankyou] … własna treść z {first_name}, {name}, {email}, {order}, {product}, {amount}, {company_phone} … [/lejek_thankyou]
	 */
	public static function shortcode_thankyou( $atts, $content = '' ) {
		$sid = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! preg_match( '/^cs_(test|live)_[A-Za-z0-9]+$/', $sid, $m ) ) {
			return '<div class="lejek-thankyou"><p>' . esc_html__( 'Thank you! If you just paid, check your email for the receipt.', 'lejek' ) . '</p></div>';
		}
		$mode  = $m[1];
		$order = Lejek_Orders::get_by_session( $sid );
		if ( ! $order ) {
			// Webhook jeszcze nie dotarł: sprawdzamy płatność bezpośrednio w Stripe.
			$s = Lejek_Stripe::request( 'GET', 'checkout/sessions/' . rawurlencode( $sid ), array(), $mode );
			if ( ! is_wp_error( $s ) && isset( $s['payment_status'] ) && in_array( $s['payment_status'], array( 'paid', 'no_payment_required' ), true ) ) {
				$order = Lejek_Orders::create_from_session( $s, $mode );
			}
		}
		if ( ! $order ) {
			return '<div class="lejek-thankyou"><p>' . esc_html__( 'We are confirming your payment. Please refresh this page in a moment.', 'lejek' ) . '</p></div>';
		}

		$parts = preg_split( '/\s+/', trim( (string) $order->name ) );
		$first = $parts && '' !== $parts[0] ? $parts[0] : __( 'there', 'lejek' );
		$phone = (string) Lejek_Settings::get( 'company_phone' );

		if ( '' === trim( (string) $content ) ) {
			$content  = '<h2>' . esc_html__( 'Thank you, {first_name}!', 'lejek' ) . '</h2>';
			$content .= '<p>' . esc_html__( 'Your order #{order} ({product}, {amount}) is confirmed. A receipt is on its way to {email}.', 'lejek' ) . '</p>';
			$content .= '<p>' . ( $phone
				? esc_html__( 'What happens next: we will call you shortly to get started. Save our number: {company_phone}.', 'lejek' )
				: esc_html__( 'What happens next: we will call you shortly to get started.', 'lejek' ) ) . '</p>';
		}

		$html = strtr(
			do_shortcode( $content ),
			array(
				'{first_name}'    => esc_html( $first ),
				'{name}'          => esc_html( $order->name ),
				'{email}'         => esc_html( $order->email ),
				'{order}'         => (int) $order->id,
				'{product}'       => esc_html( $order->product_name ),
				'{amount}'        => esc_html( Lejek_Products::money( $order->amount, $order->currency ) ),
				'{company_phone}' => $phone ? '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ) . '">' . esc_html( $phone ) . '</a>' : '',
			)
		);

		return '<div class="lejek-thankyou">' . $html . '</div>' . self::purchase_script( $order );
	}

	/** Zdarzenie Purchase dla piksela Meta i Clarity – raz na zamówienie w danej przeglądarce. */
	private static function purchase_script( $order ) {
		$data = array(
			'id'       => (int) $order->id,
			'value'    => round( $order->amount / 100, 2 ),
			'currency' => strtoupper( $order->currency ),
		);
		return '<script>window.addEventListener("load",function(){var d=' . wp_json_encode( $data ) . ';try{var k="lejek_px_"+d.id;if(localStorage.getItem(k))return;localStorage.setItem(k,"1");}catch(e){}'
			. 'if(window.fbq){fbq("track","Purchase",{value:d.value,currency:d.currency},{eventID:"lejek-order-"+d.id});}'
			. 'if(window.clarity){clarity("set","lejek_purchase","yes");clarity("event","lejek_purchase");}});</script>';
	}

	public static function shortcode_buy_url( $atts ) {
		$atts    = shortcode_atts( array( 'product' => '' ), $atts );
		$product = Lejek_Products::get_by_slug( sanitize_title( $atts['product'] ) );
		return $product ? esc_url( Lejek_Products::buy_url( $product->ID ) ) : '';
	}

	public static function shortcode_spots_left( $atts ) {
		$atts    = shortcode_atts( array( 'product' => '' ), $atts );
		$product = Lejek_Products::get_by_slug( sanitize_title( $atts['product'] ) );
		if ( ! $product ) {
			return '';
		}
		$a = Lejek_Products::availability( $product->ID );
		return null === $a['left'] ? '' : (string) (int) $a['left'];
	}

	/* ---------- Pusta strona (bez motywu) ---------- */

	private static function is_blank_page() {
		return is_page() && get_post_meta( get_queried_object_id(), '_lejek_blank', true );
	}

	public static function blank_meta_box() {
		add_meta_box( 'lejek_blank', 'Lejek', array( __CLASS__, 'render_blank_box' ), 'page', 'side' );
	}

	public static function render_blank_box( $post ) {
		wp_nonce_field( 'lejek_blank', 'lejek_blank_nonce' );
		?>
		<label><input type="checkbox" name="lejek_blank" value="1" <?php checked( (bool) get_post_meta( $post->ID, '_lejek_blank', true ) ); ?>>
			Pusta strona – bez nagłówka, stopki i stylów motywu</label>
		<p class="description">Do stron sprzedażowych wklejonych jako „Własny HTML”.</p>
		<?php
	}

	public static function save_blank( $post_id ) {
		if ( ! isset( $_POST['lejek_blank_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lejek_blank_nonce'] ) ), 'lejek_blank' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}
		if ( empty( $_POST['lejek_blank'] ) ) {
			delete_post_meta( $post_id, '_lejek_blank' );
		} else {
			update_post_meta( $post_id, '_lejek_blank', 1 );
		}
	}

	/**
	 * Na pustych stronach treść ma wyjść dokładnie tak, jak ją wklejono: WordPress nie „upiększa” cudzysłowów
	 * ani znaków &, bo potrafi przy tym uszkodzić skrypty w bloku „Własny HTML”.
	 */
	public static function raw_content_on_blank() {
		if ( self::is_blank_page() ) {
			remove_filter( 'the_content', 'wptexturize' );
			remove_filter( 'the_content', 'convert_smilies', 20 );
		}
	}

	public static function template_include( $template ) {
		return self::is_blank_page() ? LEJEK_DIR . 'templates/blank.php' : $template;
	}

	public static function strip_theme_styles() {
		if ( ! self::is_blank_page() ) {
			return;
		}
		$styles = wp_styles();
		$theme  = array( get_template_directory_uri(), get_stylesheet_directory_uri() );
		foreach ( $styles->queue as $handle ) {
			$src = isset( $styles->registered[ $handle ] ) ? (string) $styles->registered[ $handle ]->src : '';
			foreach ( $theme as $prefix ) {
				if ( $src && 0 === strpos( $src, $prefix ) ) {
					wp_dequeue_style( $handle );
				}
			}
		}
		foreach ( array( 'global-styles', 'classic-theme-styles', 'wp-block-library-theme' ) as $handle ) {
			wp_dequeue_style( $handle );
		}
	}
}

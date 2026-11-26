<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ustawienia: tryb Stripe, klucze, webhook, powiadomienia, strona podziękowania, analityka.
 */
class Lejek_Settings {

	const OPTION  = 'lejek_settings';
	const SECRETS = array(
		'test_secret'  => array( 'sk_test_', 'rk_test_' ),
		'live_secret'  => array( 'sk_live_', 'rk_live_' ),
		'test_webhook' => array( 'whsec_' ),
		'live_webhook' => array( 'whsec_' ),
	);

	public static function init() {
		add_action( 'admin_post_lejek_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_lejek_test_stripe', array( __CLASS__, 'test_connection' ) );
	}

	public static function defaults() {
		return array(
			'mode'          => 'test',
			'test_secret'   => '',
			'live_secret'   => '',
			'test_webhook'  => '',
			'live_webhook'  => '',
			'notify_email'  => get_option( 'admin_email' ),
			'company_phone' => '',
			'thankyou_page' => 0,
			'tracking'      => 1,
			'pixel_id'      => '',
			'pixel_inject'  => 0,
			'clarity_id'    => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function mode() {
		return 'live' === self::get( 'mode' ) ? 'live' : 'test';
	}

	public static function secret_key( $mode = null ) {
		$mode = $mode ? $mode : self::mode();
		return Lejek_Crypto::decrypt( self::get( $mode . '_secret' ) );
	}

	public static function webhook_secret( $mode ) {
		return Lejek_Crypto::decrypt( self::get( $mode . '_webhook' ) );
	}

	public static function webhook_url() {
		return rest_url( 'lejek/v1/stripe/webhook' );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'lejek_settings' );

		$in     = wp_unslash( $_POST );
		$s      = self::all();
		$errors = array();

		$s['mode'] = ( isset( $in['mode'] ) && 'live' === $in['mode'] ) ? 'live' : 'test';

		foreach ( self::SECRETS as $field => $prefixes ) {
			if ( ! empty( $in[ 'clear_' . $field ] ) ) {
				$s[ $field ] = '';
				continue;
			}
			$value = isset( $in[ $field ] ) ? trim( (string) $in[ $field ] ) : '';
			if ( '' === $value ) {
				continue;
			}
			$ok = false;
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $value, $prefix ) ) {
					$ok = true;
				}
			}
			if ( ! $ok ) {
				$errors[] = sprintf( 'Pole „%s” musi zaczynać się od %s. Nie zapisano go.', self::label( $field ), implode( ' albo ', $prefixes ) );
				continue;
			}
			$s[ $field ] = Lejek_Crypto::encrypt( $value );
		}

		$s['notify_email']  = sanitize_email( isset( $in['notify_email'] ) ? $in['notify_email'] : '' );
		$s['company_phone'] = sanitize_text_field( isset( $in['company_phone'] ) ? $in['company_phone'] : '' );
		$s['thankyou_page'] = absint( isset( $in['thankyou_page'] ) ? $in['thankyou_page'] : 0 );
		$s['tracking']      = empty( $in['tracking'] ) ? 0 : 1;
		$s['pixel_id']      = preg_replace( '/\D/', '', isset( $in['pixel_id'] ) ? (string) $in['pixel_id'] : '' );
		$s['pixel_inject']  = empty( $in['pixel_inject'] ) ? 0 : 1;
		$s['clarity_id']    = preg_replace( '/[^a-zA-Z0-9]/', '', isset( $in['clarity_id'] ) ? (string) $in['clarity_id'] : '' );

		update_option( self::OPTION, $s, false );

		foreach ( $errors as $e ) {
			Lejek_Admin::flash( $e, 'error' );
		}
		Lejek_Admin::flash( 'Zapisano ustawienia.', 'success' );
		wp_safe_redirect( admin_url( 'admin.php?page=lejek-settings' ) );
		exit;
	}

	public static function test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'lejek_test_stripe' );

		$mode    = self::mode();
		$account = Lejek_Stripe::request( 'GET', 'account' );
		if ( is_wp_error( $account ) ) {
			$balance = Lejek_Stripe::request( 'GET', 'balance' );
			if ( is_wp_error( $balance ) ) {
				Lejek_Admin::flash( 'Nie udało się połączyć ze Stripe (' . $mode . '): ' . $account->get_error_message(), 'error' );
			} else {
				Lejek_Admin::flash( 'Połączono ze Stripe (tryb ' . $mode . ').', 'success' );
			}
		} else {
			$name = '';
			if ( ! empty( $account['settings']['dashboard']['display_name'] ) ) {
				$name = $account['settings']['dashboard']['display_name'];
			} elseif ( ! empty( $account['business_profile']['name'] ) ) {
				$name = $account['business_profile']['name'];
			}
			Lejek_Admin::flash(
				sprintf(
					'Połączono ze Stripe (tryb %s). Konto: %s, kraj: %s, waluta wypłat: %s.',
					$mode,
					$name ? $name : $account['id'],
					isset( $account['country'] ) ? $account['country'] : '?',
					isset( $account['default_currency'] ) ? strtoupper( $account['default_currency'] ) : '?'
				),
				'success'
			);
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lejek-settings' ) );
		exit;
	}

	private static function label( $field ) {
		$labels = array(
			'test_secret'  => 'Klucz tajny – test',
			'live_secret'  => 'Klucz tajny – na żywo',
			'test_webhook' => 'Sekret webhooka – test',
			'live_webhook' => 'Sekret webhooka – na żywo',
		);
		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	private static function secret_status( $field ) {
		$plain = Lejek_Crypto::decrypt( self::get( $field ) );
		if ( '' === $plain ) {
			return '<span class="lejek-muted">nie ustawiono</span>';
		}
		return '<span class="lejek-ok">✓ zapisany, kończy się na „' . esc_html( substr( $plain, -4 ) ) . '”</span>';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::all();
		?>
		<div class="wrap lejek-wrap">
			<h1>Ustawienia</h1>
			<?php Lejek_Admin::render_flash(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lejek_save_settings">
				<?php wp_nonce_field( 'lejek_settings' ); ?>

				<div class="lejek-card">
					<h2>Płatności – Stripe</h2>
					<p>
						<label><input type="radio" name="mode" value="test" <?php checked( 'test', $s['mode'] ); ?>> <strong>Tryb testowy</strong> – płatności nie są prawdziwe (karta testowa 4242 4242 4242 4242)</label><br>
						<label><input type="radio" name="mode" value="live" <?php checked( 'live', $s['mode'] ); ?>> <strong>Na żywo</strong> – prawdziwe płatności</label>
					</p>
					<table class="form-table" role="presentation">
						<?php foreach ( array_keys( self::SECRETS ) as $field ) : ?>
							<tr>
								<th scope="row"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( self::label( $field ) ); ?></label></th>
								<td>
									<input type="password" class="regular-text" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="" autocomplete="off" placeholder="<?php echo esc_attr( false !== strpos( $field, 'webhook' ) ? 'whsec_…' : ( 0 === strpos( $field, 'test' ) ? 'sk_test_…' : 'sk_live_…' ) ); ?>">
									<p class="description"><?php echo wp_kses_post( self::secret_status( $field ) ); ?> · puste pole = bez zmian ·
										<label><input type="checkbox" name="clear_<?php echo esc_attr( $field ); ?>" value="1"> usuń</label></p>
								</td>
							</tr>
						<?php endforeach; ?>
					</table>
					<p class="description">Klucze znajdziesz w Stripe: Developers → API keys. Zapisują się zaszyfrowane.</p>
				</div>

				<div class="lejek-card">
					<h2>Webhook (Stripe informuje sklep o płatnościach)</h2>
					<p>W Stripe: Developers → Webhooks → <strong>Add endpoint</strong>. Wklej adres:</p>
					<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( self::webhook_url() ); ?>" onclick="this.select()"></p>
					<p>Zaznacz zdarzenia: <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, <code>charge.refunded</code>. Potem skopiuj „Signing secret” (whsec_…) do pola powyżej. Zrób to osobno w trybie testowym i na żywo.</p>
				</div>

				<div class="lejek-card">
					<h2>Zamówienia</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="notify_email">E-mail na powiadomienia</label></th>
							<td><input type="email" class="regular-text" id="notify_email" name="notify_email" value="<?php echo esc_attr( $s['notify_email'] ); ?>">
								<p class="description">Tu przyjdzie wiadomość o każdym nowym zamówieniu (z telefonem klienta).</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="company_phone">Wasz numer telefonu</label></th>
							<td><input type="text" class="regular-text" id="company_phone" name="company_phone" value="<?php echo esc_attr( $s['company_phone'] ); ?>" placeholder="+1 (332) 241-5600">
								<p class="description">Pokazujemy go klientowi na stronie podziękowania („zapisz nasz numer”).</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="thankyou_page">Strona podziękowania</label></th>
							<td>
								<?php
								wp_dropdown_pages(
									array(
										'name'              => 'thankyou_page',
										'id'                => 'thankyou_page',
										'selected'          => (int) $s['thankyou_page'],
										'show_option_none'  => '— wybierz stronę —',
										'option_none_value' => 0,
									)
								);
								?>
								<p class="description">Na tej stronie wstaw krótki kod <code>[lejek_thankyou]</code>. Tam wraca klient po zapłacie.</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="lejek-card">
					<h2>Analityka</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Statystyki lejka</th>
							<td><label><input type="checkbox" name="tracking" value="1" <?php checked( 1, (int) $s['tracking'] ); ?>> Zliczaj wejścia na strony i źródła ruchu (dane zostają na Twoim serwerze)</label></td>
						</tr>
						<tr>
							<th scope="row"><label for="pixel_id">Piksel Meta – ID</label></th>
							<td><input type="text" class="regular-text" id="pixel_id" name="pixel_id" value="<?php echo esc_attr( $s['pixel_id'] ); ?>" placeholder="np. 1234567890">
								<p class="description"><label><input type="checkbox" name="pixel_inject" value="1" <?php checked( 1, (int) $s['pixel_inject'] ); ?>> Wstaw kod piksela na stronach</label> (odznacz, jeśli piksel wstawia już inna wtyczka). Zdarzenie Purchase wysyłamy na stronie podziękowania zawsze, gdy piksel jest na stronie.</p></td>
						</tr>
						<tr>
							<th scope="row"><label for="clarity_id">Microsoft Clarity – ID projektu</label></th>
							<td><input type="text" class="regular-text" id="clarity_id" name="clarity_id" value="<?php echo esc_attr( $s['clarity_id'] ); ?>" placeholder="np. abcd1234ef">
								<p class="description">Wstawiamy kod Clarity i oznaczamy sesje: źródło ruchu, strona, zakup. Pobieranie wskaźników przez API dojdzie w kolejnej wersji.</p></td>
						</tr>
					</table>
				</div>

				<?php submit_button( 'Zapisz ustawienia' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="lejek_test_stripe">
				<?php wp_nonce_field( 'lejek_test_stripe' ); ?>
				<?php submit_button( 'Sprawdź połączenie ze Stripe', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}

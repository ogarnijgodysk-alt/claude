<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zamówienia: zapis z płatności Stripe, lista w panelu, statusy, zwroty, eksport CSV, powiadomienia.
 */
class Lejek_Orders {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_lejek_order_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_lejek_order_refund', array( __CLASS__, 'handle_refund' ) );
		add_action( 'admin_post_lejek_orders_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function statuses() {
		return array(
			'new'       => 'Nowe – do telefonu',
			'contacted' => 'Po rozmowie',
			'done'      => 'Zrealizowane',
			'refunded'  => 'Zwrot',
		);
	}

	public static function site_host() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/* ---------- Dane ---------- */

	public static function get( $id ) {
		global $wpdb;
		$t = Lejek_DB::orders();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_by_session( $session_id ) {
		global $wpdb;
		$t = Lejek_DB::orders();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE stripe_session = %s", $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function count_slot( $product_id, $slot, $mode ) {
		global $wpdb;
		$t = Lejek_DB::orders();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE product_id = %d AND slot_month = %s AND mode = %s AND status <> 'refunded'", $product_id, $slot, $mode ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function count_new() {
		global $wpdb;
		$t = Lejek_DB::orders();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'new' AND mode = %s", Lejek_Settings::mode() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Tworzy zamówienie z opłaconej sesji Stripe Checkout. Bezpieczne do wielokrotnego wywołania
	 * (webhook i strona podziękowania mogą przyjść w dowolnej kolejności).
	 *
	 * @return object|false
	 */
	public static function create_from_session( $s, $mode ) {
		global $wpdb;
		$meta = isset( $s['metadata'] ) && is_array( $s['metadata'] ) ? $s['metadata'] : array();
		if ( empty( $s['id'] ) || empty( $meta['lejek_product'] ) ) {
			return false; // Płatność spoza wtyczki.
		}
		if ( ! empty( $meta['lejek_site'] ) && $meta['lejek_site'] !== self::site_host() ) {
			return false; // Płatność z innej Twojej domeny na tym samym koncie Stripe.
		}

		$existing = self::get_by_session( $s['id'] );
		if ( $existing ) {
			return $existing;
		}

		$cd         = isset( $s['customer_details'] ) && is_array( $s['customer_details'] ) ? $s['customer_details'] : array();
		$product_id = (int) $meta['lejek_product'];
		$keys       = Lejek_Products::month_keys();
		$now        = current_time( 'mysql', true );

		$row = array(
			'created_at'            => $now,
			'updated_at'            => $now,
			'mode'                  => 'live' === $mode ? 'live' : 'test',
			'status'                => 'new',
			'product_id'            => $product_id,
			'product_name'          => mb_substr( (string) get_the_title( $product_id ), 0, 190 ),
			'slot_month'            => ( isset( $meta['lejek_slot'] ) && preg_match( '/^\d{4}-\d{2}$/', $meta['lejek_slot'] ) ) ? $meta['lejek_slot'] : $keys[0],
			'amount'                => isset( $s['amount_total'] ) ? (int) $s['amount_total'] : 0,
			'currency'              => isset( $s['currency'] ) ? substr( strtolower( $s['currency'] ), 0, 3 ) : 'usd',
			'email'                 => isset( $cd['email'] ) ? sanitize_email( $cd['email'] ) : '',
			'name'                  => isset( $cd['name'] ) ? mb_substr( sanitize_text_field( $cd['name'] ), 0, 190 ) : '',
			'phone'                 => isset( $cd['phone'] ) ? substr( preg_replace( '/[^0-9+]/', '', $cd['phone'] ), 0, 40 ) : '',
			'country'               => isset( $cd['address']['country'] ) ? substr( sanitize_text_field( $cd['address']['country'] ), 0, 2 ) : '',
			'utm_source'            => isset( $meta['utm_source'] ) ? mb_substr( sanitize_text_field( $meta['utm_source'] ), 0, 100 ) : '',
			'utm_medium'            => isset( $meta['utm_medium'] ) ? mb_substr( sanitize_text_field( $meta['utm_medium'] ), 0, 100 ) : '',
			'utm_campaign'          => isset( $meta['utm_campaign'] ) ? mb_substr( sanitize_text_field( $meta['utm_campaign'] ), 0, 100 ) : '',
			'visitor'               => ( isset( $meta['lejek_vid'] ) && preg_match( '/^[a-f0-9]{32}$/', $meta['lejek_vid'] ) ) ? $meta['lejek_vid'] : '',
			'stripe_session'        => substr( (string) $s['id'], 0, 100 ),
			'stripe_payment_intent' => isset( $s['payment_intent'] ) && is_string( $s['payment_intent'] ) ? substr( $s['payment_intent'], 0, 100 ) : '',
			'stripe_customer'       => isset( $s['customer'] ) && is_string( $s['customer'] ) ? substr( $s['customer'], 0, 100 ) : '',
			'note'                  => '',
		);

		$inserted = $wpdb->insert( Lejek_DB::orders(), $row );
		if ( ! $inserted ) {
			return self::get_by_session( $s['id'] ); // Zapisało je równolegle drugie wywołanie.
		}
		$id = (int) $wpdb->insert_id;

		Lejek_Events::log(
			'purchase',
			array(
				'product_id' => $product_id,
				'visitor'    => $row['visitor'],
				'utm_source' => $row['utm_source'],
			)
		);
		self::notify_admin( $id );
		do_action( 'lejek_order_created', $id, $row );

		return self::get( $id );
	}

	public static function mark_refunded( $payment_intent, $amount_refunded, $amount, $currency ) {
		global $wpdb;
		if ( ! $payment_intent ) {
			return;
		}
		$t     = Lejek_DB::orders();
		$order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE stripe_payment_intent = %s", $payment_intent ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $order ) {
			return;
		}
		$full = $amount > 0 && $amount_refunded >= $amount;
		$note = trim( $order->note . "\n" . wp_date( 'Y-m-d H:i' ) . ': ' . ( $full ? 'Zwrot całości' : 'Częściowy zwrot' ) . ' ' . Lejek_Products::money( $amount_refunded, $currency ) );
		$data = array(
			'note'       => $note,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( $full ) {
			$data['status'] = 'refunded';
		}
		$wpdb->update( $t, $data, array( 'id' => $order->id ) );
	}

	private static function notify_admin( $id ) {
		$to = Lejek_Settings::get( 'notify_email' );
		$o  = self::get( $id );
		if ( ! $to || ! $o ) {
			return;
		}
		$test    = 'test' === $o->mode ? '[TEST] ' : '';
		$subject = sprintf( '%sNowe zamówienie #%d – %s – %s', $test, $o->id, $o->product_name, Lejek_Products::money( $o->amount, $o->currency ) );
		$lines   = array(
			'Nowe zamówienie #' . $o->id . ( $test ? ' (tryb testowy)' : '' ),
			'',
			'Produkt: ' . $o->product_name,
			'Kwota: ' . Lejek_Products::money( $o->amount, $o->currency ),
			'Klient: ' . $o->name,
			'E-mail: ' . $o->email,
			'Telefon: ' . ( $o->phone ? $o->phone : 'brak' ),
			'Kraj: ' . ( $o->country ? $o->country : '?' ),
			'Źródło: ' . ( $o->utm_source ? $o->utm_source : 'bezpośrednie' ),
			'',
			'Szczegóły: ' . admin_url( 'admin.php?page=lejek-orders&order=' . $o->id ),
		);
		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}

	/* ---------- REST (odświeżanie listy na żywo) ---------- */

	public static function routes() {
		register_rest_route(
			'lejek/v1',
			'/orders/latest',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_latest' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function rest_latest() {
		global $wpdb;
		$t    = Lejek_DB::orders();
		$last = $wpdb->get_row( $wpdb->prepare( "SELECT id, amount, currency, product_name, name FROM {$t} WHERE mode = %s ORDER BY id DESC LIMIT 1", Lejek_Settings::mode() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'latest_id'    => $last ? (int) $last->id : 0,
			'latest_label' => $last ? sprintf( '#%d · %s · %s', $last->id, Lejek_Products::money( $last->amount, $last->currency ), $last->name ) : '',
			'new_count'    => self::count_new(),
		);
	}

	/* ---------- Akcje w panelu ---------- */

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	public static function handle_update() {
		self::guard( 'lejek_order_update' );
		global $wpdb;
		$id     = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		if ( $id && isset( self::statuses()[ $status ] ) ) {
			$wpdb->update(
				Lejek_DB::orders(),
				array(
					'status'     => $status,
					'note'       => $note,
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $id )
			);
			Lejek_Admin::flash( 'Zapisano zamówienie #' . $id . '.', 'success' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lejek-orders&order=' . $id ) );
		exit;
	}

	public static function handle_refund() {
		self::guard( 'lejek_order_refund' );
		$id    = isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0;
		$order = $id ? self::get( $id ) : null;
		if ( ! $order || 'refunded' === $order->status || ! $order->stripe_payment_intent ) {
			Lejek_Admin::flash( 'Tego zamówienia nie da się zwrócić.', 'error' );
		} else {
			$r = Lejek_Stripe::request( 'POST', 'refunds', array( 'payment_intent' => $order->stripe_payment_intent ), $order->mode, 'lejek-refund-' . $order->id );
			if ( is_wp_error( $r ) ) {
				Lejek_Admin::flash( 'Stripe odrzucił zwrot: ' . $r->get_error_message(), 'error' );
			} else {
				self::mark_refunded( $order->stripe_payment_intent, (int) $order->amount, (int) $order->amount, $order->currency );
				Lejek_Admin::flash( 'Zwrot wykonany w Stripe. Pieniądze wrócą do klienta w ciągu kilku dni.', 'success' );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=lejek-orders&order=' . $id ) );
		exit;
	}

	public static function handle_export() {
		self::guard( 'lejek_orders_export' );
		global $wpdb;
		$t    = Lejek_DB::orders();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE mode = %s ORDER BY id DESC", Lejek_Settings::mode() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=zamowienia-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Nr', 'Data', 'Status', 'Produkt', 'Kwota', 'Waluta', 'Imię i nazwisko', 'E-mail', 'Telefon', 'Kraj', 'Źródło', 'Kampania', 'Miesiąc miejsca', 'Notatka' ) );
		$statuses = self::statuses();
		foreach ( $rows as $o ) {
			$cells = array( $o->id, get_date_from_gmt( $o->created_at, 'Y-m-d H:i' ), isset( $statuses[ $o->status ] ) ? $statuses[ $o->status ] : $o->status, $o->product_name, number_format( $o->amount / 100, 2, '.', '' ), strtoupper( $o->currency ), $o->name, $o->email, $o->phone, $o->country, $o->utm_source, $o->utm_campaign, $o->slot_month, $o->note );
			foreach ( $cells as $i => $c ) {
				if ( is_string( $c ) && '' !== $c && in_array( $c[0], array( '=', '+', '-', '@' ), true ) && ! is_numeric( $c ) ) {
					$cells[ $i ] = "'" . $c; // Ochrona przed formułami w Excelu.
				}
			}
			fputcsv( $out, $cells );
		}
		fclose( $out );
		exit;
	}

	/* ---------- Ekrany ---------- */

	private static function phone_link( $phone ) {
		if ( ! $phone ) {
			return '<span class="lejek-muted">brak</span>';
		}
		return '<a href="tel:' . esc_attr( $phone ) . '">' . esc_html( $phone ) . '</a>';
	}

	private static function status_badge( $status ) {
		$labels = self::statuses();
		$label  = isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
		return '<span class="lejek-status lejek-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! empty( $_GET['order'] ) ) {
			self::render_detail( absint( $_GET['order'] ) );
			return;
		}

		global $wpdb;
		$t      = Lejek_DB::orders();
		$mode   = Lejek_Settings::mode();
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( ! isset( self::statuses()[ $status ] ) ) {
			$status = '';
		}
		$paged    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$per_page = 50;

		$counts = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) c FROM {$t} WHERE mode = %s GROUP BY status", $mode ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts[ $r->status ] = (int) $r->c;
		}
		$total_all = array_sum( $counts );

		if ( $status ) {
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE mode = %s AND status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $mode, $status, $per_page, ( $paged - 1 ) * $per_page ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = isset( $counts[ $status ] ) ? $counts[ $status ] : 0;
		} else {
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE mode = %s ORDER BY id DESC LIMIT %d OFFSET %d", $mode, $per_page, ( $paged - 1 ) * $per_page ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = $total_all;
		}
		$latest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$t} WHERE mode = %s", $mode ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$base   = admin_url( 'admin.php?page=lejek-orders' );
		?>
		<div class="wrap lejek-wrap">
			<h1 class="wp-heading-inline">Zamówienia</h1>
			<span class="lejek-live"><span class="lejek-dot" aria-hidden="true"></span> Na żywo – lista sprawdza nowe zamówienia co 20 sekund</span>
			<?php if ( 'test' === $mode ) : ?><span class="lejek-badge-test">tryb testowy</span><?php endif; ?>
			<hr class="wp-header-end">
			<?php Lejek_Admin::render_flash(); ?>

			<div id="lejek-orders-live" class="notice notice-success" data-latest="<?php echo esc_attr( $latest ); ?>" hidden>
				<p><strong class="lejek-live-text">Nowe zamówienie!</strong> <a href="<?php echo esc_url( $base ); ?>">Odśwież listę</a></p>
			</div>

			<ul class="subsubsub">
				<li><a href="<?php echo esc_url( $base ); ?>" <?php echo '' === $status ? 'class="current"' : ''; ?>>Wszystkie <span class="count">(<?php echo (int) $total_all; ?>)</span></a> |</li>
				<?php
				$labels = self::statuses();
				$last   = array_key_last( $labels );
				foreach ( $labels as $key => $label ) :
					?>
					<li><a href="<?php echo esc_url( add_query_arg( 'status', $key, $base ) ); ?>" <?php echo $status === $key ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ); ?>)</span></a><?php echo $key !== $last ? ' |' : ''; ?></li>
				<?php endforeach; ?>
			</ul>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lejek-export">
				<input type="hidden" name="action" value="lejek_orders_export">
				<?php wp_nonce_field( 'lejek_orders_export' ); ?>
				<button type="submit" class="button">Eksport CSV</button>
				<button type="button" class="button" id="lejek-notify">Włącz powiadomienia w przeglądarce</button>
			</form>

			<table class="widefat striped lejek-table">
				<thead><tr><th>Nr</th><th>Data</th><th>Klient</th><th>Telefon</th><th>Produkt</th><th>Kwota</th><th>Źródło</th><th>Status</th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="8">Jeszcze nie ma zamówień<?php echo 'test' === $mode ? ' w trybie testowym. Zapłać kartą 4242 4242 4242 4242, żeby zobaczyć, jak to działa.' : '.'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $o ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'order', $o->id, $base ) ); ?>"><strong>#<?php echo (int) $o->id; ?></strong></a></td>
						<td><?php echo esc_html( get_date_from_gmt( $o->created_at, 'd.m.Y H:i' ) ); ?></td>
						<td><?php echo esc_html( $o->name ); ?><br><span class="lejek-muted"><?php echo esc_html( $o->email ); ?></span></td>
						<td><?php echo wp_kses_post( self::phone_link( $o->phone ) ); ?></td>
						<td><?php echo esc_html( $o->product_name ); ?><?php echo $o->slot_month !== Lejek_Products::month_keys()[0] && $o->slot_month > Lejek_Products::month_keys()[0] ? '<br><span class="lejek-muted">rezerwacja na ' . esc_html( $o->slot_month ) . '</span>' : ''; ?></td>
						<td><?php echo esc_html( Lejek_Products::money( $o->amount, $o->currency ) ); ?></td>
						<td><?php echo esc_html( $o->utm_source ? $o->utm_source : 'bezpośrednie' ); ?></td>
						<td><?php echo wp_kses_post( self::status_badge( $o->status ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total > $per_page ) : ?>
				<p class="lejek-pages">
					<?php if ( $paged > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">← Nowsze</a><?php endif; ?>
					<?php if ( $paged * $per_page < $total ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>">Starsze →</a><?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_detail( $id ) {
		$o = self::get( $id );
		if ( ! $o ) {
			echo '<div class="wrap"><h1>Nie ma takiego zamówienia</h1></div>';
			return;
		}
		?>
		<div class="wrap lejek-wrap">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=lejek-orders' ) ); ?>">← Wszystkie zamówienia</a></p>
			<h1>Zamówienie #<?php echo (int) $o->id; ?> <?php echo wp_kses_post( self::status_badge( $o->status ) ); ?> <?php echo 'test' === $o->mode ? '<span class="lejek-badge-test">test</span>' : ''; ?></h1>
			<?php Lejek_Admin::render_flash(); ?>
			<div class="lejek-cols">
				<div class="lejek-card">
					<h2>Klient</h2>
					<p><strong><?php echo esc_html( $o->name ); ?></strong><br>
						<a href="mailto:<?php echo esc_attr( $o->email ); ?>"><?php echo esc_html( $o->email ); ?></a><br>
						Telefon: <?php echo wp_kses_post( self::phone_link( $o->phone ) ); ?><br>
						Kraj: <?php echo esc_html( $o->country ? $o->country : '?' ); ?></p>
					<h2>Zakup</h2>
					<p><?php echo esc_html( $o->product_name ); ?> · <strong><?php echo esc_html( Lejek_Products::money( $o->amount, $o->currency ) ); ?></strong><br>
						Data: <?php echo esc_html( get_date_from_gmt( $o->created_at, 'd.m.Y H:i' ) ); ?><br>
						Miejsce w miesiącu: <?php echo esc_html( $o->slot_month ); ?><br>
						Źródło: <?php echo esc_html( $o->utm_source ? $o->utm_source : 'bezpośrednie' ); ?><?php echo $o->utm_campaign ? ' · kampania: ' . esc_html( $o->utm_campaign ) : ''; ?></p>
					<?php if ( $o->stripe_payment_intent ) : ?>
						<p><a href="<?php echo esc_url( Lejek_Stripe::dashboard_payment_url( $o->stripe_payment_intent, $o->mode ) ); ?>" target="_blank" rel="noopener">Otwórz płatność w Stripe ↗</a></p>
					<?php endif; ?>
				</div>
				<div class="lejek-card">
					<h2>Obsługa</h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="lejek_order_update">
						<input type="hidden" name="order" value="<?php echo (int) $o->id; ?>">
						<?php wp_nonce_field( 'lejek_order_update' ); ?>
						<p><label for="lejek-status"><strong>Status</strong></label><br>
							<select id="lejek-status" name="status">
								<?php foreach ( self::statuses() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $o->status, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></p>
						<p><label for="lejek-note"><strong>Notatka</strong></label><br>
							<textarea id="lejek-note" name="note" rows="6" class="large-text" placeholder="np. dzwoniliśmy 12:30, oddzwonić jutro"><?php echo esc_textarea( (string) $o->note ); ?></textarea></p>
						<?php submit_button( 'Zapisz', 'primary', 'submit', false ); ?>
					</form>
					<?php if ( 'refunded' !== $o->status && $o->stripe_payment_intent ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Zwrócić klientowi całą kwotę przez Stripe? Tego nie da się cofnąć.');" class="lejek-refund">
							<input type="hidden" name="action" value="lejek_order_refund">
							<input type="hidden" name="order" value="<?php echo (int) $o->id; ?>">
							<?php wp_nonce_field( 'lejek_order_refund' ); ?>
							<button type="submit" class="button button-link-delete">Zwrot całej kwoty przez Stripe</button>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}
}

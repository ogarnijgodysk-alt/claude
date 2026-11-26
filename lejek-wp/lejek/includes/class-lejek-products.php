<?php
defined( 'ABSPATH' ) || exit;

/**
 * Produkty: cena, waluta, limit miejsc w miesiącu, synchronizacja ze Stripe, link do kasy.
 */
class Lejek_Products {

	const CPT        = 'lejek_product';
	const CURRENCIES = array( 'usd', 'eur', 'pln', 'gbp' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::CPT, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function register() {
		$cap = 'manage_options';
		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'          => 'Produkty',
					'singular_name' => 'Produkt',
					'add_new'       => 'Dodaj produkt',
					'add_new_item'  => 'Nowy produkt',
					'edit_item'     => 'Edytuj produkt',
					'all_items'     => 'Produkty',
					'search_items'  => 'Szukaj produktów',
					'not_found'     => 'Brak produktów',
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'lejek',
				'show_in_rest' => false,
				'supports'     => array( 'title' ),
				'map_meta_cap' => false,
				'capabilities' => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'delete_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					'edit_published_posts'   => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'create_posts'           => $cap,
				),
			)
		);
	}

	/* ---------- Dane produktu ---------- */

	public static function price( $id ) {
		return (int) get_post_meta( $id, '_lejek_price', true );
	}

	public static function currency( $id ) {
		$c = get_post_meta( $id, '_lejek_currency', true );
		return in_array( $c, self::CURRENCIES, true ) ? $c : 'usd';
	}

	public static function limit( $id ) {
		return max( 0, (int) get_post_meta( $id, '_lejek_limit', true ) );
	}

	public static function reservations( $id ) {
		return '0' !== (string) get_post_meta( $id, '_lejek_reservations', true );
	}

	public static function slug( $id ) {
		$slug = get_post_meta( $id, '_lejek_slug', true );
		return $slug ? $slug : sanitize_title( get_the_title( $id ) );
	}

	public static function get_by_slug( $slug ) {
		$posts = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'numberposts' => 1,
				'meta_key'    => '_lejek_slug', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'  => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		return $posts ? $posts[0] : null;
	}

	public static function buy_url( $id ) {
		return add_query_arg( 'lejek_checkout', self::slug( $id ), home_url( '/' ) );
	}

	public static function thankyou_url( $id ) {
		$page = (int) get_post_meta( $id, '_lejek_thankyou', true );
		if ( ! $page ) {
			$page = (int) Lejek_Settings::get( 'thankyou_page' );
		}
		$url = $page ? get_permalink( $page ) : '';
		return $url ? $url : home_url( '/' );
	}

	public static function money( $cents, $currency ) {
		$symbols = array(
			'usd' => '$',
			'eur' => '€',
			'gbp' => '£',
		);
		$amount = number_format( $cents / 100, 2, '.', ',' );
		return isset( $symbols[ $currency ] ) ? $symbols[ $currency ] . $amount : $amount . ' ' . strtoupper( $currency );
	}

	/* ---------- Limit miejsc ---------- */

	/** Klucze miesięcy w strefie czasowej strony: [bieżący, następny], np. ['2026-11', '2026-12']. */
	public static function month_keys() {
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		return array( $now->format( 'Y-m' ), $now->modify( 'first day of next month' )->format( 'Y-m' ) );
	}

	/**
	 * Ile miejsc zostało i do którego miesiąca trafi kolejne zamówienie.
	 * slot = bieżący miesiąc, następny (rezerwacja) albo null (wyprzedane).
	 */
	public static function availability( $id ) {
		list( $month, $next ) = self::month_keys();
		$limit = self::limit( $id );
		$mode  = Lejek_Settings::mode();
		$sold  = Lejek_Orders::count_slot( $id, $month, $mode );

		if ( 0 === $limit ) {
			return array(
				'capacity'   => 0,
				'sold'       => $sold,
				'left'       => null,
				'slot'       => $month,
				'month'      => $month,
				'next_month' => $next,
			);
		}

		$left = max( 0, $limit - $sold );
		$slot = null;
		if ( $left > 0 ) {
			$slot = $month;
		} elseif ( self::reservations( $id ) && Lejek_Orders::count_slot( $id, $next, $mode ) < $limit ) {
			$slot = $next;
		}

		return array(
			'capacity'   => $limit,
			'sold'       => $sold,
			'left'       => $left,
			'slot'       => $slot,
			'month'      => $month,
			'next_month' => $next,
		);
	}

	/* ---------- Stripe ---------- */

	public static function reset_stripe( $id, $mode = null ) {
		$mode = $mode ? $mode : Lejek_Settings::mode();
		$map  = get_post_meta( $id, '_lejek_stripe', true );
		if ( is_array( $map ) && isset( $map[ $mode ] ) ) {
			unset( $map[ $mode ] );
			update_post_meta( $id, '_lejek_stripe', $map );
		}
	}

	/**
	 * Zakłada produkt i cenę w Stripe (osobno dla trybu testowego i na żywo) albo aktualizuje je po zmianie.
	 *
	 * @return string|WP_Error ID ceny w Stripe.
	 */
	public static function ensure_stripe_price( $id, $mode = null, $retry = true ) {
		$mode   = $mode ? $mode : Lejek_Settings::mode();
		$amount = self::price( $id );
		$cur    = self::currency( $id );
		$name   = get_the_title( $id );
		if ( $amount <= 0 ) {
			return new WP_Error( 'lejek_price', 'Produkt nie ma ustawionej ceny.' );
		}

		$map = get_post_meta( $id, '_lejek_stripe', true );
		$map = is_array( $map ) ? $map : array();
		$m   = isset( $map[ $mode ] ) ? $map[ $mode ] : array();

		if ( empty( $m['product'] ) ) {
			$p = Lejek_Stripe::request(
				'POST',
				'products',
				array(
					'name'     => $name,
					'metadata' => array(
						'lejek_id'   => $id,
						'lejek_site' => Lejek_Orders::site_host(),
					),
				),
				$mode
			);
			if ( is_wp_error( $p ) ) {
				return $p;
			}
			$m = array(
				'product' => $p['id'],
				'name'    => $name,
			);
		} elseif ( ! isset( $m['name'] ) || $m['name'] !== $name ) {
			$r = Lejek_Stripe::request( 'POST', 'products/' . rawurlencode( $m['product'] ), array( 'name' => $name ), $mode );
			if ( is_wp_error( $r ) ) {
				if ( $retry && false !== stripos( $r->get_error_message(), 'No such product' ) ) {
					self::reset_stripe( $id, $mode );
					return self::ensure_stripe_price( $id, $mode, false );
				}
				return $r;
			}
			$m['name'] = $name;
		}

		if ( empty( $m['price'] ) || (int) $m['amount'] !== $amount || $m['currency'] !== $cur ) {
			$pr = Lejek_Stripe::request(
				'POST',
				'prices',
				array(
					'product'     => $m['product'],
					'unit_amount' => $amount,
					'currency'    => $cur,
				),
				$mode
			);
			if ( is_wp_error( $pr ) ) {
				if ( $retry && false !== stripos( $pr->get_error_message(), 'No such product' ) ) {
					self::reset_stripe( $id, $mode );
					return self::ensure_stripe_price( $id, $mode, false );
				}
				return $pr;
			}
			if ( ! empty( $m['price'] ) ) {
				Lejek_Stripe::request( 'POST', 'prices/' . rawurlencode( $m['price'] ), array( 'active' => false ), $mode );
			}
			$m['price']    = $pr['id'];
			$m['amount']   = $amount;
			$m['currency'] = $cur;
		}

		$map[ $mode ] = $m;
		update_post_meta( $id, '_lejek_stripe', $map );
		return $m['price'];
	}

	/* ---------- Ekran edycji ---------- */

	public static function meta_boxes() {
		add_meta_box( 'lejek_product_data', 'Cena i sprzedaż', array( __CLASS__, 'render_meta' ), self::CPT, 'normal', 'high' );
	}

	public static function render_meta( $post ) {
		wp_nonce_field( 'lejek_product_meta', 'lejek_product_nonce' );
		$price  = self::price( $post->ID );
		$cur    = self::currency( $post->ID );
		$limit  = self::limit( $post->ID );
		$res    = self::reservations( $post->ID );
		$slug   = get_post_meta( $post->ID, '_lejek_slug', true );
		$thanks = (int) get_post_meta( $post->ID, '_lejek_thankyou', true );
		$error  = get_post_meta( $post->ID, '_lejek_sync_error', true );
		$map    = get_post_meta( $post->ID, '_lejek_stripe', true );
		$mode   = Lejek_Settings::mode();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="lejek_price">Cena</label></th>
				<td>
					<input type="text" id="lejek_price" name="lejek_price" inputmode="decimal" class="small-text" value="<?php echo esc_attr( $price ? number_format( $price / 100, 2, '.', '' ) : '' ); ?>" placeholder="47.00">
					<select name="lejek_currency" aria-label="Waluta">
						<?php foreach ( self::CURRENCIES as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $cur, $c ); ?>><?php echo esc_html( strtoupper( $c ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Płatność jednorazowa.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lejek_slug">Adres w linku</label></th>
				<td>
					<input type="text" id="lejek_slug" name="lejek_slug" class="regular-text" value="<?php echo esc_attr( $slug ); ?>" placeholder="np. ai-creator">
					<p class="description">Krótka nazwa do linku kasy. Tylko małe litery, cyfry i myślniki.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lejek_limit">Limit sprzedaży na miesiąc</label></th>
				<td>
					<input type="number" min="0" id="lejek_limit" name="lejek_limit" class="small-text" value="<?php echo esc_attr( $limit ); ?>"> <span class="description">0 = bez limitu</span>
					<p><label><input type="radio" name="lejek_reservations" value="1" <?php checked( $res ); ?>> Po wyczerpaniu przyjmuj rezerwacje na następny miesiąc</label><br>
					<label><input type="radio" name="lejek_reservations" value="0" <?php checked( ! $res ); ?>> Po wyczerpaniu zablokuj kasę do końca miesiąca</label></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="lejek_thankyou">Strona podziękowania</label></th>
				<td>
					<?php
					wp_dropdown_pages(
						array(
							'name'              => 'lejek_thankyou',
							'id'                => 'lejek_thankyou',
							'selected'          => $thanks,
							'show_option_none'  => '— domyślna z Ustawień —',
							'option_none_value' => 0,
						)
					);
					?>
				</td>
			</tr>
		</table>

		<?php if ( 'publish' === $post->post_status && self::price( $post->ID ) > 0 ) : ?>
			<?php $av = self::availability( $post->ID ); ?>
			<div class="lejek-box">
				<p><strong>Link do kasy</strong> (wstaw go w przyciski na stronie):</p>
				<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( self::buy_url( $post->ID ) ); ?>" onclick="this.select()"></p>
				<?php if ( $av['capacity'] ) : ?>
					<p>Ten miesiąc: <strong><?php echo esc_html( $av['sold'] . ' z ' . $av['capacity'] ); ?></strong> miejsc zajętych (tryb <?php echo esc_html( $mode ); ?>).</p>
				<?php endif; ?>
				<p>Stripe (<?php echo esc_html( $mode ); ?>):
					<?php if ( $error ) : ?>
						<span class="lejek-err">błąd: <?php echo esc_html( $error ); ?></span>
					<?php elseif ( is_array( $map ) && ! empty( $map[ $mode ]['price'] ) ) : ?>
						<span class="lejek-ok">✓ zsynchronizowano (<?php echo esc_html( $map[ $mode ]['price'] ); ?>)</span>
					<?php else : ?>
						<span class="lejek-muted">zsynchronizuje się przy pierwszej płatności</span>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['lejek_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lejek_product_nonce'] ) ), 'lejek_product_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$in    = wp_unslash( $_POST );
		$price = isset( $in['lejek_price'] ) ? str_replace( array( ',', ' ' ), array( '.', '' ), (string) $in['lejek_price'] ) : '';
		update_post_meta( $post_id, '_lejek_price', is_numeric( $price ) ? (int) round( (float) $price * 100 ) : 0 );

		$cur = isset( $in['lejek_currency'] ) ? sanitize_key( $in['lejek_currency'] ) : 'usd';
		update_post_meta( $post_id, '_lejek_currency', in_array( $cur, self::CURRENCIES, true ) ? $cur : 'usd' );

		update_post_meta( $post_id, '_lejek_limit', isset( $in['lejek_limit'] ) ? absint( $in['lejek_limit'] ) : 0 );
		update_post_meta( $post_id, '_lejek_reservations', ( isset( $in['lejek_reservations'] ) && '0' === (string) $in['lejek_reservations'] ) ? '0' : '1' );
		update_post_meta( $post_id, '_lejek_thankyou', isset( $in['lejek_thankyou'] ) ? absint( $in['lejek_thankyou'] ) : 0 );

		$slug = sanitize_title( isset( $in['lejek_slug'] ) && '' !== trim( $in['lejek_slug'] ) ? $in['lejek_slug'] : $post->post_title );
		if ( '' === $slug ) {
			$slug = 'produkt-' . $post_id;
		}
		$base = $slug;
		$n    = 2;
		while ( true ) {
			$other = self::get_by_slug( $slug );
			if ( ! $other || (int) $other->ID === (int) $post_id ) {
				break;
			}
			$slug = $base . '-' . $n++;
		}
		update_post_meta( $post_id, '_lejek_slug', $slug );

		if ( 'publish' === $post->post_status && self::price( $post_id ) > 0 && '' !== Lejek_Settings::secret_key() ) {
			$r = self::ensure_stripe_price( $post_id );
			if ( is_wp_error( $r ) ) {
				update_post_meta( $post_id, '_lejek_sync_error', $r->get_error_message() );
			} else {
				delete_post_meta( $post_id, '_lejek_sync_error' );
			}
		}
	}

	/* ---------- Lista produktów ---------- */

	public static function columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['lejek_price'] = 'Cena';
				$out['lejek_spots'] = 'Miejsca w tym miesiącu';
				$out['lejek_link']  = 'Link do kasy';
			}
		}
		unset( $out['date'] );
		return $out;
	}

	public static function column( $col, $post_id ) {
		if ( 'lejek_price' === $col ) {
			$p = self::price( $post_id );
			echo $p ? esc_html( self::money( $p, self::currency( $post_id ) ) ) : '—';
		} elseif ( 'lejek_spots' === $col ) {
			$av = self::availability( $post_id );
			echo $av['capacity'] ? esc_html( $av['sold'] . ' / ' . $av['capacity'] ) : 'bez limitu';
		} elseif ( 'lejek_link' === $col ) {
			echo '<code>' . esc_html( self::buy_url( $post_id ) ) . '</code>';
		}
	}
}

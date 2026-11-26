<?php
defined( 'ABSPATH' ) || exit;

/**
 * Menu „Lejek” w panelu, komunikaty, lista kontrolna konfiguracji i ekran statystyk.
 */
class Lejek_Admin {

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_menu', array( __CLASS__, 'menu_settings' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_menu_page( 'Lejek', 'Lejek', 'manage_options', 'lejek', array( __CLASS__, 'render_stats' ), 'dashicons-filter', 56 );
		add_submenu_page( 'lejek', 'Statystyki', 'Statystyki', 'manage_options', 'lejek', array( __CLASS__, 'render_stats' ) );
		$new    = Lejek_Orders::count_new();
		$bubble = $new ? ' <span class="awaiting-mod">' . (int) $new . '</span>' : '';
		add_submenu_page( 'lejek', 'Zamówienia', 'Zamówienia' . $bubble, 'manage_options', 'lejek-orders', array( 'Lejek_Orders', 'render_page' ) );
	}

	public static function menu_settings() {
		add_submenu_page( 'lejek', 'Ustawienia', 'Ustawienia', 'manage_options', 'lejek-settings', array( 'Lejek_Settings', 'render' ) );
	}

	private static function is_lejek_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return false !== strpos( $screen->id, 'lejek' ) || Lejek_Products::CPT === $screen->post_type;
	}

	public static function assets() {
		if ( ! self::is_lejek_screen() ) {
			return;
		}
		wp_enqueue_style( 'lejek-admin', LEJEK_URL . 'assets/admin.css', array(), LEJEK_VERSION );
		wp_enqueue_script( 'lejek-admin', LEJEK_URL . 'assets/admin.js', array(), LEJEK_VERSION, true );
		wp_localize_script(
			'lejek-admin',
			'LejekAdmin',
			array(
				'rest'  => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/* ---------- Komunikaty po przekierowaniu ---------- */

	public static function flash( $message, $type = 'success' ) {
		$key   = 'lejek_flash_' . get_current_user_id();
		$queue = get_transient( $key );
		$queue = is_array( $queue ) ? $queue : array();
		$queue[] = array(
			'm' => $message,
			't' => $type,
		);
		set_transient( $key, $queue, 120 );
	}

	public static function render_flash() {
		$key   = 'lejek_flash_' . get_current_user_id();
		$queue = get_transient( $key );
		if ( ! is_array( $queue ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $queue as $item ) {
			$type = in_array( $item['t'], array( 'success', 'error', 'warning', 'info' ), true ) ? $item['t'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $item['m'] ) . '</p></div>';
		}
	}

	/* ---------- Lista kontrolna ---------- */

	private static function checklist() {
		$mode  = Lejek_Settings::mode();
		$items = array(
			array( '' !== Lejek_Settings::secret_key(), 'Klucz tajny Stripe (' . $mode . ')', admin_url( 'admin.php?page=lejek-settings' ) ),
			array( '' !== Lejek_Settings::webhook_secret( $mode ), 'Webhook Stripe (' . $mode . ')', admin_url( 'admin.php?page=lejek-settings' ) ),
			array( (bool) Lejek_Settings::get( 'thankyou_page' ), 'Strona podziękowania z kodem [lejek_thankyou]', admin_url( 'admin.php?page=lejek-settings' ) ),
			array( (bool) wp_count_posts( Lejek_Products::CPT )->publish, 'Co najmniej jeden opublikowany produkt', admin_url( 'post-new.php?post_type=' . Lejek_Products::CPT ) ),
		);
		$missing = array_filter(
			$items,
			function ( $i ) {
				return ! $i[0];
			}
		);
		if ( ! $missing ) {
			return;
		}
		echo '<div class="lejek-card lejek-setup"><h2>Do skonfigurowania</h2><ul>';
		foreach ( $items as $i ) {
			echo '<li>' . ( $i[0] ? '<span class="lejek-ok">✓</span> ' . esc_html( $i[1] ) : '<span class="lejek-err">✗</span> <a href="' . esc_url( $i[2] ) . '">' . esc_html( $i[1] ) . '</a>' ) . '</li>';
		}
		echo '</ul></div>';
	}

	/* ---------- Statystyki ---------- */

	public static function render_stats() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$ev    = Lejek_DB::events();
		$ot    = Lejek_DB::orders();
		$mode  = Lejek_Settings::mode();
		$days  = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification
		$days  = in_array( $days, array( 7, 30, 90 ), true ) ? $days : 30;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$tz    = wp_timezone();
		$off   = (int) $tz->getOffset( new DateTime( 'now', $tz ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$visitors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT visitor) FROM {$ev} WHERE type = 'view' AND created_at >= %s", $since ) );
		$starts   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ev} WHERE type = 'checkout_start' AND created_at >= %s", $since ) );
		$orders   = $wpdb->get_results( $wpdb->prepare( "SELECT amount, currency, status, utm_source, created_at FROM {$ot} WHERE mode = %s AND created_at >= %s", $mode, $since ) );
		$daily_v  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) d, COUNT(DISTINCT visitor) c FROM {$ev} WHERE type = 'view' AND created_at >= %s GROUP BY d", $off, $since ), OBJECT_K );
		$src_v    = $wpdb->get_results( $wpdb->prepare( "SELECT utm_source s, COUNT(DISTINCT visitor) c FROM {$ev} WHERE type = 'view' AND created_at >= %s GROUP BY utm_source ORDER BY c DESC LIMIT 20", $since ) );
		// phpcs:enable

		$paid     = 0;
		$refunds  = 0;
		$revenue  = array();
		$daily_r  = array();
		$src_o    = array();
		foreach ( $orders as $o ) {
			if ( 'refunded' === $o->status ) {
				++$refunds;
				continue;
			}
			++$paid;
			$revenue[ $o->currency ] = ( isset( $revenue[ $o->currency ] ) ? $revenue[ $o->currency ] : 0 ) + (int) $o->amount;
			$d                       = get_date_from_gmt( $o->created_at, 'Y-m-d' );
			$daily_r[ $d ]           = ( isset( $daily_r[ $d ] ) ? $daily_r[ $d ] : 0 ) + (int) $o->amount;
			$s                       = $o->utm_source ? $o->utm_source : '';
			if ( ! isset( $src_o[ $s ] ) ) {
				$src_o[ $s ] = array(
					'n' => 0,
					'r' => 0,
				);
			}
			++$src_o[ $s ]['n'];
			$src_o[ $s ]['r'] += (int) $o->amount;
		}
		arsort( $revenue );
		$cur      = $revenue ? array_key_first( $revenue ) : 'usd';
		$rev_main = $revenue ? reset( $revenue ) : 0;

		$series = array();
		$now    = new DateTimeImmutable( 'now', $tz );
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d        = $now->modify( '-' . $i . ' days' )->format( 'Y-m-d' );
			$series[] = array(
				'd' => $d,
				'v' => isset( $daily_v[ $d ] ) ? (int) $daily_v[ $d ]->c : 0,
				'r' => isset( $daily_r[ $d ] ) ? $daily_r[ $d ] : 0,
			);
		}
		$max_r = max( 1, max( array_column( $series, 'r' ) ) );

		$pct = function ( $a, $b ) {
			return $b > 0 ? number_format_i18n( $a / $b * 100, 1 ) . '%' : '—';
		};
		$base = admin_url( 'admin.php?page=lejek' );
		?>
		<div class="wrap lejek-wrap">
			<h1 class="wp-heading-inline">Statystyki</h1>
			<?php if ( 'test' === $mode ) : ?><span class="lejek-badge-test">tryb testowy – liczymy zamówienia testowe</span><?php endif; ?>
			<hr class="wp-header-end">
			<?php self::render_flash(); ?>
			<?php self::checklist(); ?>

			<p class="lejek-range">
				<?php foreach ( array( 7, 30, 90 ) as $r ) : ?>
					<a class="button <?php echo $r === $days ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'range', $r, $base ) ); ?>"><?php echo (int) $r; ?> dni</a>
				<?php endforeach; ?>
			</p>

			<div class="lejek-kpis">
				<div class="lejek-kpi"><span>Przychód</span><strong><?php echo esc_html( Lejek_Products::money( $rev_main, $cur ) ); ?></strong></div>
				<div class="lejek-kpi"><span>Zamówienia</span><strong><?php echo (int) $paid; ?></strong><?php echo $refunds ? '<em>zwroty: ' . (int) $refunds . '</em>' : ''; ?></div>
				<div class="lejek-kpi"><span>Odwiedzający</span><strong><?php echo esc_html( number_format_i18n( $visitors ) ); ?></strong></div>
				<div class="lejek-kpi"><span>Konwersja (odwiedzający → zakup)</span><strong><?php echo esc_html( $pct( $paid, $visitors ) ); ?></strong></div>
			</div>

			<div class="lejek-card">
				<h2>Przychód dziennie</h2>
				<div class="lejek-bars" role="img" aria-label="Przychód dziennie z ostatnich <?php echo (int) $days; ?> dni">
					<?php foreach ( $series as $p ) : ?>
						<span class="lejek-bar" title="<?php echo esc_attr( wp_date( 'j M', strtotime( $p['d'] . ' 12:00:00' ) ) . ': ' . Lejek_Products::money( $p['r'], $cur ) . ' · ' . $p['v'] . ' odw.' ); ?>"><i style="height:<?php echo esc_attr( round( $p['r'] / $max_r * 100, 1 ) ); ?>%"></i></span>
					<?php endforeach; ?>
				</div>
				<p class="lejek-muted">Najedź na słupek, żeby zobaczyć dzień.</p>
			</div>

			<div class="lejek-cols">
				<div class="lejek-card">
					<h2>Lejek – gdzie odpadają klienci</h2>
					<?php
					$steps = array(
						array( 'Odwiedzający', $visitors ),
						array( 'Rozpoczęli płatność', $starts ),
						array( 'Zapłacili', $paid ),
					);
					$top   = max( 1, $visitors, $starts, $paid );
					foreach ( $steps as $i => $st ) :
						?>
						<?php if ( $i > 0 && $steps[ $i - 1 ][1] > 0 ) : ?>
							<p class="lejek-drop">↓ <?php echo esc_html( $pct( max( 0, $steps[ $i - 1 ][1] - $st[1] ), $steps[ $i - 1 ][1] ) ); ?> odpada</p>
						<?php endif; ?>
						<div class="lejek-step"><span><?php echo esc_html( $st[0] ); ?></span><strong><?php echo esc_html( number_format_i18n( $st[1] ) ); ?></strong></div>
						<div class="lejek-meter"><i style="width:<?php echo esc_attr( max( 0.5, round( $st[1] / $top * 100, 1 ) ) ); ?>%"></i></div>
					<?php endforeach; ?>
				</div>

				<div class="lejek-card">
					<h2>Miejsca w miesiącu</h2>
					<?php
					$products = get_posts(
						array(
							'post_type'   => Lejek_Products::CPT,
							'post_status' => 'publish',
							'numberposts' => 50,
						)
					);
					$shown    = 0;
					foreach ( $products as $p ) :
						$av = Lejek_Products::availability( $p->ID );
						if ( ! $av['capacity'] ) {
							continue;
						}
						++$shown;
						$next = Lejek_Orders::count_slot( $p->ID, $av['next_month'], $mode );
						?>
						<div class="lejek-step"><span><?php echo esc_html( get_the_title( $p ) ); ?></span><strong><?php echo esc_html( $av['sold'] . ' z ' . $av['capacity'] ); ?></strong></div>
						<div class="lejek-meter"><i style="width:<?php echo esc_attr( round( min( 1, $av['sold'] / $av['capacity'] ) * 100, 1 ) ); ?>%"></i></div>
						<p class="lejek-muted"><?php echo esc_html( $av['month'] ); ?> · zostało <?php echo (int) $av['left']; ?><?php echo $next ? ' · rezerwacje na ' . esc_html( $av['next_month'] ) . ': ' . (int) $next : ''; ?></p>
					<?php endforeach; ?>
					<?php if ( ! $shown ) : ?>
						<p class="lejek-muted">Żaden produkt nie ma limitu miejsc.</p>
					<?php endif; ?>
				</div>
			</div>

			<div class="lejek-card">
				<h2>Źródła ruchu</h2>
				<table class="widefat striped">
					<thead><tr><th>Źródło</th><th>Odwiedzający</th><th>Zakupy</th><th>Konwersja</th><th>Przychód</th></tr></thead>
					<tbody>
					<?php
					$rows = array();
					foreach ( $src_v as $r ) {
						$rows[ (string) $r->s ] = array( (int) $r->c, 0, 0 );
					}
					foreach ( $src_o as $s => $v ) {
						if ( ! isset( $rows[ $s ] ) ) {
							$rows[ $s ] = array( 0, 0, 0 );
						}
						$rows[ $s ][1] = $v['n'];
						$rows[ $s ][2] = $v['r'];
					}
					if ( ! $rows ) {
						echo '<tr><td colspan="5">Brak danych w tym okresie.</td></tr>';
					}
					foreach ( $rows as $s => $v ) :
						?>
						<tr>
							<td><?php echo esc_html( '' === $s ? 'bezpośrednie' : $s ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $v[0] ) ); ?></td>
							<td><?php echo (int) $v[1]; ?></td>
							<td><?php echo esc_html( $pct( $v[1], $v[0] ) ); ?></td>
							<td><?php echo esc_html( Lejek_Products::money( $v[2], $cur ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="lejek-muted">Źródło bierzemy z parametru utm_source w linku (np. link w bio: <code>?utm_source=instagram</code>), a gdy go nie ma – ze strony, z której ktoś przyszedł.</p>
			</div>
		</div>
		<?php
	}
}

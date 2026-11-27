<?php
/**
 * Ustawienia → Avatar Capture: połączenie z Dyskiem Google i czasy nagrań.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ampac_get_script_url() {
	return (string) get_option( 'ampac_script_url', '' );
}

function ampac_sanitize_script_url( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	if ( ! preg_match( '#^https://script\.google\.com/(a/macros/[^/]+|macros)/s/[A-Za-z0-9_-]+/exec$#', $value ) ) {
		add_settings_error( 'ampac_script_url', 'ampac_bad_url', 'Adres skryptu musi wyglądać tak: https://script.google.com/macros/s/…/exec' );
		return ampac_get_script_url();
	}
	return esc_url_raw( $value );
}

function ampac_sanitize_seconds( $value ) {
	return max( 10, min( 900, absint( $value ) ) );
}

function ampac_register_settings() {
	register_setting( 'ampac', 'ampac_script_url', array( 'type' => 'string', 'sanitize_callback' => 'ampac_sanitize_script_url', 'default' => '' ) );
	register_setting( 'ampac', 'ampac_min_sec', array( 'type' => 'integer', 'sanitize_callback' => 'ampac_sanitize_seconds', 'default' => 120 ) );
	register_setting( 'ampac', 'ampac_max_sec', array( 'type' => 'integer', 'sanitize_callback' => 'ampac_sanitize_seconds', 'default' => 300 ) );
}
add_action( 'admin_init', 'ampac_register_settings' );

function ampac_admin_menu() {
	add_options_page( 'Avatar Capture', 'Avatar Capture', 'manage_options', 'ampac', 'ampac_render_settings' );
}
add_action( 'admin_menu', 'ampac_admin_menu' );

function ampac_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$script_code = file_get_contents( AMPAC_DIR . 'apps-script/Code.gs' );
	$script_url  = ampac_get_script_url();
	?>
	<div class="wrap">
		<h1>Avatar Capture</h1>

		<h2>Linki</h2>
		<p>
			Dla klientów: <a href="<?php echo esc_url( ampac_app_url() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( ampac_app_url() ); ?></code></a><br>
			Tryb testowy (z diagnostyką): <a href="<?php echo esc_url( ampac_app_url( true ) ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( ampac_app_url( true ) ); ?></code></a>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'ampac' ); ?>

			<h2>Dysk Google</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ampac_script_url">Adres skryptu (…/exec)</label></th>
					<td>
						<input type="url" class="large-text code" id="ampac_script_url" name="ampac_script_url" value="<?php echo esc_attr( $script_url ); ?>" placeholder="https://script.google.com/macros/s/…/exec">
						<p>
							<button type="button" class="button" id="ampac-ping">Sprawdź połączenie</button>
							<span id="ampac-ping-result" style="margin-left:8px"></span>
						</p>
						<p class="description">Bez tego adresu nagrania nie trafią na Dysk – klient będzie mógł je tylko pobrać na telefon.</p>
					</td>
				</tr>
			</table>

			<h2>Nagrywanie</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ampac_min_sec">Minimalna długość (s)</label></th>
					<td><input type="number" min="10" max="900" id="ampac_min_sec" name="ampac_min_sec" value="<?php echo esc_attr( get_option( 'ampac_min_sec', 120 ) ); ?>" class="small-text"> <span class="description">HeyGen zaleca co najmniej 120 s.</span></td>
				</tr>
				<tr>
					<th scope="row"><label for="ampac_max_sec">Automatyczny stop (s)</label></th>
					<td><input type="number" min="10" max="900" id="ampac_max_sec" name="ampac_max_sec" value="<?php echo esc_attr( get_option( 'ampac_max_sec', 300 ) ); ?>" class="small-text"> <span class="description">HeyGen: maksymalnie ok. 300 s.</span></td>
				</tr>
			</table>
			<?php submit_button( 'Zapisz' ); ?>
		</form>

		<h2>Jak podłączyć Dysk Google (jednorazowo, ok. 3 minuty)</h2>
		<ol>
			<li>Na komputerze, zalogowany na konto Google, na którym mają być nagrania, otwórz <a href="https://script.google.com/home/projects/create" target="_blank" rel="noopener">script.google.com → Nowy projekt</a>.</li>
			<li>Usuń całą zawartość pliku <code>Code.gs</code> i wklej kod z pola poniżej (przycisk „Kopiuj kod”). Zapisz (ikona dyskietki).</li>
			<li>Kliknij <b>Wdróż → Nowe wdrożenie</b>, przy „Wybierz typ” kliknij ikonę koła zębatego → <b>Aplikacja internetowa</b>.</li>
			<li>Ustaw: <b>Wykonaj jako: Ja</b> oraz <b>Kto ma dostęp: Każdy</b>. Kliknij <b>Wdróż</b>.</li>
			<li>Google poprosi o zgody: <b>Autoryzuj dostęp</b> → wybierz konto → przy „Google nie zweryfikował tej aplikacji” kliknij <b>Zaawansowane → Przejdź do projektu</b> → <b>Zezwól</b>. To Twój własny skrypt, więc to normalne.</li>
			<li>Skopiuj <b>Adres URL aplikacji internetowej</b> (kończy się na <code>/exec</code>), wklej go wyżej i kliknij <b>Zapisz</b>, potem <b>Sprawdź połączenie</b>.</li>
		</ol>
		<p>Nagrania trafią do folderu <b>Avatar footage</b> na tym Dysku – każdy klient dostaje własny podfolder. Po każdym nagraniu przyjdzie mail z linkiem.</p>
		<p><button type="button" class="button button-primary" id="ampac-copy">Kopiuj kod</button> <span id="ampac-copy-result" style="margin-left:8px"></span></p>
		<textarea id="ampac-code" class="large-text code" rows="16" readonly><?php echo esc_textarea( $script_code ); ?></textarea>
	</div>
	<script>
	(function () {
		var copyBtn = document.getElementById('ampac-copy');
		var code = document.getElementById('ampac-code');
		var copyOut = document.getElementById('ampac-copy-result');
		copyBtn.addEventListener('click', function () {
			function done() { copyOut.textContent = 'Skopiowano.'; }
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(code.value).then(done, function () { code.select(); document.execCommand('copy'); done(); });
			} else { code.select(); document.execCommand('copy'); done(); }
		});
		var pingBtn = document.getElementById('ampac-ping');
		var pingOut = document.getElementById('ampac-ping-result');
		pingBtn.addEventListener('click', function () {
			var url = document.getElementById('ampac_script_url').value.trim();
			if (!url) { pingOut.textContent = 'Najpierw wklej adres skryptu.'; return; }
			pingOut.textContent = 'Sprawdzam…';
			fetch(url, { method: 'POST', body: JSON.stringify({ action: 'ping' }) })
				.then(function (r) { return r.json(); })
				.then(function (j) { pingOut.textContent = j && j.ok ? '✓ Połączenie działa (wersja skryptu ' + j.version + ').' : 'Skrypt odpowiada, ale z błędem: ' + (j && j.error); })
				.catch(function (e) { pingOut.textContent = 'Brak połączenia: ' + e.message + '. Sprawdź adres i ustawienie „Kto ma dostęp: Każdy”.'; });
		});
	})();
	</script>
	<?php
}

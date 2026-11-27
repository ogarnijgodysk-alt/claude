<?php
/**
 * Strona testowa: kamera, nagrywanie, pytania i wyciszanie mikrofonu.
 *
 * @var string $ampac_assets Adres katalogu assets/ wtyczki.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$ampac_config = array(
	'audioBase' => $ampac_assets . 'audio/',
	'version'   => AMPAC_VERSION,
);
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>Test kamery – AI Models Partners</title>
<link rel="stylesheet" href="<?php echo esc_url( $ampac_assets . 'test.css?v=' . AMPAC_VERSION ); ?>">
</head>
<body>
<main class="wrap">
	<header>
		<h1>Test kamery i nagrywania</h1>
		<p class="sub">AI Models Partners · wersja testowa <?php echo esc_html( AMPAC_VERSION ); ?></p>
	</header>

	<section class="card">
		<h2>Jak przeprowadzić test</h2>
		<ol class="steps">
			<li>Podgłośnij telefon. Postaw go pionowo, <b>tylnym aparatem do siebie</b>, ok. 1 m od twarzy.</li>
			<li>Kliknij <b>Włącz kamerę</b> i zezwól na kamerę i mikrofon. Przez 2 sekundy nic nie mów (kalibracja ciszy).</li>
			<li>Kliknij <b>Nagrywaj</b> i cały czas głośno odliczaj. Po ok. 10 s telefon zada pytanie – <b>nie przestawaj liczyć</b>.</li>
			<li>Potem odpowiedz na pytanie i zamilknij na 4 s – telefon sam zada kolejne. Po ok. 45 s kliknij <b>Stop</b>.</li>
			<li>Obejrzyj nagranie, kliknij <b>Kopiuj raport</b> i wklej go w czacie z Claude.</li>
		</ol>
	</section>

	<section class="card settings" aria-label="Ustawienia testu">
		<label for="sel-res">Rozdzielczość
			<select id="sel-res">
				<option value="1080">Full HD (1080p)</option>
				<option value="4k">4K (jeśli telefon pozwoli)</option>
			</select>
		</label>
		<label for="sel-cam">Kamera
			<select id="sel-cam" disabled>
				<option value="">tylna (domyślna)</option>
			</select>
		</label>
		<label for="sel-voice">Głos pytań
			<select id="sel-voice">
				<option value="file">plik audio</option>
				<option value="speech">głos przeglądarki</option>
			</select>
		</label>
		<label class="chk" for="chk-mute"><input type="checkbox" id="chk-mute" checked> Wyciszaj mikrofon w nagraniu podczas pytania</label>
		<label class="chk" for="chk-silence"><input type="checkbox" id="chk-silence" checked> Zadaj pytanie po 3 s ciszy</label>
		<label class="chk" for="chk-forced"><input type="checkbox" id="chk-forced" checked> Pytanie testowe po 10 s nagrywania</label>
	</section>

	<section class="stage" aria-label="Kamera">
		<div class="viewport">
			<video id="preview" playsinline muted autoplay></video>
			<div class="empty" id="empty">Kamera wyłączona</div>
			<div class="ov-top">
				<span id="rec-badge" class="badge" hidden><span class="dot"></span>REC <span id="timer">0:00</span></span>
				<span id="mute-badge" class="badge mute" hidden>Mikrofon wyciszony – pytanie</span>
			</div>
			<div class="ov-bottom">
				<div class="meter" aria-hidden="true"><div id="level" class="meter-bar"></div><div id="thr" class="meter-thr"></div></div>
				<span id="speak-state" class="badge">cisza</span>
			</div>
		</div>
		<div class="controls">
			<button type="button" id="btn-start" class="primary">Włącz kamerę</button>
			<button type="button" id="btn-rec" class="rec" disabled>Nagrywaj</button>
			<button type="button" id="btn-stop" disabled>Stop</button>
			<button type="button" id="btn-ask" disabled>Zadaj pytanie teraz</button>
		</div>
		<p id="status" class="status" role="status">Gotowe do startu.</p>
		<p id="orient" class="warn" hidden>Obróć telefon pionowo.</p>
	</section>

	<section class="card result" id="result" hidden>
		<h2>Wynik</h2>
		<video id="playback" playsinline controls></video>
		<dl id="facts" class="facts"></dl>
		<h3>Momenty z pytaniami</h3>
		<ul id="mutes" class="mutes"></ul>
		<p class="hint">Kliknij moment, żeby go odtworzyć. Powinna być w nim cisza – bez głosu pytania i bez Twojego liczenia. Sprawdź też, czy ruch ust zgadza się z dźwiękiem i czy obraz jest pionowy i ostry.</p>
		<div class="row">
			<a id="btn-download" class="button" href="#" download>Pobierz nagranie</a>
			<button type="button" id="btn-copy" class="primary">Kopiuj raport</button>
		</div>
		<label class="sr" for="report">Raport z testu</label>
		<textarea id="report" rows="12" readonly></textarea>
	</section>

	<details class="card">
		<summary>Dziennik zdarzeń</summary>
		<ol id="log" class="log"></ol>
	</details>
</main>
<script>window.AMPAC_TEST = <?php echo wp_json_encode( $ampac_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?>;</script>
<script src="<?php echo esc_url( $ampac_assets . 'test.js?v=' . AMPAC_VERSION ); ?>"></script>
</body>
</html>

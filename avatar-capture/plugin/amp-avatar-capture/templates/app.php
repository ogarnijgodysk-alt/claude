<?php
/**
 * Aplikacja nagrywania dla klienta (angielska). W trybie testowym z panelem diagnostyki.
 *
 * @var array  $ampac_config Konfiguracja przekazywana do JS.
 * @var bool   $ampac_debug  Tryb testowy.
 * @var string $ampac_assets Adres katalogu assets/.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$ampac_v = rawurlencode( AMPAC_VERSION );
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#F5F5F2">
<title>Record your avatar footage – AI Models Partners</title>
<link rel="stylesheet" href="<?php echo esc_url( $ampac_assets . 'app.css?v=' . $ampac_v ); ?>">
</head>
<body class="<?php echo $ampac_debug ? 'is-debug' : ''; ?>">

<section class="screen light" id="scr-welcome">
	<div class="inner">
		<div class="brand"><span class="logo" aria-hidden="true"></span>AI MODELS PARTNERS<?php if ( $ampac_debug ) : ?> <span class="test-tag">TEST</span><?php endif; ?></div>
		<h1>Let’s record your AI avatar footage</h1>
		<p class="lead">2 short videos, about 10 minutes. The app checks your framing, light and sound – and tells you out loud what to fix.</p>
		<ol class="shots">
			<li><span class="num">1</span><span class="txt"><b>Close-up</b><small>Chest up, answering a few easy questions</small></span><span class="pill">2–3 min</span></li>
			<li><span class="num">2</span><span class="txt"><b>Full body</b><small>Head to toe, standing</small></span><span class="pill">2–3 min</span></li>
		</ol>
		<h2 class="eyebrow">Before you start</h2>
		<ul class="tips">
			<li><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="2" width="6" height="9" rx="1.5"/><path d="M12 11v4M12 15l-6 7M12 15l6 7M12 15v7"/></svg>Phone stand or tripod</li>
			<li><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>Light in front of you</li>
			<li><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v4"/></svg>Quiet room</li>
			<li><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h4l5-4v14l-5-4H4z"/><path d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12"/></svg>Volume up</li>
		</ul>
		<div class="grow"></div>
		<label class="field-label" for="inp-name">Your first and last name</label>
		<input id="inp-name" class="field" type="text" autocomplete="name" autocapitalize="words" placeholder="e.g. Mike Reynolds" maxlength="80">
		<p id="name-error" class="error" hidden>Please enter your first and last name.</p>
		<button type="button" id="btn-welcome" class="btn primary">Get started</button>
	</div>
</section>

<section class="screen light" id="scr-setup" hidden>
	<div class="inner">
		<div class="topbar">
			<button type="button" class="icon-btn" id="btn-setup-back" aria-label="Back"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg></button>
			<div class="progress-wrap"><span id="setup-step">Video 1 of 2</span><div class="bars"><i class="on"></i><i id="bar2"></i></div></div>
		</div>
		<h1 id="setup-title">Close-up · chest up</h1>
		<p class="lead" id="setup-sub">2–3 minutes answering easy questions. No script needed.</p>
		<div class="card illu">
			<svg id="illu-closeup" viewBox="0 0 112 198" role="img" aria-label="Correct framing: head and chest fill the frame, eyes on the upper third">
				<rect x="1" y="1" width="110" height="196" rx="14" fill="#1C1C21"/>
				<rect x="45" y="92" width="22" height="34" rx="9" fill="#7E7E88"/>
				<ellipse cx="56" cy="72" rx="23" ry="28" fill="#9A9AA4"/>
				<path d="M8 197C8 150 26 122 56 122s48 28 48 75z" fill="#5B5F70"/>
				<line x1="4" y1="66" x2="108" y2="66" stroke="#3DDC84" stroke-width="1.5" stroke-dasharray="4 3"/>
			</svg>
			<svg id="illu-fullbody" viewBox="0 0 112 198" role="img" aria-label="Correct framing: whole body from head to toe" hidden>
				<rect x="1" y="1" width="110" height="196" rx="14" fill="#1C1C21"/>
				<rect x="1" y="150" width="110" height="47" fill="#26262C"/>
				<ellipse cx="56" cy="34" rx="9" ry="11" fill="#9A9AA4"/>
				<path d="M45 48q11-4 22 0l6 7 2 48-5 1-2-36-1 47-2 60h-8l-1-55h-2l-1 55h-8l-2-60-1-47-2 36-5-1 2-48z" fill="#5B5F70"/>
			</svg>
			<div class="illu-text">
				<b id="illu-distance">3–4 ft away</b>
				<span id="illu-height">Phone upright, lens at your eye level.</span>
			</div>
		</div>
		<ul class="checklist" id="setup-tips"></ul>
		<div class="grow"></div>
		<button type="button" id="btn-open-camera" class="btn primary">Open camera</button>
	</div>
</section>

<section class="screen cam" id="scr-camera" hidden>
	<div class="stage" id="stage">
		<video id="preview" playsinline muted autoplay></video>
		<svg id="guide" class="guide" aria-hidden="true"></svg>
	</div>
	<div class="cam-top">
		<div class="checks" id="checks">
			<div class="chk" data-check="frame"><span class="ico"></span><span>Frame</span></div>
			<div class="chk" data-check="light"><span class="ico"></span><span>Light</span></div>
			<div class="chk" data-check="level"><span class="ico"></span><span>Level</span></div>
			<div class="chk" data-check="sound"><span class="ico"></span><span>Sound</span></div>
			<div class="chk" data-check="steady"><span class="ico"></span><span>Steady</span></div>
		</div>
		<div class="banner" id="banner">
			<span class="banner-ico" id="banner-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h4l5-4v14l-5-4H4z"/><path d="M16.5 8.5a5 5 0 0 1 0 7M19 6a8.5 8.5 0 0 1 0 12"/></svg></span>
			<div><div class="banner-title" id="banner-title">Starting camera…</div><div class="banner-sub" id="banner-sub"></div></div>
		</div>
		<div class="rec-row" id="rec-row" hidden>
			<div class="rec-pill"><span class="dot"></span>REC <span id="rec-time">0:00</span></div>
			<div class="rec-progress"><i id="rec-bar"></i></div>
			<div class="rec-badge" id="rec-q" hidden></div>
			<div class="rec-badge warn" id="rec-mute" hidden>Mic paused</div>
		</div>
	</div>
	<div class="countdown" id="countdown" hidden>3</div>
	<div class="cam-bottom">
		<p class="cam-caption" id="cam-caption"></p>
		<div class="cam-controls">
			<button type="button" class="icon-btn dark" id="btn-cam-back" aria-label="Back"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
			<button type="button" class="round" id="btn-main" aria-label="Start recording"><span class="round-in"></span></button>
			<button type="button" class="icon-btn dark on" id="btn-voice" aria-label="Voice guidance" aria-pressed="true"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9h4l5-4v14l-5-4H4z"/><path d="M16.5 8.5a5 5 0 0 1 0 7"/></svg></button>
		</div>
	</div>
</section>

<section class="screen light" id="scr-review" hidden>
	<div class="inner">
		<span class="step-label" id="review-step">Video 1 of 2 · Close-up</span>
		<video id="playback" playsinline controls></video>
		<div class="verdict" id="verdict">
			<span class="verdict-ico" id="verdict-ico"></span>
			<div><b id="verdict-title">Looks great!</b><span id="verdict-sub"></span></div>
		</div>
		<ul class="results" id="review-checks"></ul>
		<div class="grow"></div>
		<button type="button" id="btn-send" class="btn primary">Send video</button>
		<button type="button" id="btn-again" class="btn secondary">Record again</button>
	</div>
</section>

<section class="screen light" id="scr-upload" hidden>
	<div class="inner center">
		<span class="step-label" id="upload-step">Video 1 of 2 · Close-up</span>
		<svg class="ring" viewBox="0 0 200 200" aria-hidden="true"><circle cx="100" cy="100" r="84" class="ring-bg"/><circle cx="100" cy="100" r="84" class="ring-fg" id="ring-fg" transform="rotate(-90 100 100)"/></svg>
		<div class="ring-pct" id="upload-pct">0%</div>
		<h1 id="upload-title">Sending your video</h1>
		<p class="lead" id="upload-sub">Keep this page open.</p>
		<p class="error" id="upload-error" hidden></p>
		<button type="button" id="btn-upload-retry" class="btn primary" hidden>Try again</button>
		<a id="btn-upload-download" class="btn secondary" href="#" download hidden>Save video to this phone</a>
	</div>
</section>

<section class="screen light" id="scr-done" hidden>
	<div class="inner center">
		<span class="done-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg></span>
		<h1>All done – thank you!</h1>
		<p class="lead">AI Models Partners received your videos. We’ll let you know when your avatar is ready.</p>
		<ul class="results" id="done-list"></ul>
		<p class="muted">You can close this page now.</p>
	</div>
</section>

<section class="screen light" id="scr-error" hidden>
	<div class="inner center">
		<h1 id="error-title">Something went wrong</h1>
		<p class="lead" id="error-text"></p>
		<button type="button" id="btn-error-retry" class="btn primary">Try again</button>
	</div>
</section>

<?php if ( $ampac_debug ) : ?>
<button type="button" id="dbg-toggle" class="dbg-toggle">DEBUG</button>
<aside id="ampac-dbg" class="dbg" hidden>
	<div class="dbg-head"><b>Diagnostyka v<?php echo esc_html( AMPAC_VERSION ); ?></b><button type="button" id="dbg-close" class="dbg-x" aria-label="Zamknij">×</button></div>
	<div class="dbg-grid">
		<label>Minimalna długość <select id="dbg-min"><option value="20">20 s (szybki test)</option><option value="60">60 s</option><option value="cfg">z ustawień</option></select></label>
		<label>Start nagrania <select id="dbg-start"><option value="auto">auto (rozpoznawanie mowy → zapas)</option><option value="speech">tylko rozpoznawanie mowy</option><option value="vad">dowolne krótkie słowo</option></select></label>
		<label>Głośność lektora <select id="dbg-vol"><option value="1">normalna</option><option value="2" selected>głośniej</option><option value="3.2">maksymalna</option></select></label>
		<label>Tryb audio iOS <select id="dbg-session"><option value="auto">auto</option><option value="play-and-record">play-and-record</option></select></label>
	</div>
	<pre id="dbg-live" class="dbg-live"></pre>
	<div class="dbg-actions"><button type="button" id="dbg-copy">Kopiuj raport</button><button type="button" id="dbg-say">Test głosu</button></div>
	<ol id="dbg-log" class="dbg-log"></ol>
</aside>
<?php endif; ?>

<video id="keepawake" src="<?php echo esc_url( $ampac_assets . 'keepawake.mp4' ); ?>" playsinline muted loop aria-hidden="true"></video>
<script>window.AMPAC = <?php echo wp_json_encode( $ampac_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?>;</script>
<script src="<?php echo esc_url( $ampac_assets . 'app.js?v=' . $ampac_v ); ?>"></script>
</body>
</html>

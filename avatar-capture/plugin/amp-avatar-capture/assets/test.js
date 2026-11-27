/*
 * AI Models Partners – Avatar Capture: strona testowa.
 * Sprawdza: tylną kamerę, nagrywanie (MediaRecorder), pytania głosowe
 * i wyciszanie mikrofonu w nagraniu na czas pytania (WebAudio GainNode).
 */
(function () {
  'use strict';

  var CFG = window.AMPAC_TEST || { audioBase: 'audio/', version: 'dev' };

  var QUESTIONS = [
    { text: 'What do you do for work?', file: 'q1.mp3' },
    { text: 'What do you like to do on weekends?', file: 'q2.mp3' },
    { text: 'Where would you love to travel next, and why?', file: 'q3.mp3' },
    { text: 'Tell me about something you are proud of.', file: 'q4.mp3' }
  ];
  var FORCED_AT_MS = 10000;   // pytanie testowe w trakcie mówienia
  var SILENCE_MS = 3000;      // cisza, po której pada pytanie
  var ANSWER_GRACE_MS = 3000; // dodatkowy czas na odpowiedź po pytaniu
  var PRE_MUTE_MS = 80;       // wyciszenie chwilę przed dźwiękiem
  var UNMUTE_TAIL_MS = 350;   // wyciszenie chwilę po dźwięku (pogłos)
  var MIME_CANDIDATES = [
    'video/mp4;codecs=avc1.640028,mp4a.40.2',
    'video/mp4;codecs=avc1,mp4a.40.2',
    'video/mp4',
    'video/webm;codecs=vp9,opus',
    'video/webm;codecs=vp8,opus',
    'video/webm'
  ];

  function $(id) { return document.getElementById(id); }
  var el = {
    res: $('sel-res'), cam: $('sel-cam'), voice: $('sel-voice'),
    mute: $('chk-mute'), silence: $('chk-silence'), forced: $('chk-forced'),
    preview: $('preview'), empty: $('empty'), recBadge: $('rec-badge'), timer: $('timer'),
    muteBadge: $('mute-badge'), level: $('level'), thr: $('thr'), speak: $('speak-state'),
    start: $('btn-start'), rec: $('btn-rec'), stop: $('btn-stop'), ask: $('btn-ask'),
    status: $('status'), orient: $('orient'), result: $('result'), playback: $('playback'),
    facts: $('facts'), mutes: $('mutes'), download: $('btn-download'), copy: $('btn-copy'),
    report: $('report'), log: $('log')
  };

  var S = {
    stream: null, videoTrack: null, micTrack: null,
    ctx: null, micSource: null, analyser: null, sink: null, gain: null, dest: null,
    recorder: null, chunks: [], recStart: 0, recEnd: 0, recording: false,
    asking: false, qIndex: 0, buffers: {}, audioLoaded: 0,
    lastVoice: 0, threshold: 0.015, ambient: null, calibrating: false,
    mutes: [], logLines: [], errors: [], forcedTimer: null, tick: null, raf: null,
    wakeLock: null, mime: '', usedWebAudio: false, blobUrl: null, camId: '',
    diag: {}, result: null
  };

  /* ---------- pomocnicze ---------- */

  function now() { return performance.now(); }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function recT() { return S.recStart ? (now() - S.recStart) / 1000 : 0; }
  function fmt(sec) {
    if (sec == null || !isFinite(sec)) return '?';
    var m = Math.floor(sec / 60);
    var s = sec - m * 60;
    return m + ':' + (s < 10 ? '0' : '') + s.toFixed(1);
  }
  function fmtClock(sec) {
    var m = Math.floor(sec / 60);
    var s = Math.floor(sec - m * 60);
    return m + ':' + (s < 10 ? '0' : '') + s;
  }
  function stamp() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes());
  }
  function log(msg, isErr) {
    var line = '[' + (S.recording ? fmt(recT()) : '--') + '] ' + msg;
    S.logLines.push(line);
    if (isErr) S.errors.push(msg);
    var li = document.createElement('li');
    li.textContent = line;
    if (isErr) li.className = 'err';
    el.log.appendChild(li);
  }
  function setStatus(msg) { el.status.textContent = msg; }
  function errText(e) {
    if (!e) return 'nieznany błąd';
    return (e.name ? e.name + ': ' : '') + (e.message || String(e));
  }
  function fail(what, e) {
    var msg = what + ' – ' + errText(e);
    log(msg, true);
    setStatus(msg);
  }

  window.addEventListener('error', function (ev) { log('Błąd skryptu: ' + ev.message, true); });
  window.addEventListener('unhandledrejection', function (ev) { log('Błąd (promise): ' + errText(ev.reason), true); });

  function pickMime() {
    if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
    for (var i = 0; i < MIME_CANDIDATES.length; i++) {
      try { if (MediaRecorder.isTypeSupported(MIME_CANDIDATES[i])) return MIME_CANDIDATES[i]; } catch (e) { /* pomiń */ }
    }
    return '';
  }
  function supportedMimes() {
    if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return [];
    return MIME_CANDIDATES.filter(function (m) { try { return MediaRecorder.isTypeSupported(m); } catch (e) { return false; } });
  }

  /* ---------- kamera i mikrofon ---------- */

  function constraints() {
    var dims = el.res.value === '4k' ? { w: 3840, h: 2160 } : { w: 1920, h: 1080 };
    var video = { width: { ideal: dims.w }, height: { ideal: dims.h }, frameRate: { ideal: 30 } };
    if (S.camId) video.deviceId = { exact: S.camId };
    else video.facingMode = { ideal: 'environment' };
    return {
      video: video,
      audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false, channelCount: { ideal: 1 } }
    };
  }

  function stopStream() {
    if (S.stream) S.stream.getTracks().forEach(function (t) { t.stop(); });
    S.stream = null;
  }

  function unlockSpeech() {
    try {
      if ('speechSynthesis' in window) {
        var u = new SpeechSynthesisUtterance(' ');
        u.volume = 0;
        window.speechSynthesis.speak(u);
      }
    } catch (e) { /* pomiń */ }
  }

  async function startCamera() {
    if (S.recording) return;
    el.start.disabled = true;
    el.rec.disabled = true;
    setStatus('Włączam kamerę…');
    try {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        throw new Error('Ta przeglądarka nie daje dostępu do kamery. Otwórz stronę w Safari lub Chrome (nie w podglądzie z innej aplikacji).');
      }
      if (!window.MediaRecorder) throw new Error('Ta przeglądarka nie umie nagrywać wideo (brak MediaRecorder).');
      if (!S.ctx) {
        var AC = window.AudioContext || window.webkitAudioContext;
        S.ctx = new AC();
      }
      if (S.ctx.state !== 'running') await S.ctx.resume();
      unlockSpeech();
      stopStream();
      S.stream = await navigator.mediaDevices.getUserMedia(constraints());
      S.videoTrack = S.stream.getVideoTracks()[0];
      S.micTrack = S.stream.getAudioTracks()[0];
      el.preview.srcObject = S.stream;
      try { await el.preview.play(); } catch (e) { log('Podgląd: ' + errText(e)); }
      el.empty.hidden = true;
      setupAudioGraph();
      describeTracks();
      await listCameras();
      if (!S.audioLoaded) loadQuestionAudio();
      requestWakeLock();
      await calibrate();
      el.rec.disabled = false;
      setStatus('Kamera działa. Kliknij „Nagrywaj” i zacznij odliczać.');
    } catch (e) {
      fail('Nie udało się włączyć kamery', e);
    }
    el.start.textContent = 'Włącz ponownie';
    el.start.disabled = false;
  }

  function setupAudioGraph() {
    var ctx = S.ctx;
    try { if (S.micSource) S.micSource.disconnect(); } catch (e) { /* pomiń */ }
    S.micSource = ctx.createMediaStreamSource(new MediaStream([S.micTrack]));
    if (!S.analyser) {
      S.analyser = ctx.createAnalyser();
      S.analyser.fftSize = 2048;
      // Analizator musi być podłączony do wyjścia, żeby przeglądarka go liczyła – przez zerową głośność.
      S.sink = ctx.createGain();
      S.sink.gain.value = 0;
      S.analyser.connect(S.sink);
      S.sink.connect(ctx.destination);
      S.gain = ctx.createGain();
      S.gain.gain.value = 1;
      S.dest = ctx.createMediaStreamDestination();
      S.gain.connect(S.dest);
    }
    S.micSource.connect(S.analyser);
    S.micSource.connect(S.gain);
    if (!S.raf) loop();
  }

  function describeTracks() {
    var v = {}, a = {}, vc = null;
    try { v = S.videoTrack.getSettings(); } catch (e) { /* pomiń */ }
    try { a = S.micTrack.getSettings(); } catch (e) { /* pomiń */ }
    try { if (S.videoTrack.getCapabilities) vc = S.videoTrack.getCapabilities(); } catch (e) { /* pomiń */ }
    S.diag.video = v;
    S.diag.videoCaps = vc;
    S.diag.videoLabel = S.videoTrack.label;
    S.diag.audio = a;
    S.diag.audioLabel = S.micTrack.label;
    log('Kamera: ' + (S.videoTrack.label || '?') + ', ' + v.width + '×' + v.height + ' @' + (v.frameRate ? Math.round(v.frameRate) : '?') + ' kl/s');
    log('Mikrofon: ' + (S.micTrack.label || '?') + ', echo=' + a.echoCancellation + ', szumy=' + a.noiseSuppression + ', AGC=' + a.autoGainControl);
  }

  async function listCameras() {
    try {
      var devices = await navigator.mediaDevices.enumerateDevices();
      var cams = devices.filter(function (d) { return d.kind === 'videoinput'; });
      S.diag.cameras = cams.map(function (c) { return c.label || '(bez nazwy)'; });
      var current = (S.diag.video && S.diag.video.deviceId) || S.camId;
      while (el.cam.firstChild) el.cam.removeChild(el.cam.firstChild);
      cams.forEach(function (c, i) {
        var o = document.createElement('option');
        o.value = c.deviceId;
        o.textContent = c.label || ('Kamera ' + (i + 1));
        if (c.deviceId === current) o.selected = true;
        el.cam.appendChild(o);
      });
      el.cam.disabled = cams.length < 2;
    } catch (e) {
      log('Lista kamer: ' + errText(e), true);
    }
  }

  function requestWakeLock() {
    if (!('wakeLock' in navigator) || S.wakeLock) return;
    navigator.wakeLock.request('screen').then(function (lock) {
      S.wakeLock = lock;
      S.diag.wakeLock = 'tak';
      lock.addEventListener('release', function () { S.wakeLock = null; });
    }).catch(function (e) {
      S.diag.wakeLock = 'nie (' + errText(e) + ')';
    });
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && S.stream) requestWakeLock();
  });

  async function calibrate() {
    setStatus('Kalibracja ciszy – nie mów przez 2 sekundy…');
    S.ambient = [];
    S.calibrating = true;
    await sleep(1800);
    S.calibrating = false;
    var vals = S.ambient.slice().sort(function (x, y) { return x - y; });
    var median = vals.length ? vals[Math.floor(vals.length / 2)] : 0.003;
    S.threshold = Math.min(0.06, Math.max(0.008, median * 3));
    S.diag.ambient = median;
    log('Kalibracja: tło ' + median.toFixed(4) + ', próg mowy ' + S.threshold.toFixed(4));
  }

  /* ---------- poziom dźwięku i wykrywanie ciszy ---------- */

  var buf = null;
  function levelToPct(rms) {
    var db = 20 * Math.log10(Math.max(rms, 1e-5));
    return Math.max(0, Math.min(100, (db + 60) / 60 * 100));
  }
  function loop() {
    S.raf = requestAnimationFrame(loop);
    if (!S.analyser) return;
    if (!buf || buf.length !== S.analyser.fftSize) buf = new Float32Array(S.analyser.fftSize);
    S.analyser.getFloatTimeDomainData(buf);
    var sum = 0;
    for (var i = 0; i < buf.length; i++) sum += buf[i] * buf[i];
    var rms = Math.sqrt(sum / buf.length);
    if (S.calibrating) S.ambient.push(rms);
    var t = now();
    var talking = rms > S.threshold;
    if (talking && !S.asking) S.lastVoice = Math.max(S.lastVoice, t);

    el.level.style.width = levelToPct(rms) + '%';
    el.thr.style.left = levelToPct(S.threshold) + '%';
    var label = S.asking ? 'pytanie' : (talking ? 'mówisz' : 'cisza');
    if (el.speak.textContent !== label) {
      el.speak.textContent = label;
      el.speak.className = 'badge' + (talking && !S.asking ? ' talk' : '');
    }

    if (S.recording && !S.asking && el.silence.checked &&
        t - S.recStart > 4000 && t - S.lastVoice > SILENCE_MS) {
      askQuestion('cisza 3 s');
    }
  }

  /* ---------- pytania ---------- */

  function decode(ab) {
    return new Promise(function (resolve, reject) {
      try {
        var p = S.ctx.decodeAudioData(ab, resolve, reject);
        if (p && typeof p.then === 'function') p.then(resolve, reject);
      } catch (e) { reject(e); }
    });
  }

  function loadQuestionAudio() {
    QUESTIONS.forEach(function (q) {
      fetch(CFG.audioBase + q.file, { cache: 'force-cache' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
        .then(decode)
        .then(function (b) { S.buffers[q.file] = b; S.audioLoaded++; })
        .catch(function (e) { log('Nie wczytano ' + q.file + ': ' + errText(e), true); });
    });
  }

  function setGain(v) {
    var g = S.gain.gain;
    var t = S.ctx.currentTime;
    g.cancelScheduledValues(t);
    g.setValueAtTime(g.value, t);
    g.linearRampToValueAtTime(v, t + 0.02);
  }

  function playBuffer(buffer) {
    return new Promise(function (resolve) {
      var src = S.ctx.createBufferSource();
      src.buffer = buffer;
      src.connect(S.ctx.destination);
      var done = false;
      function fin() { if (!done) { done = true; resolve('plik audio'); } }
      src.onended = fin;
      setTimeout(fin, buffer.duration * 1000 + 1500);
      src.start();
    });
  }

  function speak(text) {
    return new Promise(function (resolve, reject) {
      if (!('speechSynthesis' in window)) { reject(new Error('brak syntezy mowy')); return; }
      var u = new SpeechSynthesisUtterance(text);
      u.lang = 'en-US';
      u.rate = 1;
      u.volume = 1;
      var done = false;
      function fin(label) { if (!done) { done = true; resolve(label); } }
      u.onend = function () { fin('głos przeglądarki'); };
      u.onerror = function (e) { fin('głos przeglądarki (błąd: ' + (e.error || '?') + ')'); };
      setTimeout(function () { fin('głos przeglądarki (koniec po czasie)'); }, text.length * 90 + 3000);
      window.speechSynthesis.cancel();
      window.speechSynthesis.speak(u);
    });
  }

  async function askQuestion(reason) {
    if (!S.recording || S.asking) return;
    S.asking = true;
    var q = QUESTIONS[S.qIndex % QUESTIONS.length];
    S.qIndex++;
    var muted = el.mute.checked && S.usedWebAudio;
    var from = recT();
    if (muted) setGain(0);
    el.muteBadge.textContent = muted ? 'Mikrofon wyciszony – pytanie' : 'Pytanie (bez wyciszania)';
    el.muteBadge.hidden = false;
    log('Pytanie ' + S.qIndex + ' (' + reason + '): „' + q.text + '”');
    await sleep(PRE_MUTE_MS);
    var method = 'brak';
    try {
      if (el.voice.value === 'file' && S.buffers[q.file]) method = await playBuffer(S.buffers[q.file]);
      else method = await speak(q.text);
    } catch (e) {
      log('Nie udało się odtworzyć pytania: ' + errText(e), true);
    }
    await sleep(UNMUTE_TAIL_MS);
    if (muted) setGain(1);
    el.muteBadge.hidden = true;
    S.mutes.push({ from: from, to: recT(), text: q.text, reason: reason, method: method, muted: muted });
    S.lastVoice = now() + ANSWER_GRACE_MS;
    S.asking = false;
  }

  /* ---------- nagrywanie ---------- */

  function setRecordingUi(on) {
    el.rec.disabled = on;
    el.stop.disabled = !on;
    el.ask.disabled = !on;
    el.start.disabled = on;
    el.res.disabled = on;
    el.cam.disabled = on || el.cam.options.length < 2;
    el.mute.disabled = on;
    el.recBadge.hidden = !on;
  }

  function startRecording() {
    if (!S.stream || S.recording) return;
    if (S.ctx.state !== 'running') S.ctx.resume();
    S.chunks = [];
    S.mutes = [];
    S.qIndex = 0;
    S.usedWebAudio = el.mute.checked;
    var audioTrack = S.usedWebAudio ? S.dest.stream.getAudioTracks()[0] : S.micTrack;
    var recStream = new MediaStream([S.videoTrack, audioTrack]);
    S.mime = pickMime();
    var opts = { audioBitsPerSecond: 128000, videoBitsPerSecond: el.res.value === '4k' ? 30000000 : 10000000 };
    if (S.mime) opts.mimeType = S.mime;
    try {
      S.recorder = new MediaRecorder(recStream, opts);
    } catch (e) {
      log('MediaRecorder z ustawieniami nie ruszył (' + errText(e) + ') – próbuję domyślnie', true);
      S.recorder = new MediaRecorder(recStream);
    }
    S.recorder.ondataavailable = function (ev) { if (ev.data && ev.data.size) S.chunks.push(ev.data); };
    S.recorder.onerror = function (ev) { fail('Błąd nagrywania', ev.error || ev); };
    S.recorder.onstop = finishRecording;
    try {
      S.recorder.start(1000);
    } catch (e) {
      fail('Nagrywanie nie wystartowało', e);
      return;
    }
    S.recStart = now();
    S.lastVoice = S.recStart;
    S.recording = true;
    setRecordingUi(true);
    el.result.hidden = true;
    el.timer.textContent = '0:00';
    S.tick = setInterval(function () { el.timer.textContent = fmtClock(recT()); }, 250);
    if (el.forced.checked) {
      S.forcedTimer = setTimeout(function () { askQuestion('pytanie testowe po 10 s'); }, FORCED_AT_MS);
    }
    log('Start: ' + (S.recorder.mimeType || S.mime || 'format domyślny') +
      (S.usedWebAudio ? ', dźwięk przez WebAudio (z wyciszaniem)' : ', dźwięk prosto z mikrofonu (bez wyciszania)'));
    setStatus('Nagrywam… odliczaj na głos.');
  }

  function stopRecording() {
    if (!S.recording) return;
    S.recording = false;
    S.recEnd = now();
    clearTimeout(S.forcedTimer);
    clearInterval(S.tick);
    if (S.gain) setGain(1);
    try { window.speechSynthesis && window.speechSynthesis.cancel(); } catch (e) { /* pomiń */ }
    el.muteBadge.hidden = true;
    setRecordingUi(false);
    setStatus('Kończę nagranie…');
    try { S.recorder.stop(); } catch (e) { fail('Zatrzymanie nagrania', e); }
  }

  function finishRecording() {
    var type = (S.recorder && S.recorder.mimeType) || S.mime || 'video/mp4';
    var blob = new Blob(S.chunks, { type: type });
    if (S.blobUrl) URL.revokeObjectURL(S.blobUrl);
    S.blobUrl = URL.createObjectURL(blob);
    window.__ampacLastBlob = blob;
    var ext = type.indexOf('mp4') >= 0 ? 'mp4' : (type.indexOf('webm') >= 0 ? 'webm' : 'video');
    el.download.href = S.blobUrl;
    el.download.setAttribute('download', 'test-nagrania-' + stamp() + '.' + ext);
    S.result = {
      type: type, sizeMB: blob.size / 1048576, chunks: S.chunks.length,
      wallSec: (S.recEnd - S.recStart) / 1000, videoW: null, videoH: null, duration: null
    };
    el.playback.onloadedmetadata = function () {
      S.result.videoW = el.playback.videoWidth;
      S.result.videoH = el.playback.videoHeight;
      S.result.duration = isFinite(el.playback.duration) ? el.playback.duration : null;
      renderResult();
    };
    el.playback.src = S.blobUrl;
    el.result.hidden = false;
    renderResult();
    log('Koniec: ' + S.result.sizeMB.toFixed(1) + ' MB, ' + S.chunks.length + ' kawałków');
    setStatus(blob.size ? 'Gotowe. Obejrzyj nagranie poniżej.' : 'Nagranie jest puste – zobacz raport.');
    el.result.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* ---------- wynik i raport ---------- */

  function addFact(dt, dd) {
    var a = document.createElement('dt');
    a.textContent = dt;
    var b = document.createElement('dd');
    b.textContent = dd;
    el.facts.appendChild(a);
    el.facts.appendChild(b);
  }

  function renderResult() {
    var r = S.result;
    var v = S.diag.video || {};
    while (el.facts.firstChild) el.facts.removeChild(el.facts.firstChild);
    addFact('Plik', r.type + ', ' + r.sizeMB.toFixed(1) + ' MB');
    addFact('Obraz w pliku', r.videoW ? r.videoW + '×' + r.videoH + (r.videoH > r.videoW ? ' (pion)' : ' (poziom)') : 'czytam…');
    addFact('Długość', (r.duration ? fmt(r.duration) : '?') + ' (zegar: ' + fmt(r.wallSec) + ')');
    addFact('Kamera', (S.diag.videoLabel || '?') + ', ' + v.width + '×' + v.height + ' @' + (v.frameRate ? Math.round(v.frameRate) : '?') + ' kl/s');
    addFact('Dźwięk', S.usedWebAudio ? 'przez WebAudio (z wyciszaniem)' : 'prosto z mikrofonu');
    addFact('Błędy', S.errors.length ? String(S.errors.length) : 'brak');

    while (el.mutes.firstChild) el.mutes.removeChild(el.mutes.firstChild);
    if (!S.mutes.length) {
      var none = document.createElement('li');
      none.className = 'none';
      none.textContent = 'Nie padło żadne pytanie.';
      el.mutes.appendChild(none);
    }
    S.mutes.forEach(function (m, i) {
      var li = document.createElement('li');
      var b = document.createElement('button');
      b.type = 'button';
      b.textContent = (i + 1) + '. ' + fmt(m.from) + '–' + fmt(m.to) + ' · ' + m.reason + ' · ' + (m.muted ? 'wyciszone' : 'NIE wyciszone');
      b.addEventListener('click', function () {
        el.playback.currentTime = Math.max(0, m.from - 1.5);
        el.playback.play().catch(function () { /* pomiń */ });
      });
      li.appendChild(b);
      el.mutes.appendChild(li);
    });
    el.report.value = buildReport();
  }

  function buildReport() {
    var r = S.result || {};
    var v = S.diag.video || {};
    var a = S.diag.audio || {};
    var c = S.diag.videoCaps;
    var lines = [];
    lines.push('RAPORT TESTU KAMERY v' + CFG.version + ' – ' + new Date().toLocaleString('pl-PL'));
    lines.push('Przeglądarka: ' + navigator.userAgent);
    lines.push('Ekran: ' + window.screen.width + '×' + window.screen.height + ', okno ' + window.innerWidth + '×' + window.innerHeight);
    lines.push('Kamera: ' + (S.diag.videoLabel || '?') + ' | ' + v.width + '×' + v.height + ' @' + v.frameRate + ' | facingMode=' + v.facingMode + ' | wybrano: ' + (el.res.value === '4k' ? '4K' : '1080p'));
    lines.push('Kamery w telefonie: ' + (S.diag.cameras || []).join(' / '));
    if (c) {
      var caps = [];
      if (c.width) caps.push('szer. max ' + c.width.max);
      if (c.height) caps.push('wys. max ' + c.height.max);
      if (c.frameRate) caps.push('kl/s max ' + c.frameRate.max);
      ['focusMode', 'exposureMode', 'whiteBalanceMode'].forEach(function (k) { if (c[k]) caps.push(k + '=' + c[k].join(',')); });
      if (c.zoom) caps.push('zoom ' + c.zoom.min + '–' + c.zoom.max);
      if (c.torch !== undefined) caps.push('latarka');
      lines.push('Możliwości kamery: ' + (caps.join(', ') || 'brak danych'));
    } else {
      lines.push('Możliwości kamery: przeglądarka nie podaje');
    }
    lines.push('Mikrofon: ' + (S.diag.audioLabel || '?') + ' | ' + a.sampleRate + ' Hz, kanały ' + a.channelCount + ', echo=' + a.echoCancellation + ', szumy=' + a.noiseSuppression + ', AGC=' + a.autoGainControl);
    lines.push('AudioContext: ' + (S.ctx ? S.ctx.sampleRate + ' Hz, ' + S.ctx.state : 'brak'));
    lines.push('Formaty obsługiwane: ' + (supportedMimes().join(' ; ') || 'brak'));
    lines.push('Nagrano jako: ' + (r.type || '?') + ' | ' + (r.sizeMB ? r.sizeMB.toFixed(1) : '?') + ' MB | ' + r.chunks + ' kawałków');
    lines.push('Obraz w pliku: ' + (r.videoW ? r.videoW + '×' + r.videoH : '?') + ' | długość ' + fmt(r.duration) + ' (zegar ' + fmt(r.wallSec) + ')');
    lines.push('Dźwięk w nagraniu: ' + (S.usedWebAudio ? 'WebAudio z wyciszaniem' : 'prosto z mikrofonu'));
    lines.push('Głos pytań: ' + el.voice.value + ' | plików audio wczytanych: ' + S.audioLoaded + '/' + QUESTIONS.length);
    lines.push('Próg mowy: ' + S.threshold.toFixed(4) + ' (tło ' + (S.diag.ambient != null ? S.diag.ambient.toFixed(4) : '?') + ')');
    lines.push('Blokada wygaszania ekranu: ' + (S.diag.wakeLock || 'nieobsługiwana'));
    lines.push('Pytania:');
    if (!S.mutes.length) lines.push('  (brak)');
    S.mutes.forEach(function (m, i) {
      lines.push('  ' + (i + 1) + '. ' + fmt(m.from) + '–' + fmt(m.to) + ' | ' + m.reason + ' | ' + m.method + ' | ' + (m.muted ? 'wyciszone' : 'bez wyciszania'));
    });
    lines.push('Błędy: ' + (S.errors.length ? S.errors.join(' || ') : 'brak'));
    lines.push('');
    lines.push('MOJE OBSERWACJE (dopisz):');
    lines.push('1. Czy słyszałem pytania wyraźnie z 1 m? ');
    lines.push('2. Czy w momentach pytań w nagraniu jest cisza (bez głosu pytania i bez mojego liczenia)? ');
    lines.push('3. Czy ruch ust zgadza się z dźwiękiem? ');
    lines.push('4. Czy obraz jest pionowy i ostry? ');
    return lines.join('\n');
  }

  function copyReport() {
    var text = el.report.value || buildReport();
    function done() { setStatus('Raport skopiowany. Wklej go w czacie z Claude.'); }
    function fallback() {
      el.report.focus();
      el.report.select();
      try { document.execCommand('copy'); done(); } catch (e) { setStatus('Zaznacz tekst raportu i skopiuj go ręcznie.'); }
    }
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, fallback);
    else fallback();
  }

  /* ---------- zdarzenia ---------- */

  function checkOrientation() { el.orient.hidden = window.innerHeight >= window.innerWidth; }
  window.addEventListener('resize', checkOrientation);
  checkOrientation();

  el.start.addEventListener('click', startCamera);
  el.rec.addEventListener('click', startRecording);
  el.stop.addEventListener('click', stopRecording);
  el.ask.addEventListener('click', function () { askQuestion('ręcznie'); });
  el.copy.addEventListener('click', copyReport);
  el.res.addEventListener('change', function () { if (S.stream && !S.recording) startCamera(); });
  el.cam.addEventListener('change', function () { S.camId = el.cam.value; if (S.stream && !S.recording) startCamera(); });

  if (!window.isSecureContext) {
    setStatus('Strona musi być otwarta przez https (kłódka), inaczej kamera nie zadziała.');
  }
  log('Strona gotowa, wersja ' + CFG.version);
})();

/*
 * AI Models Partners – Avatar Capture (aplikacja klienta).
 * Kamera → kontrola kadru (MediaPipe Pose) i podpowiedzi głosowe → start na hasło „Start”
 * → nagrywanie z pytaniami (mikrofon w nagraniu wyciszany na czas pytania)
 * → sprawdzenie nagrania → wysyłka na Dysk Google przez Apps Script.
 */
(function () {
  'use strict';

  var CFG = window.AMPAC || {};
  var DEBUG = !!CFG.debug;

  /* ---------- treści ---------- */

  var TEXT = {
    welcome: ['Let’s get set up', 'Stand the phone upright, back camera facing you.'],
    not_visible: ['Step in front of the camera', 'I can’t see you yet.'],
    step_left: ['Step to your left', 'Just a small step.'],
    step_right: ['Step to your right', 'Just a small step.'],
    step_back: ['Take a step back', 'You’re a little too close.'],
    come_closer: ['Come a little closer', 'You’re a little too far.'],
    head_cut: ['Your head is cut off', 'Take a step back.'],
    feet_cut: ['I can’t see your feet', 'Take a step back.'],
    raise_phone: ['Raise the phone a little', 'Your eyes should be in the upper third.'],
    lower_phone: ['Lower the phone a little', 'You’re too low in the frame.'],
    straighten: ['Straighten the phone', 'It’s tilted.'],
    too_dark: ['It’s too dark', 'Turn toward a window or a lamp.'],
    backlight: ['The light is behind you', 'Face the light.'],
    hold_still: ['Great – hold still', ''],
    ready_say_start: ['Perfect! Say “Start”', 'when you’re ready – or tap the red button.'],
    say_start_again: ['Say “Start”', 'whenever you’re ready.'],
    countdown: ['Get ready', 'Recording in 3, 2, 1…'],
    out_of_frame: ['You’re out of the frame', 'Step back in.'],
    start_over: ['Let’s start this one over', ''],
    min_reached: ['Great job!', 'Keep talking, or raise your hand to finish.'],
    all_done: ['All done. Thank you!', ''],
    next_full_body: ['Now the full body shot', 'Step back until I can see you from head to toe.'],
    q1: 'Introduce yourself. Who are you, and where are you from?',
    q2: 'What do you do for work?',
    q3: 'Tell me about a typical day for you.',
    q4: 'What do you love most about your job?',
    q5: 'What do you like to do on weekends?',
    q6: 'Where would you love to travel next, and why?',
    q7: 'Tell me about something you’re proud of.',
    q8: 'What are you looking forward to this year?'
  };
  var VOICE_IDS = ['welcome', 'not_visible', 'step_left', 'step_right', 'step_back', 'come_closer', 'head_cut', 'feet_cut',
    'raise_phone', 'lower_phone', 'straighten', 'too_dark', 'backlight', 'hold_still', 'ready_say_start', 'say_start_again',
    'countdown', 'out_of_frame', 'start_over', 'min_reached', 'all_done', 'next_full_body',
    'q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8'];

  var SHOTS = [
    {
      id: 'closeup', label: 'Close-up', title: 'Close-up · chest up', file: '1-close-up',
      sub: '2–3 minutes answering easy questions. No script needed.',
      distance: '3–4 ft away', height: 'Phone upright, lens at your eye level.',
      tips: ['We use the back camera – much better quality.',
        'We’ll ask you questions out loud – just answer, looking into the lens.',
        'Talk with energy and natural gestures – your avatar copies them.',
        'Don’t stop halfway. If something goes wrong, we start over.'],
      questions: ['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8']
    },
    {
      id: 'fullbody', label: 'Full body', title: 'Full body · head to toe', file: '2-full-body',
      sub: '2–3 minutes standing and answering easy questions.',
      distance: '8–10 ft away', height: 'Phone upright, about chest height.',
      tips: ['Stand where your whole body fits, with a little space above your head and below your feet.',
        'Keep your feet in the frame the whole time.',
        'Answer the questions looking into the lens.',
        'Natural gestures are great – just stay in the frame.'],
      questions: ['q5', 'q6', 'q7', 'q8', 'q2', 'q3', 'q4', 'q1']
    }
  ];

  var T = {
    detectMs: 110, evalMs: 250, issueDebounce: 900, repeatSame: 6000, gapAfterSpeak: 1100,
    okHold: 1200, backToAdjust: 1600, remindStart: 18000, silenceMs: 3000, answerGrace: 3000,
    preMute: 80, tailMute: 380, outWarn: 1000, outAbort: 3000, handHold: 800, firstQuestion: 700
  };
  var START_RE = /\b(start|started|starts|ready|record|nagrywaj|startuj)\b/i;
  var MIME_CANDIDATES = ['video/mp4;codecs=avc1.640028,mp4a.40.2', 'video/mp4;codecs=avc1,mp4a.40.2', 'video/mp4',
    'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
  var CHUNK = 8 * 1024 * 1024;

  /* ---------- stan ---------- */

  var S = {
    name: '', folder: '', shotIndex: 0, phase: 'idle', screen: 'welcome',
    stream: null, micTrack: null, ctx: null, analyser: null, recAnalyser: null, sink: null, recGain: null,
    dest: null, voiceGain: null, micSource: null, buffers: {}, voiceOn: true,
    speaking: false, speakEnd: 0, lastSaid: null, lastSaidAt: 0, currentSrc: null,
    pose: null, poseLoading: false, poseError: null, lm: null, lmAt: 0, lastDetectTs: 0, detectCount: 0, detectErrors: 0,
    fr: null, issue: null, issueSince: 0, awaitPromptAt: 0,
    rms: 0, thr: 0.004, noise: [], noiseFloor: 0, lastVoice: 0, seg: null,
    light: { face: null, bg: null, all: null, issue: null, dark: false },
    tilt: { known: false, roll: 0, pitch: 0, issue: false }, motion: { known: false, mags: [], unsteady: false },
    recog: null, listenMode: 'vad', srFailed: false, srResults: 0, srMiss: 0, lastHeard: '',
    rec: null, current: null, results: [], timers: [], loopsOn: false,
    diag: {}, logLines: [], errors: [], wakeLock: null, mime: ''
  };

  /* ---------- pomocnicze ---------- */

  function $(id) { return document.getElementById(id); }
  function now() { return performance.now(); }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function shot() { return SHOTS[S.shotIndex]; }
  function minSec() {
    if (DEBUG && $('dbg-min') && $('dbg-min').value !== 'cfg') return Number($('dbg-min').value);
    return CFG.minSec || 120;
  }
  function maxSec() { return Math.max(minSec() + 10, CFG.maxSec || 300); }
  function clock(sec) { sec = Math.max(0, Math.floor(sec)); return Math.floor(sec / 60) + ':' + (sec % 60 < 10 ? '0' : '') + (sec % 60); }
  function fmt(sec) { if (sec == null || !isFinite(sec)) return '?'; var m = Math.floor(sec / 60), s = sec - m * 60; return m + ':' + (s < 10 ? '0' : '') + s.toFixed(1); }
  function db(x) { return 20 * Math.log10(Math.max(x, 1e-6)); }
  function errText(e) { if (!e) return 'nieznany błąd'; return (e.name ? e.name + ': ' : '') + (e.message || String(e)); }
  function recT() { return S.rec ? (now() - S.rec.start) / 1000 : 0; }
  function log(msg, isErr) {
    var line = '[' + new Date().toTimeString().slice(0, 8) + (S.rec && S.phase === 'recording' ? ' rec ' + fmt(recT()) : '') + '] ' + msg;
    S.logLines.push(line);
    if (S.logLines.length > 600) S.logLines.shift();
    if (isErr) S.errors.push(msg);
    if (DEBUG && $('dbg-log')) {
      var li = document.createElement('li');
      li.textContent = line;
      if (isErr) li.className = 'err';
      $('dbg-log').appendChild(li);
    }
  }
  window.addEventListener('error', function (ev) { log('Błąd skryptu: ' + ev.message, true); });
  window.addEventListener('unhandledrejection', function (ev) { log('Błąd: ' + errText(ev.reason), true); });

  function show(name) {
    ['welcome', 'setup', 'camera', 'review', 'upload', 'done', 'error'].forEach(function (n) {
      $('scr-' + n).hidden = n !== name;
    });
    S.screen = name;
    document.querySelector('meta[name=theme-color]').setAttribute('content', name === 'camera' ? '#0D0D0F' : '#F5F5F2');
    window.scrollTo(0, 0);
  }
  function setBanner(kind, title, sub) {
    var b = $('banner');
    b.className = 'banner ' + (kind || '');
    if ($('banner-title').textContent !== title) $('banner-title').textContent = title;
    if ($('banner-sub').textContent !== (sub || '')) $('banner-sub').textContent = sub || '';
  }
  function bannerFor(id, kind) { var t = TEXT[id] || [id, '']; setBanner(kind || 'warn', t[0], t[1]); }
  function caption(text) { if ($('cam-caption').textContent !== text) $('cam-caption').textContent = text; }
  function fail(title, text) {
    $('error-title').textContent = title;
    $('error-text').textContent = text;
    show('error');
  }
  var ICON_OK = '<svg viewBox="0 0 24 24" class="r-ico" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7"/></svg>';
  var ICON_BAD = '<svg viewBox="0 0 24 24" class="r-ico" aria-hidden="true"><path d="M12 6v8"/><path d="M12 18.5h.01"/></svg>';

  /* ---------- dźwięk ---------- */

  function ensureAudio() {
    if (!S.ctx) {
      var AC = window.AudioContext || window.webkitAudioContext;
      S.ctx = new AC();
      var ctx = S.ctx;
      S.sink = ctx.createGain(); S.sink.gain.value = 0; S.sink.connect(ctx.destination);
      S.analyser = ctx.createAnalyser(); S.analyser.fftSize = 2048; S.analyser.connect(S.sink);
      S.recGain = ctx.createGain(); S.recGain.gain.value = 1;
      S.dest = ctx.createMediaStreamDestination();
      S.recGain.connect(S.dest);
      S.recAnalyser = ctx.createAnalyser(); S.recAnalyser.fftSize = 2048;
      S.recGain.connect(S.recAnalyser); S.recAnalyser.connect(S.sink);
      S.voiceGain = ctx.createGain();
      var comp = ctx.createDynamicsCompressor();
      comp.threshold.value = -10; comp.knee.value = 6; comp.ratio.value = 8; comp.attack.value = 0.003; comp.release.value = 0.15;
      S.voiceGain.connect(comp); comp.connect(ctx.destination);
      loadVoices();
    }
    S.voiceGain.gain.value = voiceVolume();
    if (S.ctx.state !== 'running') S.ctx.resume();
    if (DEBUG && $('dbg-session').value !== 'auto' && navigator.audioSession) {
      try { navigator.audioSession.type = $('dbg-session').value; } catch (e) { log('audioSession: ' + errText(e), true); }
    }
    S.diag.audioSession = navigator.audioSession ? navigator.audioSession.type : 'brak API';
  }
  function voiceVolume() { return DEBUG && $('dbg-vol') ? Number($('dbg-vol').value) : 2; }

  function decode(ab) {
    return new Promise(function (resolve, reject) {
      try {
        var p = S.ctx.decodeAudioData(ab, resolve, reject);
        if (p && typeof p.then === 'function') p.then(resolve, reject);
      } catch (e) { reject(e); }
    });
  }
  function loadVoices() {
    VOICE_IDS.forEach(function (id) {
      fetch(CFG.audioBase + id + '.mp3')
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
        .then(decode)
        .then(function (b) { S.buffers[id] = b; })
        .catch(function (e) { log('Głos ' + id + ': ' + errText(e), true); });
    });
  }

  function textOf(id) { var t = TEXT[id]; return Array.isArray(t) ? (t[0] + '. ' + t[1]) : (t || ''); }
  function play(id, force) {
    var buf = S.buffers[id];
    S.speaking = true;
    var p;
    if (!S.voiceOn && !force) {
      p = sleep(10);
    } else if (buf) {
      p = new Promise(function (resolve) {
        var src = S.ctx.createBufferSource();
        src.buffer = buf;
        src.connect(S.voiceGain);
        var done = false;
        function fin() { if (!done) { done = true; resolve(); } }
        src.onended = fin;
        setTimeout(fin, buf.duration * 1000 + 1200);
        src.start();
        S.currentSrc = src;
      });
    } else {
      p = speakFallback(textOf(id));
    }
    return p.then(function () { S.speaking = false; S.speakEnd = now(); S.currentSrc = null; });
  }
  function stopVoice() {
    try { if (S.currentSrc) S.currentSrc.stop(); } catch (e) { /* pomiń */ }
    try { if (window.speechSynthesis) window.speechSynthesis.cancel(); } catch (e) { /* pomiń */ }
  }
  function speakFallback(text) {
    return new Promise(function (resolve) {
      if (!text || !('speechSynthesis' in window)) { resolve(); return; }
      var u = new SpeechSynthesisUtterance(text);
      u.lang = 'en-US';
      var done = false;
      function fin() { if (!done) { done = true; resolve(); } }
      u.onend = fin; u.onerror = fin;
      setTimeout(fin, text.length * 90 + 2500);
      window.speechSynthesis.speak(u);
    });
  }
  function say(id) {
    S.lastSaid = id; S.lastSaidAt = now();
    log('Głos: ' + id);
    return play(id);
  }

  function attachMic(track) {
    try { if (S.micSource) S.micSource.disconnect(); } catch (e) { /* pomiń */ }
    S.micTrack = track;
    S.micSource = S.ctx.createMediaStreamSource(new MediaStream([track]));
    S.micSource.connect(S.analyser);
    S.micSource.connect(S.recGain);
    track.addEventListener('ended', function () { log('Mikrofon: ścieżka się zakończyła', true); });
  }
  async function checkMic() {
    if (S.micTrack && S.micTrack.readyState === 'live') return;
    log('Mikrofon nieaktywny – włączam ponownie', true);
    try {
      var s = await navigator.mediaDevices.getUserMedia({ audio: audioConstraints() });
      attachMic(s.getAudioTracks()[0]);
      S.diag.micReacquired = (S.diag.micReacquired || 0) + 1;
    } catch (e) { log('Nie udało się włączyć mikrofonu: ' + errText(e), true); }
  }

  /* ---------- kamera ---------- */

  function audioConstraints() { return { echoCancellation: false, noiseSuppression: false, autoGainControl: false, channelCount: { ideal: 1 } }; }
  function videoConstraints(deviceId) {
    var v = { width: { ideal: 1920 }, height: { ideal: 1080 }, frameRate: { ideal: 30 } };
    if (deviceId) v.deviceId = { exact: deviceId }; else v.facingMode = { ideal: 'environment' };
    return v;
  }
  function chooseMainBack(cams) {
    var BACK = /back|rear|environment|tyln|tył|tyl/i;
    var BAD = /ultra|dual|triple|tele|szerokok|dwoma|trzema|potr|macro|depth/i;
    var plain = cams.filter(function (c) { return BACK.test(c.label) && !BAD.test(c.label); });
    return plain[0] || null;
  }
  async function startStream() {
    S.stream = await navigator.mediaDevices.getUserMedia({ video: videoConstraints(null), audio: audioConstraints() });
    try {
      var devices = await navigator.mediaDevices.enumerateDevices();
      var cams = devices.filter(function (d) { return d.kind === 'videoinput'; });
      S.diag.cameras = cams.map(function (c) { return c.label || '?'; });
      var cur = S.stream.getVideoTracks()[0];
      var best = chooseMainBack(cams);
      if (best && best.label && best.label !== cur.label) {
        log('Zmieniam obiektyw: ' + cur.label + ' → ' + best.label);
        cur.stop();
        S.stream.removeTrack(cur);
        var vs;
        try { vs = await navigator.mediaDevices.getUserMedia({ video: videoConstraints(best.deviceId), audio: false }); }
        catch (e) { log('Główny obiektyw niedostępny: ' + errText(e), true); vs = await navigator.mediaDevices.getUserMedia({ video: videoConstraints(null), audio: false }); }
        S.stream.addTrack(vs.getVideoTracks()[0]);
      }
    } catch (e) { log('Wybór obiektywu: ' + errText(e), true); }
    var vt = S.stream.getVideoTracks()[0];
    var at = S.stream.getAudioTracks()[0];
    S.diag.video = vt.getSettings ? vt.getSettings() : {};
    S.diag.videoLabel = vt.label;
    try { S.diag.videoCaps = vt.getCapabilities ? vt.getCapabilities() : null; } catch (e) { S.diag.videoCaps = null; }
    S.diag.audio = at && at.getSettings ? at.getSettings() : {};
    S.diag.audioLabel = at ? at.label : '';
    log('Kamera: ' + vt.label + ' ' + S.diag.video.width + '×' + S.diag.video.height + ' @' + Math.round(S.diag.video.frameRate || 0));
    attachMic(at);
  }

  function requestSensors() {
    // iOS wymaga zgody na czujniki w reakcji na kliknięcie.
    try {
      if (window.DeviceMotionEvent && typeof DeviceMotionEvent.requestPermission === 'function') {
        DeviceMotionEvent.requestPermission().then(function (st) { S.diag.motionPermission = st; }).catch(function (e) { S.diag.motionPermission = errText(e); });
      }
    } catch (e) { /* pomiń */ }
    if (!S.motionBound) {
      S.motionBound = true;
      window.addEventListener('devicemotion', onMotion);
    }
  }
  function onMotion(e) {
    var g = e.accelerationIncludingGravity;
    if (g && g.x != null && g.y != null) {
      var gx = g.x, gy = g.y, gz = g.z || 0;
      var mag = Math.sqrt(gx * gx + gy * gy + gz * gz) || 1;
      S.tilt.known = true;
      S.tilt.roll = Math.atan2(gx, Math.abs(gy)) * 180 / Math.PI;
      S.tilt.pitch = Math.asin(Math.max(-1, Math.min(1, gz / mag))) * 180 / Math.PI;
    }
    var a = e.acceleration;
    if (a && a.x != null) {
      S.motion.known = true;
      S.motion.mags.push(Math.sqrt(a.x * a.x + a.y * a.y + (a.z || 0) * (a.z || 0)));
      if (S.motion.mags.length > 60) S.motion.mags.shift();
    }
  }

  async function keepAwake() {
    try {
      if ('wakeLock' in navigator && !S.wakeLock) {
        S.wakeLock = await navigator.wakeLock.request('screen');
        S.diag.wakeLock = 'tak';
        S.wakeLock.addEventListener('release', function () { S.wakeLock = null; });
      }
    } catch (e) { S.diag.wakeLock = 'nie (' + errText(e) + ')'; }
    try { await $('keepawake').play(); S.diag.keepAwakeVideo = 'tak'; } catch (e) { S.diag.keepAwakeVideo = 'nie (' + errText(e) + ')'; }
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      if (S.stream) keepAwake();
    } else if (S.phase === 'recording') {
      log('Aplikacja zeszła w tło podczas nagrania', true);
      abortRecording('app in background', true);
    }
  });

  /* ---------- rozpoznawanie sylwetki ---------- */

  async function loadPose() {
    if (S.pose || S.poseLoading) return;
    S.poseLoading = true;
    var t0 = now();
    try {
      var vision = await import(CFG.mp.bundle);
      var fileset = await vision.FilesetResolver.forVisionTasks(CFG.mp.wasm);
      var opts = {
        baseOptions: { modelAssetPath: CFG.mp.model, delegate: 'GPU' },
        runningMode: 'VIDEO', numPoses: 1,
        minPoseDetectionConfidence: 0.5, minPosePresenceConfidence: 0.5, minTrackingConfidence: 0.5
      };
      try {
        S.pose = await vision.PoseLandmarker.createFromOptions(fileset, opts);
        S.diag.delegate = 'GPU';
      } catch (e) {
        log('GPU niedostępne (' + errText(e) + '), używam CPU');
        opts.baseOptions.delegate = 'CPU';
        S.pose = await vision.PoseLandmarker.createFromOptions(fileset, opts);
        S.diag.delegate = 'CPU';
      }
      S.diag.poseLoadMs = Math.round(now() - t0);
      log('Rozpoznawanie sylwetki gotowe (' + S.diag.delegate + ', ' + S.diag.poseLoadMs + ' ms)');
    } catch (e) {
      S.poseError = errText(e);
      log('Rozpoznawanie sylwetki niedostępne: ' + S.poseError, true);
    }
    S.poseLoading = false;
  }
  function detectTick() {
    var v = $('preview');
    if (!S.pose || v.readyState < 2 || document.hidden) return;
    var t = now();
    if (t <= S.lastDetectTs) return;
    S.lastDetectTs = t;
    try {
      var r = S.pose.detectForVideo(v, t);
      S.lm = (r && r.landmarks && r.landmarks[0]) || null;
      S.lmAt = t;
      S.detectCount++;
    } catch (e) {
      S.detectErrors++;
      if (S.detectErrors < 4) log('Błąd rozpoznawania: ' + errText(e), true);
    }
  }

  function vis(p, min) { return p && (p.visibility == null || p.visibility > (min || 0.5)); }
  function analyzeFraming(lm, shotId) {
    if (!lm) return { issue: 'not_visible', m: null };
    var nose = lm[0], le = lm[2], re = lm[5], ls = lm[11], rs = lm[12];
    if (!vis(nose, 0.4) || !(vis(ls, 0.3) || vis(rs, 0.3))) return { issue: 'not_visible', m: null };
    var eyeY = (le.y + re.y) / 2;
    var shY = (ls.y + rs.y) / 2, shX = (ls.x + rs.x) / 2;
    var headH = Math.max(0.02, shY - eyeY);
    var m = {
      eyeY: eyeY, shY: shY, shW: Math.abs(ls.x - rs.x),
      headTop: eyeY - 0.75 * headH, chin: eyeY + 0.6 * headH,
      cx: (nose.x + shX) / 2, noseX: nose.x, noseY: nose.y
    };
    if (shotId === 'closeup') {
      if (m.shW > 0.92 || (m.headTop < 0 && m.shW > 0.8)) return { issue: m.headTop < 0 ? 'head_cut' : 'step_back', m: m };
      if (m.shY > 0.97) return { issue: 'step_back', m: m };
      if (m.shW < 0.42) return { issue: 'come_closer', m: m };
      if (m.cx < 0.40) return { issue: 'step_left', m: m };
      if (m.cx > 0.60) return { issue: 'step_right', m: m };
      if (m.headTop < 0.01 || m.eyeY < 0.20) return { issue: 'raise_phone', m: m };
      if (m.eyeY > 0.46) return { issue: 'lower_phone', m: m };
      return { issue: null, m: m };
    }
    var feet = [27, 28, 29, 30, 31, 32].map(function (i) { return lm[i]; });
    var seen = feet.filter(function (p) { return vis(p, 0.5) && p.y < 1.0; });
    var feetY = Math.max.apply(null, feet.map(function (p) { return p ? p.y : 0; }));
    var hips = lm[23] && lm[24] ? (lm[23].x + lm[24].x) / 2 : shX;
    m.feetY = feetY;
    m.bodyH = feetY - m.headTop;
    m.cx = (shX + hips + nose.x) / 3;
    if (m.headTop < -0.01 && feetY > 0.99) return { issue: 'step_back', m: m };
    if (seen.length < 2 || feetY > 0.985) return { issue: m.headTop > 0.15 ? 'lower_phone' : 'feet_cut', m: m };
    if (m.headTop < 0.0) return { issue: (1 - feetY) > 0.12 ? 'raise_phone' : 'head_cut', m: m };
    if (m.bodyH > 0.96) return { issue: 'step_back', m: m };
    if (m.bodyH < 0.55) return { issue: 'come_closer', m: m };
    if (m.cx < 0.38) return { issue: 'step_left', m: m };
    if (m.cx > 0.62) return { issue: 'step_right', m: m };
    return { issue: null, m: m };
  }

  /* ---------- światło, poziom, dźwięk ---------- */

  var lc = document.createElement('canvas');
  var lcx = lc.getContext('2d', { willReadFrequently: true });
  function lightTick() {
    var v = $('preview');
    if (v.readyState < 2 || !v.videoWidth) return;
    var w = 36, h = Math.max(16, Math.round(36 * v.videoHeight / v.videoWidth));
    lc.width = w; lc.height = h;
    try { lcx.drawImage(v, 0, 0, w, h); } catch (e) { return; }
    var d = lcx.getImageData(0, 0, w, h).data;
    var m = S.fr && S.fr.m;
    var fx0 = 0.38, fx1 = 0.62, fy0 = 0.18, fy1 = 0.42;
    if (m) {
      var half = Math.max(0.06, (m.chin - m.headTop) * 0.35 * (v.videoHeight / v.videoWidth));
      fx0 = m.noseX - half; fx1 = m.noseX + half; fy0 = Math.max(0, m.headTop + 0.02); fy1 = Math.min(1, m.chin);
    }
    var all = 0, n = 0, face = 0, nf = 0, bg = 0, nb = 0;
    for (var y = 0; y < h; y++) {
      for (var x = 0; x < w; x++) {
        var i = (y * w + x) * 4;
        var lum = 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2];
        all += lum; n++;
        var nx = (x + 0.5) / w, ny = (y + 0.5) / h;
        if (nx >= fx0 && nx <= fx1 && ny >= fy0 && ny <= fy1) { face += lum; nf++; }
        else if (ny < 0.2) { bg += lum; nb++; }
      }
    }
    var L = S.light;
    L.all = all / n; L.face = nf ? face / nf : null; L.bg = nb ? bg / nb : null;
    L.dark = (L.face != null ? L.face < 55 : L.all < 45);
    L.issue = null;
    if (L.dark) L.issue = 'too_dark';
    else if (m && L.face != null && L.bg != null && L.bg - L.face > 60 && L.face < 120) L.issue = 'backlight';
  }
  function motionUpdate() {
    var mags = S.motion.mags;
    if (mags.length > 10) {
      var mean = mags.reduce(function (a, b) { return a + b; }, 0) / mags.length;
      S.motion.unsteady = mean > 0.35;
    }
    S.tilt.issue = S.tilt.known && (Math.abs(S.tilt.roll) > 4 || Math.abs(S.tilt.pitch) > 25);
  }
  function noiseUpdate() {
    // próg mowy: 4× poziom tła (10. percentyl ostatnich pomiarów, gdy aplikacja milczy)
    if (S.noise.length < 30) return;
    var sorted = S.noise.slice().sort(function (a, b) { return a - b; });
    S.noiseFloor = sorted[Math.floor(sorted.length * 0.1)];
    S.thr = Math.min(0.03, Math.max(0.0015, S.noiseFloor * 4));
  }

  var abuf = null, rbuf = null;
  function rmsOf(an, buf) { an.getFloatTimeDomainData(buf); var s = 0; for (var i = 0; i < buf.length; i++) s += buf[i] * buf[i]; return Math.sqrt(s / buf.length); }
  function audioTick() {
    if (!S.analyser) return;
    if (!abuf) { abuf = new Float32Array(S.analyser.fftSize); rbuf = new Float32Array(S.recAnalyser.fftSize); }
    var t = now();
    var rms = rmsOf(S.analyser, abuf);
    S.rms = rms;
    var appTalking = S.speaking || t - S.speakEnd < 450;
    if (!appTalking) { S.noise.push(rms); if (S.noise.length > 400) S.noise.shift(); }
    var voice = !appTalking && rms > S.thr;
    if (voice) S.lastVoice = Math.max(S.lastVoice, t);
    if (S.phase === 'await') utterance(voice, t);
    if (S.rec && S.phase === 'recording') {
      var rr = rmsOf(S.recAnalyser, rbuf);
      if (t - S.rec.lastLevelAt >= 250) {
        S.rec.lastLevelAt = t;
        S.rec.levels.push({ t: recT(), db: Math.round(db(rr) * 10) / 10, muted: S.rec.muted, voice: voice });
      }
    }
  }

  /* ---------- pętle ---------- */

  function startLoops() {
    if (S.loopsOn) return;
    S.loopsOn = true;
    S.timers.push(setInterval(detectTick, T.detectMs));
    S.timers.push(setInterval(evalTick, T.evalMs));
    S.timers.push(setInterval(lightTick, 600));
    S.timers.push(setInterval(function () { motionUpdate(); noiseUpdate(); }, 500));
    if (DEBUG) S.timers.push(setInterval(debugLive, 400));
    (function raf() { if (!S.loopsOn) return; audioTick(); requestAnimationFrame(raf); })();
  }
  function stopLoops() {
    S.loopsOn = false;
    S.timers.forEach(clearInterval);
    S.timers = [];
  }

  function evalTick() {
    var t = now();
    var lm = S.lm && t - S.lmAt < 1000 ? S.lm : null;
    var fr = S.pose ? analyzeFraming(lm, shot().id) : { issue: S.poseError ? null : 'loading', m: null };
    S.fr = fr;
    var frameIssue = fr.issue;
    var issue = frameIssue || (S.tilt.issue ? 'straighten' : null) || S.light.issue || null;
    setChip('frame', S.pose ? (frameIssue ? 'bad' : 'ok') : 'na');
    setChip('light', S.light.all == null ? 'na' : (S.light.issue ? 'bad' : 'ok'));
    setChip('level', S.tilt.known ? (S.tilt.issue ? 'bad' : 'ok') : 'na');
    setChip('sound', S.noiseFloor ? (db(S.noiseFloor) > -38 ? 'bad' : 'ok') : 'na');
    setChip('steady', S.motion.known ? (S.motion.unsteady ? 'bad' : 'ok') : 'na');
    drawGuide(!issue && S.phase !== 'recording' ? 'ok' : '');
    if (S.phase === 'adjust' || S.phase === 'await') guidance(issue, t);
    else if (S.phase === 'recording') recordingWatch(fr, t);
  }
  function setChip(name, state) {
    var el = document.querySelector('.chk[data-check="' + name + '"]');
    var cls = 'chk ' + (state === 'na' ? '' : state);
    if (el && el.className !== cls) el.className = cls;
  }

  /* ---------- ramka na ekranie ---------- */

  var guideKey = '';
  function drawGuide(state) {
    var v = $('preview'), svg = $('guide');
    if (!v.videoWidth) return;
    var box = $('stage').getBoundingClientRect();
    var ar = v.videoWidth / v.videoHeight;
    var w = box.width, h = box.width / ar;
    if (h > box.height) { h = box.height; w = h * ar; }
    var left = (box.width - w) / 2, top = (box.height - h) / 2;
    var W = 1000, H = Math.round(1000 / ar);
    var key = [shot().id, w | 0, h | 0, state, DEBUG && S.lm ? S.lmAt : ''].join('|');
    if (key === guideKey) return;
    guideKey = key;
    svg.style.left = left + 'px'; svg.style.top = top + 'px'; svg.style.width = w + 'px'; svg.style.height = h + 'px';
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    svg.setAttribute('class', 'guide ' + (state || ''));
    var parts = [];
    if (shot().id === 'closeup') {
      parts.push('<ellipse class="g-shape" cx="' + 0.5 * W + '" cy="' + 0.3 * H + '" rx="' + 0.17 * W + '" ry="' + 0.105 * H + '"/>');
      parts.push('<path class="g-shape" d="M' + 0.02 * W + ' ' + H + 'C' + 0.02 * W + ' ' + 0.62 * H + ' ' + 0.2 * W + ' ' + 0.53 * H + ' ' + 0.5 * W + ' ' + 0.53 * H +
        'C' + 0.8 * W + ' ' + 0.53 * H + ' ' + 0.98 * W + ' ' + 0.62 * H + ' ' + 0.98 * W + ' ' + H + '"/>');
      parts.push('<line class="g-eye" x1="0" y1="' + 0.31 * H + '" x2="' + 0.04 * W + '" y2="' + 0.31 * H + '"/><line class="g-eye" x1="' + 0.96 * W + '" y1="' + 0.31 * H + '" x2="' + W + '" y2="' + 0.31 * H + '"/>');
    } else {
      var s = 0.86 * H / 637;
      var tx = 0.5 * W - 195 * s, ty = 0.07 * H - 133 * s;
      parts.push('<g transform="translate(' + tx + ' ' + ty + ') scale(' + s + ')">' +
        '<ellipse class="g-shape" cx="195" cy="175" rx="36" ry="42"/>' +
        '<path class="g-shape" d="M152 228Q195 214 238 228L258 252 268 430 250 434 242 300 238 470 230 770 203 770 197 520 193 520 187 770 160 770 152 470 148 300 140 434 122 430 132 252Z"/></g>');
    }
    if (DEBUG && S.lm) {
      [0, 11, 12, 15, 16, 23, 24, 27, 28].forEach(function (i) {
        var p = S.lm[i];
        if (p && vis(p, 0.3)) parts.push('<circle class="g-dot" cx="' + p.x * W + '" cy="' + p.y * H + '" r="9"/>');
      });
    }
    svg.innerHTML = parts.join('');
  }
  window.addEventListener('resize', function () { guideKey = ''; });

  /* ---------- ustawianie i start na hasło ---------- */

  function enterAdjust(first) {
    S.phase = 'adjust';
    S.issue = null; S.issueSince = now();
    $('rec-row').hidden = true;
    $('countdown').hidden = true;
    $('btn-main').className = 'round';
    $('btn-main').setAttribute('aria-label', 'Start recording');
    caption('Recording starts when you say “Start”.');
    setBanner('info', 'Looking for you…', 'One moment.');
    if (first) {
      var line = S.shotIndex === 0 ? 'welcome' : 'next_full_body';
      bannerFor(line, 'info');
      say(line);
    }
  }

  function guidance(issue, t) {
    if (issue !== S.issue) { S.issue = issue; S.issueSince = t; }
    if (S.phase === 'adjust') {
      if (issue === 'loading') { if (!S.speaking) setBanner('info', 'Looking for you…', 'One moment.'); return; }
      if (!issue) {
        if (!S.speaking) setBanner('ok', 'Looking good', 'Hold still…');
        if (t - S.issueSince > T.okHold && !S.speaking) enterAwait();
        return;
      }
      if (!S.speaking) bannerFor(issue, 'warn');
      if (S.speaking || t - S.issueSince < T.issueDebounce || t - S.speakEnd < T.gapAfterSpeak) return;
      if (S.lastSaid === issue && t - S.lastSaidAt < T.repeatSame) return;
      bannerFor(issue, 'warn');
      say(issue);
    } else if (S.phase === 'await') {
      if (issue && issue !== 'loading' && t - S.issueSince > T.backToAdjust) {
        stopListening();
        log('Kadr się zepsuł (' + issue + ') – wracam do ustawiania');
        S.phase = 'adjust';
        S.issueSince = t;
        caption('Recording starts when you say “Start”.');
        return;
      }
      if (!S.speaking && t - S.awaitPromptAt > T.remindStart) {
        S.awaitPromptAt = t;
        stopListening();
        bannerFor('say_start_again', 'ok');
        say('say_start_again').then(function () { if (S.phase === 'await') startListening(); });
      }
    }
  }

  function enterAwait() {
    S.phase = 'await';
    S.awaitPromptAt = now();
    S.seg = null;
    bannerFor('ready_say_start', 'ok');
    caption('Say “Start” – or tap the red button.');
    log('Kadr OK – czekam na „Start”');
    say('ready_say_start').then(function () { if (S.phase === 'await') startListening(); });
  }

  function startMode() { return DEBUG && $('dbg-start') ? $('dbg-start').value : 'auto'; }
  function startListening() {
    var mode = startMode();
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    S.listenMode = (mode === 'vad' || !SR || (S.srFailed && mode === 'auto')) ? 'vad' : 'speech';
    S.diag.speechApi = SR ? 'jest' : 'brak';
    if (S.listenMode === 'speech') {
      try {
        var r = new SR();
        r.lang = 'en-US';
        r.continuous = true;
        r.interimResults = true;
        r.maxAlternatives = 3;
        r.onresult = function (ev) {
          if (S.speaking || now() - S.speakEnd < 900) return;
          S.srResults++;
          for (var i = ev.resultIndex; i < ev.results.length; i++) {
            for (var k = 0; k < ev.results[i].length; k++) {
              var tx = ev.results[i][k].transcript || '';
              S.lastHeard = tx;
              if (START_RE.test(tx)) { triggerStart('hasło: „' + tx.trim() + '”'); return; }
            }
          }
        };
        r.onerror = function (ev) {
          log('Rozpoznawanie mowy: ' + ev.error, ev.error !== 'no-speech' && ev.error !== 'aborted');
          if (['not-allowed', 'service-not-allowed', 'audio-capture', 'language-not-supported', 'network'].indexOf(ev.error) >= 0) {
            S.srFailed = true;
            if (startMode() === 'auto') { S.listenMode = 'vad'; log('Przełączam na: dowolne krótkie słowo'); }
          }
        };
        r.onend = function () {
          S.recog = null;
          checkMic();
          if (S.phase === 'await' && S.listenMode === 'speech' && !S.srFailed) {
            setTimeout(function () { if (S.phase === 'await' && !S.recog && !S.speaking) startListening(); }, 300);
          }
        };
        r.start();
        S.recog = r;
        log('Nasłuch: rozpoznawanie mowy');
        return;
      } catch (e) {
        S.srFailed = true;
        S.listenMode = 'vad';
        log('Rozpoznawanie mowy niedostępne: ' + errText(e), true);
      }
    }
    log('Nasłuch: dowolne krótkie słowo');
  }
  function stopListening() {
    if (S.recog) {
      var r = S.recog;
      S.recog = null;
      r.onend = function () { checkMic(); };
      try { r.abort(); } catch (e) { /* pomiń */ }
    }
  }
  function utterance(voice, t) {
    var seg = S.seg;
    if (voice) {
      if (!seg) S.seg = { start: t, last: t };
      else seg.last = t;
    } else if (seg && t - seg.last > 500) {
      var dur = seg.last - seg.start;
      S.seg = null;
      if (dur > 150 && dur < 1800) onUtterance(dur);
    }
  }
  function onUtterance(dur) {
    log('Krótka wypowiedź: ' + Math.round(dur) + ' ms');
    if (S.listenMode === 'vad') { triggerStart('krótkie słowo (' + Math.round(dur) + ' ms)'); return; }
    if (startMode() === 'auto') {
      var mark = S.srResults;
      setTimeout(function () {
        if (S.phase !== 'await' || S.srResults !== mark) return;
        S.srMiss++;
        log('Rozpoznawanie mowy nic nie zwróciło (' + S.srMiss + '×)');
        if (S.srMiss >= 2) { stopListening(); S.listenMode = 'vad'; log('Przełączam na: dowolne krótkie słowo'); }
      }, 2500);
    }
  }
  function triggerStart(reason) {
    if (S.phase !== 'await' && S.phase !== 'adjust') return;
    S.startReason = reason;
    log('START: ' + reason);
    stopListening();
    stopVoice();
    countdown();
  }

  async function countdown() {
    S.phase = 'countdown';
    bannerFor('countdown', 'ok');
    caption('');
    await checkMic();
    var cd = $('countdown');
    var marks = [[1050, '3'], [1750, '2'], [2450, '1']];
    var timers = marks.map(function (mk) { return setTimeout(function () { if (S.phase === 'countdown') { cd.textContent = mk[1]; cd.hidden = false; } }, mk[0]); });
    await play('countdown', true);
    timers.forEach(clearTimeout);
    cd.hidden = true;
    if (S.phase !== 'countdown') return;
    startRecording();
  }

  /* ---------- nagrywanie ---------- */

  function pickMime() {
    if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
    for (var i = 0; i < MIME_CANDIDATES.length; i++) {
      try { if (MediaRecorder.isTypeSupported(MIME_CANDIDATES[i])) return MIME_CANDIDATES[i]; } catch (e) { /* pomiń */ }
    }
    return '';
  }

  function startRecording() {
    var vt = S.stream.getVideoTracks()[0];
    var recStream = new MediaStream([vt, S.dest.stream.getAudioTracks()[0]]);
    S.mime = pickMime();
    var opts = { audioBitsPerSecond: 128000, videoBitsPerSecond: 10000000 };
    if (S.mime) opts.mimeType = S.mime;
    var recorder;
    try { recorder = new MediaRecorder(recStream, opts); }
    catch (e) { log('MediaRecorder z ustawieniami: ' + errText(e), true); recorder = new MediaRecorder(recStream); }
    S.rec = {
      recorder: recorder, chunks: [], start: now(), end: 0, mutes: [], qIdx: 0, muted: false, asking: false,
      minSaid: false, outSince: 0, outWarned: false, outEvents: [], handSince: 0, levels: [], lastLevelAt: 0,
      darkTicks: 0, ticks: 0, aborted: false, stopReason: '', shot: shot().id
    };
    var rec = S.rec;
    recorder.ondataavailable = function (ev) { if (ev.data && ev.data.size) rec.chunks.push(ev.data); };
    recorder.onerror = function (ev) { log('Błąd nagrywania: ' + errText(ev.error || ev), true); };
    recorder.onstop = function () { finishRecording(rec); };
    S.recGain.gain.value = 1;
    try { recorder.start(1000); } catch (e) { log('Nagrywanie nie ruszyło: ' + errText(e), true); fail('Recording didn’t start', 'Please reload the page and try again.'); return; }
    S.phase = 'recording';
    S.lastVoice = now();
    $('rec-row').hidden = false;
    $('rec-q').hidden = true;
    $('rec-mute').hidden = true;
    $('btn-main').className = 'round recording locked';
    $('btn-main').setAttribute('aria-label', 'Stop recording');
    setBanner('info', 'Recording', 'Look into the lens and answer naturally.');
    log('Nagrywanie: ' + (recorder.mimeType || S.mime || 'format domyślny') + ', start: ' + S.startReason);
    setTimeout(function () { if (S.rec === rec && S.phase === 'recording') askQuestion('pierwsze pytanie'); }, T.firstQuestion);
  }

  function setRecMute(on) {
    var g = S.recGain.gain, t = S.ctx.currentTime;
    g.cancelScheduledValues(t);
    g.setValueAtTime(g.value, t);
    g.linearRampToValueAtTime(on ? 0 : 1, t + 0.02);
    S.rec.muted = on;
    $('rec-mute').hidden = !on;
  }
  async function speakMuted(id, kind) {
    var rec = S.rec;
    if (!rec || rec.asking) return;
    rec.asking = true;
    var from = recT();
    setRecMute(true);
    await sleep(T.preMute);
    await play(id, kind === 'question');
    await sleep(T.tailMute);
    if (S.rec === rec) {
      setRecMute(false);
      rec.mutes.push({ from: from, to: recT(), id: id, kind: kind });
      rec.asking = false;
      S.lastVoice = now() + T.answerGrace;
    }
  }
  function askQuestion(reason) {
    var rec = S.rec;
    var list = shot().questions;
    var id = list[rec.qIdx % list.length];
    rec.qIdx++;
    $('rec-q').textContent = 'Question ' + rec.qIdx;
    $('rec-q').hidden = false;
    setBanner('info', 'Question ' + rec.qIdx, TEXT[id]);
    log('Pytanie ' + rec.qIdx + ' (' + reason + '): ' + id);
    return speakMuted(id, 'question');
  }

  function recordingWatch(fr, t) {
    var rec = S.rec;
    if (!rec) return;
    var el = (t - rec.start) / 1000;
    var min = minSec();
    $('rec-time').textContent = clock(el);
    $('rec-bar').style.width = Math.min(100, el / min * 100) + '%';
    $('rec-bar').parentNode.className = 'rec-progress' + (el >= min ? ' done' : '');
    caption(el < min ? 'Keep going until ' + clock(min) + ' – then raise your hand to finish.' : 'Raise your hand or tap the button to finish.');
    $('btn-main').className = 'round recording' + (el < min ? ' locked' : '');
    rec.ticks++;
    if (S.light.dark) rec.darkTicks++;
    if (el >= min && !rec.minSaid) { rec.minSaid = true; speakMuted('min_reached', 'info'); }
    if (el >= maxSec()) { stopRecording('limit czasu'); return; }

    if (S.pose) {
      var m = fr.m;
      var out = fr.issue === 'not_visible' || fr.issue === 'head_cut' || fr.issue === 'feet_cut' || (m && (m.cx < 0.18 || m.cx > 0.82));
      if (out) {
        if (!rec.outSince) rec.outSince = t;
        var dur = t - rec.outSince;
        if (dur > T.outWarn && !rec.outWarned) {
          rec.outWarned = true;
          log('Poza kadrem (' + fr.issue + ')');
          setBanner('warn', TEXT.out_of_frame[0], TEXT.out_of_frame[1]);
          speakMuted('out_of_frame', 'warning');
        }
        if (dur > T.outAbort) { abortRecording('poza kadrem > 3 s'); return; }
      } else if (rec.outSince) {
        rec.outEvents.push({ from: (rec.outSince - rec.start) / 1000, to: el });
        rec.outSince = 0;
        rec.outWarned = false;
      }
      if (el >= min && S.lm) {
        var lw = S.lm[15], rw = S.lm[16], eyeY = (S.lm[2].y + S.lm[5].y) / 2;
        var up = (vis(lw, 0.6) && lw.y < eyeY) || (vis(rw, 0.6) && rw.y < eyeY);
        if (up) {
          if (!rec.handSince) rec.handSince = t;
          if (t - rec.handSince > T.handHold) { stopRecording('podniesiona ręka'); return; }
        } else rec.handSince = 0;
      }
    }
    if (!rec.asking && t - S.lastVoice > T.silenceMs && t - rec.start > 4000) askQuestion('cisza 3 s');
  }

  function stopRecording(reason) {
    var rec = S.rec;
    if (!rec || S.phase !== 'recording') return;
    rec.stopReason = reason;
    rec.end = now();
    if (rec.outSince) rec.outEvents.push({ from: (rec.outSince - rec.start) / 1000, to: (rec.end - rec.start) / 1000 });
    S.phase = 'stopping';
    stopVoice();
    setRecMute(false);
    log('Stop: ' + reason);
    setBanner('ok', 'Done!', 'Come back to the phone to check the video.');
    caption('');
    $('btn-main').className = 'round';
    try { rec.recorder.stop(); } catch (e) { log('Zatrzymanie: ' + errText(e), true); }
  }
  function abortRecording(reason, silent) {
    var rec = S.rec;
    if (!rec || S.phase !== 'recording') return;
    rec.aborted = true;
    rec.stopReason = reason;
    log('Przerwane: ' + reason, true);
    stopVoice();
    try { rec.recorder.stop(); } catch (e) { /* pomiń */ }
    S.rec = null;
    S.recGain.gain.value = 1;
    enterAdjust(false);
    if (!silent) { bannerFor('start_over', 'warn'); say('start_over'); }
  }

  function finishRecording(rec) {
    if (rec.aborted) return;
    var type = rec.recorder.mimeType || S.mime || 'video/mp4';
    var blob = new Blob(rec.chunks, { type: type });
    var stats = recordingStats(rec);
    S.current = { shot: shot(), blob: blob, type: type, stats: stats, rec: rec, url: URL.createObjectURL(blob) };
    log('Plik: ' + (blob.size / 1048576).toFixed(1) + ' MB, ' + rec.chunks.length + ' kawałków');
    S.rec = null;
    S.phase = 'review';
    stopListening();
    renderReview();
  }

  function recordingStats(rec) {
    var dur = (rec.end - rec.start) / 1000;
    var outTotal = rec.outEvents.reduce(function (a, e) { return a + (e.to - e.from); }, 0);
    var thrDb = db(S.thr);
    var open = rec.levels.filter(function (l) { return !l.muted; });
    var muted = rec.levels.filter(function (l) { return l.muted; });
    var voiced = open.filter(function (l) { return l.db > thrDb; });
    var coverage = open.length ? voiced.length / open.length : 0;
    var clip = open.filter(function (l) { return l.db > -3; }).length / Math.max(1, open.length);
    var mutedMax = muted.length ? Math.max.apply(null, muted.map(function (l) { return l.db; })) : null;
    var vdb = voiced.map(function (l) { return l.db; }).sort(function (a, b) { return a - b; });
    var voiceMedian = vdb.length ? vdb[Math.floor(vdb.length / 2)] : null;
    var v = S.diag.video || {};
    return {
      duration: dur, outTotal: outTotal, outEvents: rec.outEvents, coverage: coverage, clip: clip,
      mutedMax: mutedMax, voiceMedian: voiceMedian, thrDb: thrDb, questions: rec.mutes.filter(function (x) { return x.kind === 'question'; }).length,
      darkShare: rec.ticks ? rec.darkTicks / rec.ticks : 0, width: v.width, height: v.height, fps: v.frameRate
    };
  }

  /* ---------- sprawdzenie nagrania ---------- */

  function renderReview() {
    var c = S.current, st = c.stats, min = minSec();
    show('review');
    $('review-step').textContent = 'Video ' + (S.shotIndex + 1) + ' of 2 · ' + c.shot.label;
    $('playback').src = c.url;
    var checks = [];
    checks.push({ ok: st.duration >= min - 0.5, text: 'Length', val: clock(st.duration) + ' (min ' + clock(min) + ')', hard: true });
    checks.push({ ok: !S.pose || st.outTotal < 1, text: S.pose ? 'In frame the whole time' : 'Framing check unavailable', val: st.outTotal >= 1 ? 'out for ' + st.outTotal.toFixed(1) + ' s' : '', hard: true });
    checks.push({ ok: st.coverage >= 0.3 && st.clip < 0.02, text: st.coverage < 0.3 ? 'We barely heard you' : (st.clip >= 0.02 ? 'Sound is distorted – too loud' : 'Clear audio'), val: '', hard: st.coverage < 0.1 });
    checks.push({ ok: st.darkShare < 0.15, text: st.darkShare < 0.15 ? 'Good lighting' : 'Too dark at times', val: '', hard: false });
    var portrait = st.height > st.width;
    checks.push({ ok: Math.min(st.width, st.height) >= 1080 && portrait, text: 'Quality', val: (Math.min(st.width, st.height) || '?') + 'p · ' + Math.round(st.fps || 0) + ' fps · ' + (portrait ? 'vertical' : 'horizontal'), hard: false });
    var hardFail = checks.some(function (x) { return x.hard && !x.ok; });
    var anyFail = checks.some(function (x) { return !x.ok; });
    var list = $('review-checks');
    list.innerHTML = '';
    checks.forEach(function (x) {
      var li = document.createElement('li');
      li.className = x.ok ? 'ok' : 'bad';
      li.innerHTML = (x.ok ? ICON_OK : ICON_BAD) + '<span class="r-text"></span><span class="r-val"></span>';
      li.querySelector('.r-text').textContent = x.text;
      li.querySelector('.r-val').textContent = x.val;
      list.appendChild(li);
    });
    $('verdict').className = 'verdict' + (hardFail ? ' bad' : '');
    $('verdict-ico').innerHTML = hardFail ? ICON_BAD.replace('r-ico', '') : ICON_OK.replace('r-ico', '');
    $('verdict-title').textContent = hardFail ? 'Please record again' : (anyFail ? 'Good enough' : 'Looks great!');
    $('verdict-sub').textContent = hardFail ? 'HeyGen needs one continuous take that passes these checks.' : 'Your ' + c.shot.label.toLowerCase() + ' is ready to send.';
    $('btn-send').textContent = hardFail ? 'Send anyway' : 'Send video';
    $('btn-send').className = 'btn ' + (hardFail ? 'secondary' : 'primary');
    $('btn-again').className = 'btn ' + (hardFail ? 'primary' : 'secondary');
    c.verdict = hardFail ? 'do powtórki' : (anyFail ? 'ok z uwagami' : 'ok');
    c.checks = checks;
  }

  /* ---------- wysyłka na Dysk ---------- */

  function api(payload, timeoutMs) {
    var ctl = window.AbortController ? new AbortController() : null;
    var timer = ctl ? setTimeout(function () { ctl.abort(); }, timeoutMs || 120000) : null;
    return fetch(CFG.scriptUrl, { method: 'POST', body: JSON.stringify(payload), signal: ctl ? ctl.signal : undefined, redirect: 'follow' })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .finally(function () { if (timer) clearTimeout(timer); });
  }
  function blobToBase64(blob) {
    return new Promise(function (resolve, reject) {
      var fr = new FileReader();
      fr.onload = function () { var s = String(fr.result); resolve(s.slice(s.indexOf(',') + 1)); };
      fr.onerror = function () { reject(fr.error); };
      fr.readAsDataURL(blob);
    });
  }
  function setProgress(p) {
    var pct = Math.round(p * 100);
    $('upload-pct').textContent = pct + '%';
    $('ring-fg').style.strokeDasharray = (528 * p).toFixed(1) + ' 528';
  }

  async function upload() {
    var c = S.current;
    show('upload');
    $('upload-step').textContent = 'Video ' + (S.shotIndex + 1) + ' of 2 · ' + c.shot.label;
    $('upload-error').hidden = true;
    $('btn-upload-retry').hidden = true;
    $('btn-upload-download').hidden = true;
    $('upload-title').textContent = 'Sending your ' + c.shot.label.toLowerCase();
    setProgress(0);
    var ext = c.type.indexOf('mp4') >= 0 ? 'mp4' : 'webm';
    var fileName = c.shot.file + '.' + ext;
    $('btn-upload-download').href = c.url;
    $('btn-upload-download').setAttribute('download', fileName);
    if (!CFG.scriptUrl) {
      $('upload-title').textContent = 'Google Drive isn’t connected yet';
      $('upload-sub').textContent = 'Save the video to this phone instead.';
      $('btn-upload-download').hidden = false;
      $('btn-upload-retry').hidden = false;
      $('btn-upload-retry').textContent = 'Continue';
      c.uploaded = 'brak Dysku';
      return;
    }
    var size = c.blob.size;
    var mb = function (b) { return (b / 1048576).toFixed(0); };
    $('upload-sub').textContent = '0 of ' + mb(size) + ' MB · keep this page open';
    var t0 = now();
    try {
      if (!c.session) {
        var st = await api({
          action: 'start', folder: S.folder, fileName: fileName, mimeType: c.type.split(';')[0], size: size,
          description: 'AI Models Partners app v' + CFG.version + ' · ' + c.shot.label + ' · ' + clock(c.stats.duration) + ' · ' + c.verdict
        }, 60000);
        if (!st.ok) throw new Error(st.error || 'start failed');
        c.session = st.session; c.folderUrl = st.folderUrl; c.offset = 0;
        log('Dysk: sesja wysyłania utworzona');
      }
      var attempts = 0;
      while (true) {
        var end = Math.min(size, c.offset + CHUNK);
        var res;
        try {
          var data = await blobToBase64(c.blob.slice(c.offset, end));
          res = await api({
            action: 'chunk', session: c.session, offset: c.offset, total: size, data: data,
            notify: end === size, folder: S.folder, summary: c.shot.label + ' · ' + clock(c.stats.duration) + ' · ' + c.verdict
          });
          if (!res.ok) throw new Error(res.error || ('kod ' + res.code));
          attempts = 0;
        } catch (e) {
          attempts++;
          log('Wysyłka: błąd kawałka (' + attempts + '): ' + errText(e), true);
          if (attempts > 5) throw e;
          $('upload-sub').textContent = 'Connection problem – retrying…';
          await sleep(1500 * attempts);
          var s2 = await api({ action: 'status', session: c.session, total: size }, 60000).catch(function () { return null; });
          if (s2 && s2.ok && s2.done) { res = s2; } else { if (s2 && s2.ok) c.offset = s2.next; continue; }
        }
        if (res.done) { c.link = res.link; break; }
        c.offset = res.next;
        setProgress(c.offset / size);
        var rate = c.offset / Math.max(1, (now() - t0) / 1000);
        $('upload-sub').textContent = mb(c.offset) + ' of ' + mb(size) + ' MB · about ' + Math.max(1, Math.round((size - c.offset) / rate)) + ' s left';
      }
      setProgress(1);
      c.uploaded = 'tak';
      log('Dysk: wysłano ' + fileName + ' (' + mb(size) + ' MB, ' + Math.round((now() - t0) / 1000) + ' s)');
      if (DEBUG) {
        api({ action: 'note', folder: S.folder, fileName: 'report-' + c.shot.file + '.txt', text: buildReport() }, 60000)
          .then(function () { log('Dysk: raport zapisany'); })
          .catch(function (e) { log('Dysk: raport nie zapisany: ' + errText(e), true); });
      }
      afterUpload();
    } catch (e) {
      c.uploaded = 'błąd: ' + errText(e);
      $('upload-title').textContent = 'Upload didn’t finish';
      $('upload-sub').textContent = 'Check your internet connection and try again.';
      $('upload-error').textContent = errText(e);
      $('upload-error').hidden = false;
      $('btn-upload-retry').textContent = 'Try again';
      $('btn-upload-retry').hidden = false;
      $('btn-upload-download').hidden = false;
    }
  }

  function afterUpload() {
    var c = S.current;
    S.results.push({ shot: c.shot.label, duration: c.stats.duration, link: c.link, verdict: c.verdict, uploaded: c.uploaded });
    if (S.shotIndex === 0) {
      S.shotIndex = 1;
      S.current = null;
      openSetup();
    } else {
      finishAll();
    }
  }
  function finishAll() {
    stopLoops();
    stopListening();
    var list = $('done-list');
    list.innerHTML = '';
    S.results.forEach(function (r) {
      var li = document.createElement('li');
      li.className = 'ok';
      li.innerHTML = ICON_OK + '<span class="r-text"></span><span class="r-val"></span>';
      li.querySelector('.r-text').textContent = r.shot;
      li.querySelector('.r-val').textContent = clock(r.duration);
      list.appendChild(li);
    });
    show('done');
    if (S.ctx) play('all_done', true);
    if (S.stream) S.stream.getTracks().forEach(function (t) { t.stop(); });
    if (S.wakeLock) S.wakeLock.release().catch(function () {});
  }

  /* ---------- ekrany ---------- */

  function openSetup() {
    var sh = shot();
    $('setup-step').textContent = 'Video ' + (S.shotIndex + 1) + ' of 2';
    $('bar2').className = S.shotIndex === 1 ? 'on' : '';
    $('setup-title').textContent = sh.title;
    $('setup-sub').textContent = sh.sub;
    $('illu-closeup').hidden = sh.id !== 'closeup';
    $('illu-fullbody').hidden = sh.id !== 'fullbody';
    $('illu-distance').textContent = sh.distance;
    $('illu-height').textContent = sh.height;
    var ul = $('setup-tips');
    ul.innerHTML = '';
    sh.tips.forEach(function (tip) { var li = document.createElement('li'); li.textContent = tip; ul.appendChild(li); });
    show('setup');
  }

  async function openCamera() {
    ensureAudio();
    requestSensors();
    keepAwake();
    show('camera');
    guideKey = '';
    setBanner('info', 'Starting camera…', 'Allow camera and microphone access.');
    try {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw new Error('no-camera-api');
      if (!window.MediaRecorder) throw new Error('no-recorder');
      if (!S.stream || S.stream.getVideoTracks()[0].readyState !== 'live') await startStream();
      var v = $('preview');
      v.srcObject = S.stream;
      try { await v.play(); } catch (e) { log('Podgląd: ' + errText(e)); }
      loadPose();
      startLoops();
      enterAdjust(true);
    } catch (e) {
      log('Kamera: ' + errText(e), true);
      var msg = 'Please reload the page and try again.';
      if (e && e.name === 'NotAllowedError') msg = 'Camera or microphone access was blocked. Allow both in your browser settings for this site (in Safari: tap “aA” → Website Settings), then reload the page.';
      else if (e && e.message === 'no-camera-api') msg = 'This browser can’t use the camera. Open the link in Safari (iPhone) or Chrome (Android).';
      else if (e && e.message === 'no-recorder') msg = 'This browser can’t record video. Update your phone or open the link in Safari or Chrome.';
      else if (e && e.name === 'NotReadableError') msg = 'The camera is busy. Close other apps that use the camera and try again.';
      fail('Camera didn’t start', msg);
    }
  }

  function leaveCamera() {
    if (S.phase === 'recording') abortRecording('wyjście z kamery', true);
    stopListening();
    stopVoice();
    S.phase = 'idle';
    openSetup();
  }

  /* ---------- raport (tryb testowy) ---------- */

  function buildReport() {
    var d = S.diag, v = d.video || {}, a = d.audio || {}, c = S.current;
    var L = [];
    L.push('RAPORT v' + CFG.version + ' – ' + new Date().toLocaleString('pl-PL'));
    L.push('Klient: ' + S.name + ' | folder: ' + S.folder);
    L.push('Przeglądarka: ' + navigator.userAgent);
    L.push('Kamera: ' + (d.videoLabel || '?') + ' | ' + v.width + '×' + v.height + ' @' + Math.round(v.frameRate || 0) + ' | kamery: ' + (d.cameras || []).join(' / '));
    L.push('Mikrofon: ' + (d.audioLabel || '?') + ' | ' + a.sampleRate + ' Hz | echo=' + a.echoCancellation + ' szumy=' + a.noiseSuppression + ' AGC=' + a.autoGainControl + ' | ponowne włączenia: ' + (d.micReacquired || 0));
    L.push('Rozpoznawanie sylwetki: ' + (S.pose ? 'działa (' + d.delegate + ', ładowanie ' + d.poseLoadMs + ' ms, klatek ' + S.detectCount + ', błędów ' + S.detectErrors + ')' : 'NIE (' + (S.poseError || 'ładuje się') + ')'));
    L.push('Czujniki: zgoda=' + (d.motionPermission || 'n/d') + ' poziom=' + (S.tilt.known ? S.tilt.roll.toFixed(1) + '°' : 'brak') + ' | wygaszanie: wakeLock=' + (d.wakeLock || 'brak') + ', wideo=' + (d.keepAwakeVideo || 'brak'));
    L.push('Dźwięk: tło ' + db(S.noiseFloor).toFixed(1) + ' dB, próg mowy ' + db(S.thr).toFixed(1) + ' dB | audioSession=' + d.audioSession + ' | głośność lektora ×' + voiceVolume());
    L.push('Start: tryb=' + startMode() + ', użyty=' + S.listenMode + ', API mowy=' + (d.speechApi || '?') + ', wyników=' + S.srResults + ', ostatnio słyszane: „' + S.lastHeard + '”, powód startu: ' + (S.startReason || '-'));
    if (c) {
      var st = c.stats;
      L.push('--- Nagranie: ' + c.shot.label + ' ---');
      L.push('Plik: ' + c.type + ' | ' + (c.blob.size / 1048576).toFixed(1) + ' MB | długość ' + fmt(st.duration) + ' | stop: ' + c.rec.stopReason + ' | werdykt: ' + c.verdict);
      L.push('Głos klienta: słychać przez ' + Math.round(st.coverage * 100) + '% czasu (poza pytaniami) | mediana ' + (st.voiceMedian != null ? st.voiceMedian.toFixed(1) : '?') + ' dB | przester ' + (st.clip * 100).toFixed(1) + '%');
      L.push('Pytania: ' + st.questions + ' | najgłośniej w czasie wyciszenia: ' + (st.mutedMax != null ? st.mutedMax.toFixed(1) + ' dB' : '-') + ' (powinno być poniżej -60)');
      L.push('Poza kadrem: ' + st.outTotal.toFixed(1) + ' s ' + st.outEvents.map(function (e) { return fmt(e.from) + '–' + fmt(e.to); }).join(', ') + ' | za ciemno: ' + Math.round(st.darkShare * 100) + '%');
      L.push('Wyciszenia: ' + c.rec.mutes.map(function (m) { return m.id + ' ' + fmt(m.from) + '–' + fmt(m.to); }).join(', '));
      L.push('Poziom dźwięku co 1 s (dB, * = wyciszone): ' + levelTimeline(c.rec.levels));
      L.push('Wysyłka: ' + (c.uploaded || 'nie') + (c.link ? ' ' + c.link : ''));
    }
    L.push('Błędy: ' + (S.errors.length ? S.errors.slice(-15).join(' || ') : 'brak'));
    L.push('--- Dziennik ---');
    return L.concat(S.logLines.slice(-150)).join('\n');
  }
  function levelTimeline(levels) {
    var out = [], sec = -1, maxDb = -120, mut = false;
    levels.forEach(function (l) {
      var s = Math.floor(l.t);
      if (s !== sec) { if (sec >= 0) out.push(Math.round(maxDb) + (mut ? '*' : '')); sec = s; maxDb = -120; mut = false; }
      maxDb = Math.max(maxDb, l.db);
      mut = mut || l.muted;
    });
    if (sec >= 0) out.push(Math.round(maxDb) + (mut ? '*' : ''));
    return out.join(' ');
  }
  function debugLive() {
    if (!$('ampac-dbg') || $('ampac-dbg').hidden) return;
    var m = S.fr && S.fr.m, f = function (x) { return x == null ? '-' : x.toFixed(2); };
    $('dbg-live').textContent = [
      'faza: ' + S.phase + ' | problem: ' + (S.issue || '-') + ' | mówi: ' + S.speaking,
      'sylwetka: ' + (S.pose ? S.diag.delegate : (S.poseError ? 'błąd' : 'ładuje')) + ' klatki ' + S.detectCount,
      m ? 'cx ' + f(m.cx) + ' barki ' + f(m.shW) + ' oczy ' + f(m.eyeY) + ' czubek ' + f(m.headTop) + (m.feetY != null ? ' stopy ' + f(m.feetY) + ' wys ' + f(m.bodyH) : '') : 'brak osoby',
      'światło: twarz ' + (S.light.face == null ? '-' : Math.round(S.light.face)) + ' tło ' + (S.light.bg == null ? '-' : Math.round(S.light.bg)) + ' całość ' + (S.light.all == null ? '-' : Math.round(S.light.all)),
      'poziom: ' + (S.tilt.known ? S.tilt.roll.toFixed(1) + '° / pochyl. ' + S.tilt.pitch.toFixed(0) + '°' : 'brak') + ' | ruch: ' + (S.motion.unsteady ? 'TAK' : 'nie'),
      'mikrofon: ' + db(S.rms).toFixed(1) + ' dB, próg ' + db(S.thr).toFixed(1) + ' dB, tło ' + db(S.noiseFloor).toFixed(1) + ' dB',
      'nasłuch: ' + S.listenMode + ' wyników ' + S.srResults + ' „' + S.lastHeard + '”'
    ].join('\n');
  }

  /* ---------- zdarzenia ---------- */

  function nameValid(v) { return /\S{2,}\s+\S{2,}/.test(v.trim()); }
  $('btn-welcome').addEventListener('click', function () {
    var v = $('inp-name').value.replace(/\s+/g, ' ').trim();
    if (!nameValid(v)) { $('name-error').hidden = false; $('inp-name').focus(); return; }
    $('name-error').hidden = true;
    S.name = v;
    var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
    S.folder = v + ' – ' + d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + '-' + p(d.getMinutes()) + (DEBUG ? ' (TEST)' : '');
    ensureAudio();
    openSetup();
  });
  $('inp-name').addEventListener('keydown', function (e) { if (e.key === 'Enter') $('btn-welcome').click(); });
  $('btn-setup-back').addEventListener('click', function () { if (S.shotIndex === 0) show('welcome'); else openSetup(); });
  $('btn-open-camera').addEventListener('click', openCamera);
  $('btn-cam-back').addEventListener('click', leaveCamera);
  $('btn-main').addEventListener('click', function () {
    if (S.phase === 'adjust' || S.phase === 'await') { triggerStart('przycisk'); return; }
    if (S.phase === 'recording') {
      if (recT() >= minSec()) stopRecording('przycisk');
      else caption('Please keep going until ' + clock(minSec()) + '.');
    }
  });
  $('btn-voice').addEventListener('click', function () {
    S.voiceOn = !S.voiceOn;
    this.classList.toggle('on', S.voiceOn);
    this.setAttribute('aria-pressed', String(S.voiceOn));
    if (!S.voiceOn) stopVoice();
  });
  $('btn-send').addEventListener('click', upload);
  $('btn-again').addEventListener('click', function () {
    if (S.current && S.current.url) URL.revokeObjectURL(S.current.url);
    S.current = null;
    openCamera();
  });
  $('btn-upload-retry').addEventListener('click', function () {
    if (S.current && S.current.uploaded === 'brak Dysku') { afterUpload(); return; }
    upload();
  });
  $('btn-error-retry').addEventListener('click', function () { window.location.reload(); });

  if (DEBUG) {
    $('dbg-toggle').addEventListener('click', function () { $('ampac-dbg').hidden = !$('ampac-dbg').hidden; debugLive(); });
    $('dbg-close').addEventListener('click', function () { $('ampac-dbg').hidden = true; });
    $('dbg-vol').addEventListener('change', function () { if (S.voiceGain) S.voiceGain.gain.value = voiceVolume(); });
    $('dbg-say').addEventListener('click', function () { ensureAudio(); play('q2', true); });
    $('dbg-copy').addEventListener('click', function () {
      var text = buildReport();
      var done = function () { $('dbg-copy').textContent = 'Skopiowano ✓'; setTimeout(function () { $('dbg-copy').textContent = 'Kopiuj raport'; }, 2000); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () { window.prompt('Skopiuj raport:', text); });
      else window.prompt('Skopiuj raport:', text);
    });
    window.__ampac = { S: S, analyzeFraming: analyzeFraming, buildReport: buildReport, triggerStart: triggerStart, stopRecording: stopRecording };
  }
  if (/FBAN|FBAV|Instagram|Line\/|Messenger|TikTok/i.test(navigator.userAgent)) {
    $('name-error').textContent = 'For best results, open this link in Safari or Chrome.';
    $('name-error').hidden = false;
  }
  if (!window.isSecureContext) fail('Secure connection needed', 'Please open this page with https://');
  log('Aplikacja gotowa v' + CFG.version + (DEBUG ? ' (tryb testowy)' : ''));
})();

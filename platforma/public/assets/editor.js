/* Edytor kursu: struktura (drag-and-drop), lekcje, bloki, quizy. Zapis na serwerze po każdej zmianie. */
(function () {
  'use strict';
  var boot = JSON.parse(document.getElementById('boot').textContent);
  var BASE = boot.base || '';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var ICON = {
    grip: '<svg class="i" viewBox="0 0 24 24"><g fill="currentColor" stroke="none"><circle cx="9" cy="6" r="1.4"/><circle cx="15" cy="6" r="1.4"/><circle cx="9" cy="12" r="1.4"/><circle cx="15" cy="12" r="1.4"/><circle cx="9" cy="18" r="1.4"/><circle cx="15" cy="18" r="1.4"/></g></svg>',
    plus: '<svg class="i" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
    trash: '<svg class="i" viewBox="0 0 24 24"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>',
    video: '<svg class="i" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9.5l5 2.5-5 2.5z"/></svg>',
    quiz: '<svg class="i" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/></svg>',
    file: '<svg class="i" viewBox="0 0 24 24"><path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5"/></svg>',
    image: '<svg class="i" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M4 18l5-5 4 4 3-3 4 4"/></svg>',
    clip: '<svg class="i" viewBox="0 0 24 24"><path d="M20 11l-8 8a5 5 0 0 1-7-7l8-8a3.5 3.5 0 0 1 5 5l-8 8a2 2 0 0 1-3-3l7-7"/></svg>',
    upload: '<svg class="i" viewBox="0 0 24 24"><path d="M12 16V4M7 9l5-5 5 5M4 20h16"/></svg>',
    check: '<svg class="i" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>'
  };

  /* ---------- sieć ---------- */
  var pending = 0, savedTimer;
  function setSaved(state) {
    var el = $('#saved'); if (!el) return;
    if (state === 'busy') el.textContent = 'Zapisywanie…';
    else if (state === 'err') el.innerHTML = '<span class="bad">Błąd zapisu</span>';
    else el.innerHTML = ICON.check + 'Zapisano';
  }
  function toast(msg) {
    var t = $('#toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = msg; t.hidden = false; clearTimeout(t._t); t._t = setTimeout(function () { t.hidden = true; }, 3500);
  }
  function api(action, body, keepalive) {
    pending++; setSaved('busy');
    return fetch(BASE + '/admin/api/' + action, {
      method: 'POST', keepalive: !!keepalive,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrf, 'X-Requested-With': 'fetch', 'Accept': 'application/json' },
      body: JSON.stringify(body || {}), credentials: 'same-origin'
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Błąd serwera (' + r.status + ')' }; }); })
      .then(function (j) {
        pending--; if (!j.ok) { setSaved('err'); toast(j.error || 'Błąd zapisu'); throw new Error(j.error); }
        clearTimeout(savedTimer); savedTimer = setTimeout(function () { if (!pending) setSaved('ok'); }, 250); return j;
      }, function (err) { pending--; setSaved('err'); toast('Brak połączenia z serwerem'); throw err; });
  }
  var timers = {}, flushers = {};
  function debounced(key, fn, ms) { clearTimeout(timers[key]); flushers[key] = fn; timers[key] = setTimeout(function () { delete flushers[key]; fn(); }, ms || 600); }
  function flushAll() { Object.keys(flushers).forEach(function (k) { clearTimeout(timers[k]); var f = flushers[k]; delete flushers[k]; try { f(); } catch (e) {} }); }
  window.addEventListener('pagehide', flushAll);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flushAll(); });
  window.addEventListener('beforeunload', function (e) { if (pending || Object.keys(flushers).length) { flushAll(); e.preventDefault(); e.returnValue = ''; } });
  function arm(btn, fn) {
    if (btn.classList.contains('armed')) { fn(); return; }
    btn.classList.add('armed'); var t = btn.title; btn.title = 'Kliknij ponownie, żeby usunąć';
    setTimeout(function () { btn.classList.remove('armed'); btn.title = t; }, 2500);
  }

  /* ---------- rozpoznawanie filmów (jak po stronie serwera) ---------- */
  function vimeoOf(u) {
    var m = String(u || '').match(/vimeo\.com\/(?:.*?\/)?(\d{6,})(?:\/([a-f0-9]{6,16}))?/i); if (!m) return null;
    var h = m[2] || ((String(u).match(/[?&]h=([a-f0-9]{6,16})/i) || [])[1]) || '';
    return { id: m[1], hash: h };
  }
  function ytOf(u) { var m = String(u || '').trim().match(/(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/|v\/)|youtu\.be\/)([\w-]{11})/i); return m ? m[1] : null; }

  /* ---------- stan ---------- */
  var C = boot.course, tree = boot.tree, curLesson = null, blocks = [], quizzesCache = null, quizState = {};
  var lessonsFlat = function () { return tree.reduce(function (a, m) { return a.concat(m.lessons); }, []); };

  /* ---------- czas dostępu ---------- */
  function renderAcc() {
    var lim = C.access_amount > 0;
    $('#acc').innerHTML = '<span class="acc-k">' + '<svg class="i" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>' + 'Czas dostępu</span>' +
      '<select class="in" id="acc-mode" aria-label="Rodzaj dostępu"><option value="none"' + (lim ? '' : ' selected') + '>Bezterminowy</option><option value="lim"' + (lim ? ' selected' : '') + '>Ograniczony</option></select>' +
      (lim ? '<input class="in" type="number" min="1" max="99" id="acc-n" value="' + C.access_amount + '" aria-label="Liczba"><select class="in" id="acc-u" aria-label="Jednostka"><option value="months"' + (C.access_unit === 'years' ? '' : ' selected') + '>miesięcy</option><option value="years"' + (C.access_unit === 'years' ? ' selected' : '') + '>lat</option></select><span class="small muted">od dnia przypisania kursu</span>' : '') +
      '<span class="small muted acc-h">Dotyczy nowych przypisań. Każdemu klientowi możesz zmienić datę osobno.</span>';
    $('#acc-mode').onchange = function () { C.access_amount = this.value === 'lim' ? (C.access_amount || 12) : 0; saveAcc(); renderAcc(); };
    if (lim) {
      $('#acc-n').oninput = function () { C.access_amount = Math.max(1, Math.min(99, Math.round(+this.value) || 1)); debounced('acc', saveAcc, 500); };
      $('#acc-u').onchange = function () { C.access_unit = this.value; saveAcc(); };
    }
  }
  function saveAcc() { api('course.update', { id: C.id, access_amount: C.access_amount, access_unit: C.access_unit }); }
  $('#c-title').addEventListener('input', function () { var v = this.value; debounced('ctitle', function () { if (v.trim()) api('course.update', { id: C.id, title: v }); }, 600); });
  $('#c-del').addEventListener('click', function () {
    arm(this, function () { api('course.delete', { id: C.id }).then(function () { location.href = BASE + '/admin'; }); });
  });

  /* ---------- drzewo ---------- */
  function lessonIcons(l) {
    if (!l.videos && !l.quizzes) return '';
    var t = [l.videos ? 'Nagrania w lekcji: ' + l.videos : '', l.quizzes ? 'Quiz w lekcji' : ''].filter(Boolean).join(', ');
    return '<span class="vi" title="' + esc(t) + '">' + (l.videos ? ICON.video + (l.videos > 1 ? '<small>' + l.videos + '</small>' : '') : '') + (l.quizzes ? ICON.quiz : '') + '</span>';
  }
  function lessonRow(l) {
    return '<div class="les' + (curLesson && curLesson.id === l.id ? ' sel' : '') + '" data-id="' + l.id + '"><span class="grip" title="Przeciągnij lekcję">' + ICON.grip + '</span><button class="les-t" type="button" data-act="sel" data-id="' + l.id + '">' + esc(l.title || 'Bez tytułu') + '</button>' + lessonIcons(l) + '<button class="icb" type="button" data-act="del-les" data-id="' + l.id + '" title="Usuń lekcję" aria-label="Usuń lekcję">' + ICON.trash + '</button></div>';
  }
  function renderTree() {
    $('#tree').innerHTML = '<div id="mods">' + tree.map(function (m) {
      return '<div class="mod" data-id="' + m.id + '"><div class="mod-h"><span class="grip" title="Przeciągnij moduł">' + ICON.grip + '</span><input class="mod-t" data-act="mtitle" data-id="' + m.id + '" value="' + esc(m.title) + '" aria-label="Nazwa modułu" maxlength="300"><span class="cnt">' + m.lessons.length + '</span><button class="icb" type="button" data-act="add-les" data-id="' + m.id + '" title="Dodaj lekcję" aria-label="Dodaj lekcję">' + ICON.plus + '</button><button class="icb" type="button" data-act="del-mod" data-id="' + m.id + '" title="Usuń moduł" aria-label="Usuń moduł">' + ICON.trash + '</button></div><div class="lessons">' + m.lessons.map(lessonRow).join('') + '</div></div>';
    }).join('') + '</div>';
    if (!window.Sortable) return;
    var o = { animation: 150, ghostClass: 'drag-ghost', delay: 150, delayOnTouchOnly: true };
    new Sortable($('#mods'), Object.assign({}, o, { draggable: '.mod', handle: '.mod-h > .grip', onEnd: syncTree }));
    $$('#mods .lessons').forEach(function (el) { new Sortable(el, Object.assign({}, o, { group: 'les', draggable: '.les', handle: '.grip', onEnd: syncTree })); });
  }
  function syncTree() {
    var all = {}, mm = {};
    tree.forEach(function (m) { mm[m.id] = m; m.lessons.forEach(function (l) { all[l.id] = l; }); });
    var layout = {};
    tree = $$('#mods .mod').map(function (el) {
      var m = mm[el.dataset.id]; m.lessons = $$('.les', el).map(function (x) { return all[x.dataset.id]; });
      layout[m.id] = m.lessons.map(function (l) { return l.id; }); $('.cnt', el).textContent = m.lessons.length; return m;
    });
    api('module.reorder', { course_id: C.id, order: tree.map(function (m) { return m.id; }) })
      .then(function () { return api('lesson.layout', { course_id: C.id, layout: layout }); });
  }
  $('#tree').addEventListener('click', function (e) {
    var t = e.target.closest('[data-act]'); if (!t) return; var id = +t.dataset.id;
    if (t.dataset.act === 'sel') openLesson(id);
    else if (t.dataset.act === 'add-les') {
      api('lesson.add', { module_id: id }).then(function (j) {
        tree.filter(function (m) { return m.id === id; })[0].lessons.push(j.lesson); renderTree(); openLesson(j.lesson.id, true);
      });
    } else if (t.dataset.act === 'del-mod') {
      arm(t, function () { api('module.delete', { id: id }).then(function () { tree = tree.filter(function (m) { return m.id !== id; }); if (curLesson && !lessonsFlat().some(function (l) { return l.id === curLesson.id; })) { curLesson = null; } renderTree(); if (!curLesson) openFirst(); }); });
    } else if (t.dataset.act === 'del-les') {
      arm(t, function () { api('lesson.delete', { id: id }).then(function () { tree.forEach(function (m) { m.lessons = m.lessons.filter(function (l) { return l.id !== id; }); }); var was = curLesson && curLesson.id === id; renderTree(); if (was) { curLesson = null; openFirst(); } }); });
    }
  });
  $('#tree').addEventListener('input', function (e) {
    var t = e.target.closest('[data-act=mtitle]'); if (!t) return; var id = +t.dataset.id, v = t.value;
    tree.filter(function (m) { return m.id === id; })[0].title = v; debounced('mt' + id, function () { api('module.update', { id: id, title: v }); }, 600);
  });
  $('#add-module').addEventListener('click', function () {
    api('module.add', { course_id: C.id }).then(function (j) { tree.push(j.module); renderTree(); var ins = $$('.mod-t'); var l = ins[ins.length - 1]; l.focus(); l.select(); });
  });
  function openFirst() { var f = lessonsFlat()[0]; if (f) openLesson(f.id); else { curLesson = null; $('#pane').innerHTML = '<div class="empty"><b>Ten kurs nie ma jeszcze lekcji</b><p>Dodaj lekcję przyciskiem plus przy module.</p></div>'; } }

  /* ---------- lekcja i bloki ---------- */
  function openLesson(id, focusTitle) {
    flushAll();
    var meta = lessonsFlat().filter(function (l) { return l.id === id; })[0]; if (!meta) return;
    curLesson = meta; $$('#mods .les').forEach(function (x) { x.classList.toggle('sel', +x.dataset.id === id); });
    $('#pane').innerHTML = '<p class="muted">Wczytywanie lekcji…</p>';
    api('lesson.get', { id: id }).then(function (j) {
      if (!curLesson || curLesson.id !== id) return;
      blocks = j.blocks; renderPane(); if (focusTitle) { var t = $('#lt'); t.focus(); t.select(); }
    });
  }
  function renderPane() {
    var l = curLesson;
    $('#pane').innerHTML = '<div><label class="l" for="lt">Tytuł lekcji</label><input id="lt" class="in" value="' + esc(l.title) + '" maxlength="300"></div><div><span class="l">Zawartość lekcji</span><div id="blocks"></div></div>' +
      '<div class="addbar">' + [['text', 'Blok tekstu'], ['vimeo', 'Wideo z Vimeo'], ['youtube', 'Wideo z YouTube'], ['image', 'Obraz (JPG, PNG)'], ['file', 'Plik (PDF, ZIP)'], ['quiz', 'Quiz']].map(function (b) { return '<button class="btn" type="button" data-add="' + b[0] + '">' + ICON.plus + b[1] + '</button>'; }).join('') + '</div>' +
      '<p class="small muted" style="margin:0">Bloki układasz w dowolnej kolejności, na przykład tekst, film, tekst, drugi film. Przeciągnij za uchwyt, żeby je przestawić.</p>';
    $('#lt').addEventListener('input', function () {
      var v = this.value; l.title = v; var b = $('.les[data-id="' + l.id + '"] .les-t'); if (b) b.textContent = v || 'Bez tytułu';
      debounced('lt' + l.id, function () { api('lesson.update', { id: l.id, title: v }); }, 600);
    });
    $$('[data-add]').forEach(function (b) { b.addEventListener('click', function () { addBlock(b.dataset.add); }); });
    renderBlocks();
  }
  var LABEL = { text: [ICON.file, 'Tekst'], vimeo: [ICON.video, 'Wideo Vimeo'], youtube: [ICON.video, 'Wideo YouTube'], image: [ICON.image, 'Obraz'], file: [ICON.clip, 'Plik do pobrania'], quiz: [ICON.quiz, 'Quiz'], embed: [ICON.video, 'Osadzenie (Spotify)'] };
  function blockShell(b, body) {
    var lb = LABEL[b.type] || LABEL.text;
    return '<div class="blk" data-id="' + b.id + '"><div class="blk-h"><span class="grip" title="Przeciągnij blok">' + ICON.grip + '</span><span class="blk-k">' + lb[0] + lb[1] + '</span><span class="sp"></span><button class="icb" type="button" data-bact="del" title="Usuń blok" aria-label="Usuń blok">' + ICON.trash + '</button></div><div class="blk-b">' + body + '</div></div>';
  }
  function renderBlocks() {
    var box = $('#blocks');
    box.innerHTML = blocks.length ? blocks.map(blockHtml).join('') : '<div class="empty" style="border:1.5px dashed var(--line-strong);border-radius:10px">Lekcja jest pusta. Dodaj blok tekstu lub wideo.</div>';
    blocks.forEach(function (b) { afterBlock(b); });
    if (window.Sortable && blocks.length) new Sortable(box, { animation: 150, ghostClass: 'drag-ghost', delay: 150, delayOnTouchOnly: true, draggable: '.blk', handle: '.blk-h .grip', onEnd: function () {
      var m = {}; blocks.forEach(function (b) { m[b.id] = b; }); blocks = $$('.blk', box).map(function (x) { return m[x.dataset.id]; });
      api('block.reorder', { lesson_id: curLesson.id, order: blocks.map(function (b) { return b.id; }) });
    } });
  }
  function blockHtml(b) {
    switch (b.type) {
      case 'text': return blockShell(b, '<div class="tb" role="toolbar" aria-label="Formatowanie">' + [['bold', 'Pogrubienie', '<b>B</b>'], ['italic', 'Kursywa', '<i>I</i>'], ['h2', 'Nagłówek', 'H2'], ['insertUnorderedList', 'Lista', 'Lista'], ['link', 'Link', 'Link']].map(function (x) { return '<button type="button" data-fmt="' + x[0] + '" title="' + x[1] + '">' + x[2] + '</button>'; }).join('') + '</div><div class="rte" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Tekst">' + (b.content || '') + '</div>');
      case 'vimeo': case 'youtube':
        return blockShell(b, '<div><input class="in" data-vurl value="' + esc(b.content) + '" placeholder="' + (b.type === 'youtube' ? 'https://www.youtube.com/watch?v=M7lc1UVf-VE' : 'https://vimeo.com/824517390') + '" inputmode="url" autocomplete="off" aria-label="' + (b.type === 'youtube' ? 'Link do filmu na YouTube' : 'Link do nagrania na Vimeo') + '"><div class="vstat"></div></div><div class="vprev"></div>');
      case 'image': return blockShell(b, imageBody(b));
      case 'file': return blockShell(b, fileBody(b));
      case 'embed': return blockShell(b, '<p class="small muted">Osadzenie z importu (Spotify). Adres: <span class="mono">' + esc(b.content) + '</span></p>');
      case 'quiz': return blockShell(b, '<div class="quizbox" data-quizbox>Wczytywanie quizu…</div>');
    }
    return '';
  }
  function afterBlock(b) {
    var el = $('.blk[data-id="' + b.id + '"]'); if (!el) return;
    if (b.type === 'vimeo' || b.type === 'youtube') vstat(el, b);
    if (b.type === 'quiz') loadQuiz(el, b);
  }
  function vstat(el, b) {
    var v = (b.content || '').trim(), yt = b.type === 'youtube', ok = yt ? ytOf(v) : vimeoOf(v), nm = yt ? 'YouTube' : 'Vimeo';
    $('.vstat', el).innerHTML = !v ? '<span class="muted">Wklej link z ' + nm + ', odtwarzacz pojawi się poniżej.</span>' : ok ? '<span class="ok">' + ICON.check + 'Rozpoznano nagranie</span><span class="mono muted">ID ' + esc(yt ? ok : ok.id) + (ok.hash ? ' · link prywatny' : '') + '</span>' : '<span class="bad">To nie wygląda na link do ' + nm + '. Skopiuj adres ze strony ' + (yt ? 'filmu' : 'nagrania') + '.</span>';
    var src = !ok ? '' : yt ? 'https://www.youtube-nocookie.com/embed/' + ok : 'https://player.vimeo.com/video/' + ok.id + '?dnt=1' + (ok.hash ? '&h=' + ok.hash : '');
    $('.vprev', el).innerHTML = src ? '<div class="video"><iframe src="' + esc(src) + '" title="Podgląd" loading="lazy" allowfullscreen allow="fullscreen; picture-in-picture"></iframe></div>' : '<div class="video empty"><span>Brak nagrania</span></div>';
  }
  function imageBody(b) {
    var imgs = (b.data && b.data.images) || [];
    return '<div class="thumbs">' + imgs.map(function (im, i) {
      var u = (b.urls && b.urls[i]) || im.src || '';
      return '<div class="thumb" data-i="' + i + '"><img src="' + esc(u) + '" alt="" loading="lazy"><div class="stack" style="gap:6px"><input class="in" data-f="alt" value="' + esc(im.alt || '') + '" placeholder="Opis obrazu (alt) – krótki opis tego, co widać" aria-label="Opis obrazu (alt)" maxlength="300"><input class="in" data-f="caption" value="' + esc(im.caption || '') + '" placeholder="Podpis (opcjonalnie)" aria-label="Podpis" maxlength="500"></div><button class="icb" type="button" data-bact="rm-img" title="Usuń obraz" aria-label="Usuń obraz">' + ICON.trash + '</button></div>';
    }).join('') + '</div><div class="upl"><div class="t"><b>' + (imgs.length ? 'Dodaj kolejny obraz (galeria)' : 'Nie wybrano obrazu') + '</b><div class="small muted">JPG lub PNG, do ' + boot.limits.image_mb + ' MB</div></div><label class="btn sm" for="up-' + b.id + '">' + ICON.upload + (imgs.length ? 'Dodaj obraz' : 'Wybierz plik') + '</label><input id="up-' + b.id + '" type="file" accept=".jpg,.jpeg,.png" data-up="image" hidden></div><div class="err" data-err></div>';
  }
  function fileBody(b) {
    var d = b.data || {}, has = d.media_id || d.url;
    var meta = d.media_id && b.meta ? (b.meta.ext.toUpperCase() + ' · ' + b.meta.size) : (d.url ? 'Plik zdalny (z importu): ' + d.url : '');
    return '<div class="upl"><div class="t"><b>' + esc(has ? (d.name || 'Plik') : 'Nie wybrano pliku') + '</b><div class="small muted">' + esc(meta || ('PDF, ZIP, DOCX, XLSX lub PPTX, do ' + boot.limits.file_mb + ' MB')) + '</div></div><label class="btn sm" for="up-' + b.id + '">' + ICON.upload + (has ? 'Zmień plik' : 'Wybierz plik') + '</label><input id="up-' + b.id + '" type="file" accept="' + boot.limits.file_ext.map(function (x) { return '.' + x; }).join(',') + '" data-up="file" hidden></div>' +
      (has ? '<div><label class="l" for="fn-' + b.id + '">Nazwa wyświetlana</label><input id="fn-' + b.id + '" class="in" data-f="fname" value="' + esc(d.name || '') + '" maxlength="200"></div>' : '') + '<div class="err" data-err></div>';
  }
  function blk(el) { var id = +el.closest('.blk').dataset.id; return blocks.filter(function (b) { return b.id === id; })[0]; }
  function updateIcons() {
    var m = lessonsFlat().filter(function (l) { return l.id === curLesson.id; })[0];
    m.videos = blocks.filter(function (b) { return (b.type === 'vimeo' && vimeoOf(b.content)) || (b.type === 'youtube' && ytOf(b.content)); }).length;
    m.quizzes = blocks.filter(function (b) { return b.type === 'quiz'; }).length;
    var row = $('.les[data-id="' + m.id + '"]'); if (!row) return; var vi = $('.vi', row); if (vi) vi.remove(); $('.les-t', row).insertAdjacentHTML('afterend', lessonIcons(m));
  }
  function saveBlock(b, extra) {
    var body = { id: b.id }; Object.assign(body, extra || {});
    return api('block.update', body).then(function (j) { if (j.block) { b.data = j.block.data; b.urls = j.block.urls; b.meta = j.block.meta; if (j.sanitized !== undefined) b.content = j.sanitized; } return j; });
  }
  function addBlock(type) {
    api('block.add', { lesson_id: curLesson.id, type: type }).then(function (j) {
      blocks.push(j.block); renderBlocks(); updateIcons(); var el = $('.blk[data-id="' + j.block.id + '"]'); el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      var f = $('input:not([type=file]),.rte', el); if (f) f.focus();
    });
  }
  /* delegacja zdarzeń w panelu bloków */
  var pane = $('#pane');
  pane.addEventListener('click', function (e) {
    var t = e.target.closest('[data-bact],[data-fmt]'); if (!t) return;
    if (t.dataset.fmt) {
      var rte = $('.rte', t.closest('.blk')); rte.focus(); var c = t.dataset.fmt;
      if (c === 'h2') document.execCommand('formatBlock', false, (document.queryCommandValue('formatBlock') || '').toLowerCase() === 'h2' ? 'p' : 'h2');
      else if (c === 'link') { var u = window.prompt('Adres linku (https://…):', 'https://'); if (u && /^(https?:\/\/|mailto:|\/)/i.test(u)) document.execCommand('createLink', false, u); }
      else document.execCommand(c);
      var b = blk(rte); b.content = rte.innerHTML; debounced('b' + b.id, function () { saveBlock(b, { content: b.content }); }, 400); return;
    }
    var el = t.closest('.blk'), b2 = blk(el);
    if (t.dataset.bact === 'del') arm(t, function () { api('block.delete', { id: b2.id }).then(function () { blocks = blocks.filter(function (x) { return x.id !== b2.id; }); renderBlocks(); updateIcons(); }); });
    else if (t.dataset.bact === 'rm-img') {
      var i = +t.closest('.thumb').dataset.i; b2.data.images.splice(i, 1); b2.urls && b2.urls.splice(i, 1);
      saveBlock(b2, { data: b2.data }).then(function () { rerender(b2); });
    }
  });
  function rerender(b) { var el = $('.blk[data-id="' + b.id + '"]'); if (!el) return; $('.blk-b', el).innerHTML = b.type === 'image' ? imageBody(b) : fileBody(b); }
  pane.addEventListener('mousedown', function (e) { if (e.target.closest('[data-fmt]')) e.preventDefault(); });
  pane.addEventListener('input', function (e) {
    var t = e.target; var el = t.closest('.blk'); if (!el) return; var b = blk(el);
    if (t.classList.contains('rte')) { b.content = t.innerHTML; debounced('b' + b.id, function () { saveBlock(b, { content: b.content }); }, 600); }
    else if (t.hasAttribute('data-vurl')) { b.content = t.value; vstat(el, b); updateIcons(); debounced('b' + b.id, function () { saveBlock(b, { content: b.content }); }, 600); }
    else if (t.dataset.f === 'alt' || t.dataset.f === 'caption') { var i = +t.closest('.thumb').dataset.i; b.data.images[i][t.dataset.f] = t.value; debounced('b' + b.id, function () { saveBlock(b, { data: b.data }); }, 600); }
    else if (t.dataset.f === 'fname') { b.data.name = t.value; debounced('b' + b.id, function () { saveBlock(b, { data: b.data }); }, 600); }
  });
  pane.addEventListener('focusout', function (e) {
    var t = e.target; if (!t.classList || !t.classList.contains('rte')) return; var b = blk(t);
    flushers['b' + b.id] && (clearTimeout(timers['b' + b.id]), delete flushers['b' + b.id]);
    saveBlock(b, { content: t.innerHTML }).then(function () { if (document.activeElement !== t) t.innerHTML = b.content; });
  });
  pane.addEventListener('paste', function (e) {
    if (!e.target.classList || !e.target.classList.contains('rte')) return;
    e.preventDefault(); var txt = (e.clipboardData || window.clipboardData).getData('text/plain'); document.execCommand('insertText', false, txt);
  });
  pane.addEventListener('change', function (e) {
    var t = e.target; if (!t.hasAttribute || !t.hasAttribute('data-up')) return;
    var fl = t.files[0]; if (!fl) return; var el = t.closest('.blk'), b = blk(el), kind = t.dataset.up, err = $('[data-err]', el);
    var ext = (fl.name.split('.').pop() || '').toLowerCase(), allowed = kind === 'image' ? boot.limits.image_ext : boot.limits.file_ext, lim = kind === 'image' ? boot.limits.image_mb : boot.limits.file_mb;
    if (allowed.indexOf(ext) < 0) { err.textContent = 'Ten typ pliku nie jest obsługiwany. Wybierz ' + allowed.join(', ').toUpperCase() + '.'; t.value = ''; return; }
    if (fl.size > lim * 1048576) { err.textContent = 'Plik jest za duży. Maksymalny rozmiar to ' + lim + ' MB.'; t.value = ''; return; }
    err.textContent = 'Wysyłanie…';
    var fd = new FormData(); fd.append('file', fl); fd.append('kind', kind); fd.append('_csrf', boot.csrf);
    pending++; setSaved('busy');
    fetch(BASE + '/admin/upload', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Błąd serwera (' + r.status + ')' }; }); })
      .then(function (j) {
        pending--; if (!j.ok) { setSaved('err'); err.textContent = j.error; t.value = ''; return; }
        err.textContent = '';
        if (kind === 'image') { b.data = b.data && b.data.images ? b.data : { images: [] }; b.data.images.push({ media_id: j.media.id, alt: j.media.name.replace(/\.[^.]+$/, ''), caption: '' }); }
        else { b.data = { media_id: j.media.id, name: j.media.name }; }
        saveBlock(b, { data: b.data }).then(function () { rerender(b); });
      }, function () { pending--; setSaved('err'); err.textContent = 'Brak połączenia z serwerem.'; });
  });

  /* ---------- quiz ---------- */
  var QT = { single: 'Jedna poprawna odpowiedź', multi: 'Wiele poprawnych odpowiedzi', text: 'Odpowiedź opisowa', file: 'Przesłanie pliku' };
  function loadQuiz(el, b) {
    var qid = b.data && b.data.quiz_id; var box = $('[data-quizbox]', el);
    if (!qid) { box.innerHTML = quizPicker(b) ; return; }
    api('quiz.get', { id: qid }).then(function (j) { quizState[b.id] = j.quiz; renderQuiz(el, b); });
  }
  function quizPicker(b) { return '<p class="muted">Ten blok nie ma quizu.</p><button class="btn sm" type="button" data-qact="new">Utwórz nowy quiz</button>'; }
  function renderQuiz(el, b) {
    var q = quizState[b.id], box = $('[data-quizbox]', el);
    box.innerHTML = '<div class="row" style="margin-bottom:8px"><label class="l" style="margin:0" for="qs-' + b.id + '">Quiz z biblioteki</label><select class="in sel-w" id="qs-' + b.id + '" data-qact="pick"><option value="">…</option></select><button class="btn sm" type="button" data-qact="new">Nowy quiz</button></div>' +
      '<div class="qhead"><div><label class="l" for="qt-' + b.id + '">Tytuł quizu</label><input id="qt-' + b.id + '" class="in" data-qf="title" value="' + esc(q.title) + '"></div><div><label class="l" for="qp-' + b.id + '">Próg zaliczenia</label><div class="sfx"><input id="qp-' + b.id + '" class="in" type="number" min="0" max="100" data-qf="pass" value="' + q.pass + '"><span class="muted">%</span></div></div><div><label class="l" for="qn-' + b.id + '">Podejścia</label><select id="qn-' + b.id + '" class="in" data-qf="tries">' + [[0, 'bez limitu'], [1, '1'], [3, '3']].map(function (x) { return '<option value="' + x[0] + '"' + (q.tries === x[0] ? ' selected' : '') + '>' + x[1] + '</option>'; }).join('') + '</select></div></div>' +
      q.questions.map(function (qq, i) { return qHtml(qq, i); }).join('') + '<div class="row"><button class="btn sm" type="button" data-qact="addq">Dodaj pytanie</button></div><p class="small muted" style="margin:0">Pytania zamknięte są oceniane automatycznie. Pytania opisowe i z plikiem trafiają do oceny ręcznej (zakładka Wyniki) i nie liczą się do wyniku automatycznego.</p>';
    fillPicker(b, q);
  }
  function fillPicker(b, q) {
    var get = quizzesCache ? Promise.resolve(quizzesCache) : api('quiz.list', {}).then(function (j) { quizzesCache = j.quizzes; return quizzesCache; });
    get.then(function (list) { var s = $('#qs-' + b.id); if (!s) return; s.innerHTML = list.map(function (z) { return '<option value="' + z.id + '"' + (z.id === q.id ? ' selected' : '') + '>#' + z.id + ' ' + esc(z.title) + ' (' + z.questions + ' pyt.' + (z.used ? ', użyty ' + z.used + '×' : '') + ')</option>'; }).join(''); });
  }
  function qHtml(q, i) {
    var auto = q.type === 'single' || q.type === 'multi', none = auto && !q.answers.some(function (a) { return a.correct; });
    return '<div class="qq" data-qi="' + i + '"><div class="qq-h"><b>Pytanie ' + (i + 1) + '</b><select class="in sel-w" data-qf="qtype" aria-label="Rodzaj pytania">' + Object.keys(QT).map(function (k) { return '<option value="' + k + '"' + (q.type === k ? ' selected' : '') + '>' + QT[k] + '</option>'; }).join('') + '</select><span class="sp"></span><button class="icb" type="button" data-qact="delq" title="Usuń pytanie" aria-label="Usuń pytanie">' + ICON.trash + '</button></div><input class="in" data-qf="qtext" value="' + esc(q.text) + '" placeholder="Treść pytania" aria-label="Treść pytania">' +
      (auto ? q.answers.map(function (a, k) { return '<div class="qo" data-ai="' + k + '"><input type="' + (q.type === 'single' ? 'radio' : 'checkbox') + '" name="ok-' + i + '-' + (q.id || 'n') + '" data-qf="acorrect"' + (a.correct ? ' checked' : '') + ' title="Zaznacz, jeśli to poprawna odpowiedź" aria-label="Poprawna odpowiedź"><input class="in" data-qf="atext" value="' + esc(a.text) + '" placeholder="Treść odpowiedzi" aria-label="Treść odpowiedzi"><button class="icb" type="button" data-qact="dela" title="Usuń odpowiedź" aria-label="Usuń odpowiedź">' + ICON.trash + '</button></div>'; }).join('') + '<div class="row"><button class="lnk" type="button" data-qact="adda">+ Dodaj odpowiedź</button></div>' + (none ? '<div class="bad small">Zaznacz co najmniej jedną poprawną odpowiedź.</div>' : '<div class="small muted">Zaznaczone pole oznacza poprawną odpowiedź.</div>') : '<div class="small muted">' + (q.type === 'text' ? 'Odpowiedź opisowa: oceniasz ją ręcznie.' : 'Klient prześle plik, a Ty ocenisz go ręcznie.') + '</div>') + '</div>';
  }
  function saveQuiz(b) {
    var q = quizState[b.id];
    debounced('q' + q.id, function () { api('quiz.save', { id: q.id, title: q.title, pass: q.pass, tries: q.tries, questions: q.questions }).then(function (j) { /* zachowaj identyfikatory */ q.questions.forEach(function (qq, i) { var s = j.quiz.questions[i]; if (s) { qq.id = s.id; qq.answers.forEach(function (a, k) { if (s.answers[k]) a.id = s.answers[k].id; }); } }); quizzesCache = null; }); }, 700);
  }
  pane.addEventListener('click', function (e) {
    var t = e.target.closest('[data-qact]'); if (!t || t.tagName === 'SELECT') return; var el = t.closest('.blk'), b = blk(el), a = t.dataset.qact;
    if (a === 'new') { api('quiz.new', { block_id: b.id }).then(function (j) { b.data = j.block.data; quizzesCache = null; loadQuiz(el, b); }); return; }
    var q = quizState[b.id]; if (!q) return; var qi = t.closest('.qq') ? +t.closest('.qq').dataset.qi : -1;
    if (a === 'addq') { q.questions.push({ id: 0, type: 'single', text: '', answers: [{ id: 0, text: '', correct: true }, { id: 0, text: '', correct: false }] }); renderQuiz(el, b); saveQuiz(b); }
    else if (a === 'delq') arm(t, function () { q.questions.splice(qi, 1); renderQuiz(el, b); saveQuiz(b); });
    else if (a === 'adda') { q.questions[qi].answers.push({ id: 0, text: '', correct: false }); renderQuiz(el, b); saveQuiz(b); }
    else if (a === 'dela') { var qq = q.questions[qi]; if (qq.answers.length <= 2) { toast('Pytanie potrzebuje co najmniej dwóch odpowiedzi'); return; } qq.answers.splice(+t.closest('.qo').dataset.ai, 1); renderQuiz(el, b); saveQuiz(b); }
  });
  pane.addEventListener('change', function (e) {
    var t = e.target, el = t.closest && t.closest('.blk'); if (!el) return;
    if (t.dataset.qact === 'pick') { var b0 = blk(el); if (!t.value) return; api('quiz.attach', { block_id: b0.id, quiz_id: +t.value }).then(function (j) { b0.data = j.block.data; loadQuiz(el, b0); }); return; }
    if (!t.dataset.qf) return; var b = blk(el), q = quizState[b.id]; if (!q) return; var f = t.dataset.qf, qe = t.closest('.qq'), qq = qe ? q.questions[+qe.dataset.qi] : null;
    if (f === 'tries') { q.tries = +t.value; saveQuiz(b); }
    else if (f === 'qtype') { qq.type = t.value; if ((qq.type === 'single' || qq.type === 'multi') && !qq.answers.length) qq.answers = [{ id: 0, text: '', correct: true }, { id: 0, text: '', correct: false }]; if (qq.type === 'single') { var seen = false; qq.answers.forEach(function (a) { if (a.correct && !seen) seen = true; else a.correct = false; }); } renderQuiz(el, b); saveQuiz(b); }
    else if (f === 'acorrect') { var k = +t.closest('.qo').dataset.ai; qq.answers.forEach(function (a, i) { if (i === k) a.correct = t.checked; else if (qq.type === 'single') a.correct = false; }); renderQuiz(el, b); saveQuiz(b); }
  });
  pane.addEventListener('input', function (e) {
    var t = e.target; if (!t.dataset || !t.dataset.qf) return; var el = t.closest('.blk'), b = blk(el), q = quizState[b.id]; if (!q) return; var f = t.dataset.qf, qe = t.closest('.qq'), qq = qe ? q.questions[+qe.dataset.qi] : null;
    if (f === 'title') q.title = t.value; else if (f === 'pass') q.pass = Math.max(0, Math.min(100, Math.round(+t.value) || 0));
    else if (f === 'qtext') qq.text = t.value; else if (f === 'atext') qq.answers[+t.closest('.qo').dataset.ai].text = t.value; else return;
    saveQuiz(b);
  });

  /* ---------- start ---------- */
  renderAcc(); renderTree();
  var first = boot.open && lessonsFlat().some(function (l) { return l.id === boot.open; }) ? boot.open : null;
  if (first) openLesson(first); else openFirst();
})();

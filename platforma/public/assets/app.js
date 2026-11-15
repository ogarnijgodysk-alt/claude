/* Wspólne zachowania panelu i widoku klienta (bez zależności). */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var o = e.target.closest('[data-open]');
    if (o) {
      var d = document.getElementById(o.getAttribute('data-open'));
      if (d && d.showModal) { d.showModal(); var f = d.querySelector('input,select,textarea'); if (f) f.focus(); }
      return;
    }
    if (e.target.closest('[data-close]')) {
      var dd = e.target.closest('dialog'); if (dd) dd.close(); return;
    }
    var a = e.target.closest('[data-arm]');
    if (a) {
      // „kliknij ponownie, żeby potwierdzić”
      if (!a.classList.contains('armed')) {
        e.preventDefault();
        a.classList.add('armed');
        a.dataset.title = a.title; a.title = 'Kliknij ponownie, żeby potwierdzić';
        setTimeout(function () { a.classList.remove('armed'); a.title = a.dataset.title || ''; }, 2500);
      }
    }
  });
  document.addEventListener('submit', function (e) {
    var b = e.submitter;
    if (b && b.hasAttribute('data-arm') && !b.classList.contains('armed')) { e.preventDefault(); }
    var c = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (c && !window.confirm(c)) { e.preventDefault(); }
  });
  document.querySelectorAll('dialog.modal-d').forEach(function (d) {
    d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
  });
  // automatyczne wysłanie formularza po zmianie filtra
  document.addEventListener('change', function (e) {
    if (e.target.hasAttribute && e.target.hasAttribute('data-autosubmit') && e.target.form) e.target.form.submit();
    var c = e.target.closest && e.target.closest('[data-enroll-row]');
    if (c && e.target.type === 'checkbox') {
      var d = c.querySelector('input[type=date]');
      if (e.target.checked) { if (!d.value) d.value = e.target.dataset.default || ''; } else { d.value = ''; }
    }
  });
  // hurtowe zaznaczanie klientów
  var form = document.getElementById('bulk-form');
  if (form) {
    var bar = document.getElementById('bulk-bar'), cnt = document.getElementById('bulk-count'), all = document.getElementById('bulk-all'),
      rows = [].slice.call(form.querySelectorAll('.sel-row')), page = document.getElementById('sel-page'), total = +form.dataset.total || 0,
      selAll = document.getElementById('bulk-select-all');
    var fmt = function (n) { return n.toLocaleString('pl-PL'); };
    var upd = function () {
      var n = rows.filter(function (r) { return r.checked; }).length;
      cnt.textContent = all.value === '1' ? 'Zaznaczono ' + fmt(total) + ' (wszyscy pasujący)' : 'Zaznaczono ' + fmt(n);
      bar.hidden = !(n || all.value === '1');
      page.checked = rows.length > 0 && rows.every(function (r) { return r.checked; });
      selAll.hidden = all.value === '1' || total <= n;
    };
    rows.forEach(function (r) { r.addEventListener('change', function () { all.value = '0'; upd(); }); });
    page.addEventListener('change', function () { all.value = '0'; rows.forEach(function (r) { r.checked = page.checked; }); upd(); });
    selAll.addEventListener('click', function () { all.value = '1'; rows.forEach(function (r) { r.checked = true; }); upd(); });
    document.getElementById('bulk-clear').addEventListener('click', function () { all.value = '0'; rows.forEach(function (r) { r.checked = false; }); upd(); });
  }
})();

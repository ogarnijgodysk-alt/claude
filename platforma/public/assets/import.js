/* Import klientów: przeciąganie pliku i pasek postępu (kolejne porcje zlecane do serwera). */
(function () {
  'use strict';
  var drop = document.getElementById('drop'), file = document.getElementById('file'), form = document.getElementById('upload-form');
  if (drop && file && form) {
    file.addEventListener('change', function () { if (file.files[0]) form.submit(); });
    ['dragover', 'dragenter'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.remove('over'); }); });
    drop.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files.length) { file.files = e.dataTransfer.files; form.submit(); }
    });
  }
  var run = document.getElementById('run');
  if (run && run.dataset.running === '1') {
    var bar = document.getElementById('run-bar'), txt = document.getElementById('run-text'), fails = 0;
    var nf = function (n) { return n.toLocaleString('pl-PL'); };
    var step = function () {
      fetch(run.dataset.tick, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': run.dataset.csrf, 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (s) {
          fails = 0;
          if (!s.ok) { txt.textContent = s.error || 'Błąd importu'; return; }
          var pct = s.total ? Math.floor(s.done / s.total * 100) : 0;
          bar.style.width = pct + '%';
          txt.textContent = 'Przetworzono ' + nf(s.done) + ' z ' + nf(s.total) + ' · dodano ' + nf(s.added);
          if (s.finished) { location.reload(); } else { setTimeout(step, 150); }
        })
        .catch(function () { if (++fails < 5) setTimeout(step, 2000); else txt.textContent = 'Utracono połączenie z serwerem. Import będzie kontynuowany w tle (cron); odśwież stronę, żeby zobaczyć postęp.'; });
    };
    step();
  }
})();

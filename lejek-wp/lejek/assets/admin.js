/* Lejek: odświeżanie listy zamówień na żywo i powiadomienia w przeglądarce. */
(function () {
	var box = document.getElementById('lejek-orders-live');
	var btn = document.getElementById('lejek-notify');

	if (btn) {
		if (!('Notification' in window) || Notification.permission === 'granted') {
			btn.hidden = true;
		} else {
			btn.addEventListener('click', function () {
				Notification.requestPermission().then(function (p) {
					if (p === 'granted') { btn.hidden = true; }
				});
			});
		}
	}

	if (!box || !window.LejekAdmin || !window.fetch) { return; }

	var latest = parseInt(box.getAttribute('data-latest') || '0', 10);
	var title = document.title;

	function poll() {
		fetch(LejekAdmin.rest + 'lejek/v1/orders/latest', {
			headers: { 'X-WP-Nonce': LejekAdmin.nonce },
			credentials: 'same-origin'
		}).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
			if (!d || d.latest_id <= latest) { return; }
			latest = d.latest_id;
			box.hidden = false;
			box.querySelector('.lejek-live-text').textContent = 'Nowe zamówienie: ' + d.latest_label;
			document.title = '(' + d.new_count + ') ' + title;
			if ('Notification' in window && Notification.permission === 'granted') {
				new Notification('Nowe zamówienie', { body: d.latest_label });
			}
		}).catch(function () {});
	}

	setInterval(poll, 20000);
})();

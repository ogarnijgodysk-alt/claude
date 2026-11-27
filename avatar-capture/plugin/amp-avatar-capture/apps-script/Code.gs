/**
 * AI Models Partners – Avatar Capture: odbiornik nagrań na Dysk Google.
 *
 * Wdrożenie: Wdróż → Nowe wdrożenie → Aplikacja internetowa
 *   Wykonaj jako: Ja   |   Kto ma dostęp: Każdy
 * Adres aplikacji (…/exec) wklej w WordPressie: Ustawienia → Avatar Capture.
 *
 * Telefon klienta wysyła nagranie w kawałkach (po 8 MB). Skrypt zakłada
 * folder klienta w „Avatar footage” i składa plik na Dysku (resumable upload).
 */

var ROOT_FOLDER_NAME = 'Avatar footage';
var MAX_FILE_BYTES = 3 * 1024 * 1024 * 1024; // 3 GB
var NOTIFY_BY_EMAIL = true;                  // mail do właściciela po każdym nagraniu
var VERSION = 1;

function doGet() {
  return json_({ ok: true, app: 'amp-avatar-capture-drive', version: VERSION });
}

function doPost(e) {
  try {
    var req = JSON.parse(e.postData.contents);
    switch (req.action) {
      case 'ping':   return json_({ ok: true, version: VERSION });
      case 'start':  return json_(start_(req));
      case 'chunk':  return json_(chunk_(req));
      case 'status': return json_(status_(req));
      case 'note':   return json_(note_(req));
      default:       return json_({ ok: false, error: 'unknown action' });
    }
  } catch (err) {
    return json_({ ok: false, error: String((err && err.message) || err) });
  }
}

/** Zakłada folder klienta i sesję wysyłania na Dysk. */
function start_(req) {
  var size = Number(req.size);
  if (!(size > 0) || size > MAX_FILE_BYTES) throw new Error('bad size');
  var mime = String(req.mimeType || 'video/mp4');
  if (!/^video\//.test(mime)) throw new Error('only video files');
  var folder = clientFolder_(req.folder);
  var name = cleanName_(req.fileName, 'video.mp4');

  var resp = UrlFetchApp.fetch('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id', {
    method: 'post',
    contentType: 'application/json; charset=UTF-8',
    headers: {
      Authorization: 'Bearer ' + ScriptApp.getOAuthToken(),
      'X-Upload-Content-Type': mime,
      'X-Upload-Content-Length': String(size)
    },
    payload: JSON.stringify({ name: name, parents: [folder.getId()], description: String(req.description || '').slice(0, 2000) }),
    muteHttpExceptions: true
  });
  if (resp.getResponseCode() !== 200) throw new Error('drive start ' + resp.getResponseCode() + ': ' + resp.getContentText().slice(0, 300));
  var h = resp.getAllHeaders();
  var session = h.Location || h.location;
  if (!session) throw new Error('no upload session');
  return { ok: true, session: session, folderId: folder.getId(), folderUrl: folder.getUrl() };
}

/** Dokleja kawałek pliku (base64) do sesji wysyłania. */
function chunk_(req) {
  var bytes = Utilities.base64Decode(req.data);
  var offset = Number(req.offset);
  var total = Number(req.total);
  var end = offset + bytes.length - 1;
  var resp = UrlFetchApp.fetch(req.session, {
    method: 'put',
    contentType: 'application/octet-stream',
    headers: { 'Content-Range': 'bytes ' + offset + '-' + end + '/' + total },
    payload: bytes,
    muteHttpExceptions: true
  });
  return uploadResult_(resp, req);
}

/** Sprawdza, ile bajtów Dysk już ma (wznawianie po zerwaniu połączenia). */
function status_(req) {
  var resp = UrlFetchApp.fetch(req.session, {
    method: 'put',
    headers: { 'Content-Range': 'bytes */' + Number(req.total) },
    muteHttpExceptions: true
  });
  return uploadResult_(resp, req);
}

function uploadResult_(resp, req) {
  var code = resp.getResponseCode();
  if (code === 308) {
    var h = resp.getAllHeaders();
    var range = h.Range || h.range;
    var next = 0;
    if (range) next = Number(String(range).split('-')[1]) + 1;
    return { ok: true, done: false, next: next };
  }
  if (code === 200 || code === 201) {
    var file = JSON.parse(resp.getContentText());
    var link = 'https://drive.google.com/file/d/' + file.id + '/view';
    if (NOTIFY_BY_EMAIL && req.notify) notify_(req, link);
    return { ok: true, done: true, fileId: file.id, link: link };
  }
  return { ok: false, code: code, error: resp.getContentText().slice(0, 300) };
}

/** Zapisuje mały plik tekstowy (np. raport jakości) w folderze klienta. */
function note_(req) {
  var folder = clientFolder_(req.folder);
  var text = String(req.text || '').slice(0, 200000);
  var file = folder.createFile(cleanName_(req.fileName, 'note.txt'), text, MimeType.PLAIN_TEXT);
  return { ok: true, fileId: file.getId(), folderUrl: folder.getUrl() };
}

function notify_(req, link) {
  try {
    var to = Session.getEffectiveUser().getEmail();
    if (!to) return;
    var who = cleanName_(req.folder, 'Client');
    MailApp.sendEmail(to, 'New recording: ' + who, 'New video from ' + who + ':\n' + link + '\n\n' + String(req.summary || ''));
  } catch (e) {
    // brak maila nie może zepsuć wysyłki
  }
}

function clientFolder_(name) {
  var root = folderIn_(DriveApp.getRootFolder(), ROOT_FOLDER_NAME);
  return folderIn_(root, cleanName_(name, 'Unnamed client'));
}

function folderIn_(parent, name) {
  var it = parent.getFoldersByName(name);
  return it.hasNext() ? it.next() : parent.createFolder(name);
}

function cleanName_(value, fallback) {
  var s = String(value || '').replace(/[\u0000-\u001f\\\/:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120);
  return s || fallback;
}

function json_(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj)).setMimeType(ContentService.MimeType.JSON);
}

/* CloneStudio — asystent wideo na stronę. Wklej:
   <script src="https://TWOJA-DOMENA/embed.js" data-assistant="ID_ASYSTENTA" async></script> */
(function () {
  var script = document.currentScript;
  if (!script) return;
  var id = script.getAttribute("data-assistant");
  var origin = new URL(script.src).origin;
  var color = script.getAttribute("data-color") || "#7c3aed";
  if (!id) return;

  fetch(origin + "/api/assistants/" + encodeURIComponent(id) + "/public")
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (a) { if (a) mount(a); })
    .catch(function () {});

  function mount(a) {
    var host = document.createElement("div");
    host.style.cssText = "position:fixed;right:max(16px,env(safe-area-inset-right));bottom:max(16px,env(safe-area-inset-bottom));z-index:2147483000;font-family:system-ui,sans-serif";
    var shadow = host.attachShadow ? host.attachShadow({ mode: "open" }) : host;
    shadow.innerHTML =
      '<style>' +
      '.b{display:flex;align-items:center;gap:10px;border:0;cursor:pointer;background:#fff;border-radius:999px;padding:6px 16px 6px 6px;box-shadow:0 8px 30px rgba(0,0,0,.18);font:600 14px system-ui,sans-serif;color:#18181b}' +
      '.b img,.b i{width:48px;height:48px;border-radius:50%;object-fit:cover;display:block;background:' + color + '}' +
      '.b:focus-visible{outline:3px solid ' + color + '}' +
      '.p{position:fixed;right:16px;bottom:88px;width:380px;height:620px;max-height:calc(100vh - 110px);border-radius:18px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25);background:#fff;display:none}' +
      '.p.o{display:block}.p iframe{width:100%;height:100%;border:0}' +
      '.x{position:absolute;top:10px;right:10px;width:32px;height:32px;border:0;border-radius:50%;background:rgba(0,0,0,.06);cursor:pointer;font-size:16px}' +
      '@media (max-width:480px){.p{inset:0;width:auto;height:auto;max-height:none;border-radius:0}}' +
      '</style>' +
      '<div class="p" role="dialog" aria-label="Rozmowa z ' + esc(a.name) + '"><button class="x" aria-label="Zamknij">✕</button></div>' +
      '<button class="b" aria-label="Porozmawiaj z ' + esc(a.name) + '">' +
      (a.cover ? '<img alt="" src="' + origin + a.cover + '">' : "<i></i>") +
      "<span>Porozmawiaj z " + esc(a.name) + "</span></button>";
    var panel = shadow.querySelector(".p");
    var frame;
    shadow.querySelector(".b").addEventListener("click", function () {
      if (!frame) {
        frame = document.createElement("iframe");
        frame.src = origin + "/widget/" + encodeURIComponent(id);
        frame.allow = "camera; microphone; autoplay; fullscreen; display-capture";
        frame.title = "Rozmowa z " + a.name;
        panel.appendChild(frame);
      }
      panel.classList.toggle("o");
    });
    shadow.querySelector(".x").addEventListener("click", function () {
      panel.classList.remove("o");
      // Removing the frame ends any live call and releases the microphone.
      if (frame) { frame.remove(); frame = null; }
    });
    document.body.appendChild(host);
  }

  function esc(s) {
    return String(s || "").replace(/[&<>"']/g, function (c) { return "&#" + c.charCodeAt(0) + ";"; });
  }
})();

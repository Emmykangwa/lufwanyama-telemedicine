/* assets/js/app.js  --  interruption tolerance (NFR3) plus two small helpers.
 *
 * 1. Any form marked data-offline="name" keeps a draft in localStorage while
 *    the user types, so a reload, a flat battery or a lost page does not
 *    erase half an hour of typing.
 * 2. Pressing Submit while the phone has no network queues the submission
 *    instead of failing, and a red banner says so.
 * 3. When the network comes back the queue is posted automatically.
 *
 * No inline script is used anywhere, so the Content-Security-Policy can
 * refuse inline JavaScript outright.
 */
(function () {
  'use strict';

  var DRAFT = 'tm_draft_';
  var QUEUE = 'tm_queue';
  var banner = document.getElementById('net');
  var baseText = banner ? banner.textContent : '';

  // ---------- small storage wrappers (private mode can throw) ----------
  function get(key) {
    try { return localStorage.getItem(key); } catch (e) { return null; }
  }
  function set(key, value) {
    try { localStorage.setItem(key, value); return true; } catch (e) { return false; }
  }
  function drop(key) {
    try { localStorage.removeItem(key); } catch (e) {}
  }
  function queue() {
    try { return JSON.parse(get(QUEUE) || '[]'); } catch (e) { return []; }
  }
  function saveQueue(list) {
    set(QUEUE, JSON.stringify(list));
  }

  // ---------- offline banner ----------
  function paintBanner() {
    if (!banner) return;
    var waiting = queue().length;
    if (navigator.onLine && waiting === 0) {
      banner.hidden = true;
      return;
    }
    banner.hidden = false;
    banner.textContent = waiting
      ? baseText + ' (' + waiting + ')'
      : baseText;
  }

  // ---------- which fields are worth remembering ----------
  function skip(field) {
    if (!field || !field.name) return true;
    if (field.name === 'csrf') return true;
    return ['hidden', 'password', 'submit', 'button', 'file'].indexOf(field.type) !== -1;
  }

  // ---------- drafts and queueing, per form ----------
  Array.prototype.forEach.call(document.querySelectorAll('form[data-offline]'), function (form) {
    var id = form.getAttribute('data-offline');
    var key = DRAFT + id;

    // restore
    try {
      var saved = JSON.parse(get(key) || '{}');
      Object.keys(saved).forEach(function (name) {
        var field = form.elements[name];
        if (!field || skip(field)) return;
        if (field.type === 'checkbox' || field.type === 'radio') return;
        if (!field.value) field.value = saved[name];
      });
    } catch (e) {}

    // keep saving while they type
    form.addEventListener('input', function () {
      var data = {};
      Array.prototype.forEach.call(form.elements, function (field) {
        if (!skip(field)) data[field.name] = field.value;
      });
      set(key, JSON.stringify(data));
    });

    form.addEventListener('submit', function (ev) {
      if (navigator.onLine) {
        // remember which draft to clear once the server confirms the save
        try { sessionStorage.setItem('tm_submitted', id); } catch (e) {}
        return;
      }
      ev.preventDefault();
      var body = new URLSearchParams(new FormData(form)).toString();
      var list = queue();
      list.push({
        id: id,
        url: form.getAttribute('action') || location.href,
        body: body,
        at: Date.now(),
        tries: 0
      });
      saveQueue(list);
      paintBanner();
      if (banner) banner.scrollIntoView({ block: 'nearest' });
    });
  });

  // ---------- clear the draft the server has just accepted ----------
  if (document.body.hasAttribute('data-clear')) {
    var done = null;
    try { done = sessionStorage.getItem('tm_submitted'); } catch (e) {}
    if (done) {
      drop(DRAFT + done);
      try { sessionStorage.removeItem('tm_submitted'); } catch (e) {}
    }
  }

  // ---------- send whatever is waiting ----------
  var sending = false;
  function sendQueue() {
    if (sending || !navigator.onLine) return;
    var list = queue();
    if (!list.length) return;
    sending = true;

    var item = list[0];
    fetch(item.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: item.body
    }).then(function (res) {
      var rest = queue();
      if (res.ok) {
        rest.shift();                 // sent: forget it and forget the draft
        drop(DRAFT + item.id);
        saveQueue(rest);
        sending = false;
        paintBanner();
        if (rest.length) { sendQueue(); } else { location.reload(); }
        return;
      }
      // 4xx/5xx: the entry stays so nothing is lost, but stop hammering
      if (rest.length) {
        rest[0].tries = (rest[0].tries || 0) + 1;
        if (rest[0].tries >= 5) { rest.shift(); }
        saveQueue(rest);
      }
      sending = false;
      paintBanner();
    }).catch(function () {
      sending = false;               // still offline, try again on 'online'
      paintBanner();
    });
  }

  window.addEventListener('online', function () { paintBanner(); sendQueue(); });
  window.addEventListener('offline', paintBanner);
  paintBanner();
  sendQueue();

  // ---------- two CSP-safe helpers ----------
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest ? ev.target.closest('[data-print]') : null;
    if (el) { ev.preventDefault(); window.print(); }
  });

  document.addEventListener('submit', function (ev) {
    var question = ev.target.getAttribute('data-confirm');
    if (question && !window.confirm(question)) { ev.preventDefault(); }
  });

  // Reschedule needs a date; show that field only when it is relevant.
  Array.prototype.forEach.call(document.querySelectorAll('[data-toggle-date]'), function (select) {
    var box = document.getElementById(select.getAttribute('data-toggle-date'));
    if (!box) return;
    function sync() { box.hidden = select.value !== 'reschedule'; }
    select.addEventListener('change', sync);
    sync();
  });

  // ---------- context-aware form helpers ----------

  // The current value of a named control (radio group or select) in a form.
  function valueOf(form, name) {
    var el = form && form.elements[name];
    if (!el) return '';
    if (el.length !== undefined && !el.tagName) {
      for (var i = 0; i < el.length; i++) { if (el[i].checked) return el[i].value; }
      return '';
    }
    return el.value;
  }

  // data-show-when="name=value" / data-hide-when="name=value": a field that
  // only matters for one choice appears with that choice. Without JavaScript
  // every field stays visible and the server still validates.
  Array.prototype.forEach.call(document.querySelectorAll('[data-show-when],[data-hide-when]'), function (box) {
    var show = box.hasAttribute('data-show-when');
    var rule = (box.getAttribute(show ? 'data-show-when' : 'data-hide-when') || '').split('=');
    var form = box.closest('form');
    if (!form || rule.length !== 2) return;
    function sync() {
      var match = valueOf(form, rule[0]) === rule[1];
      box.hidden = show ? !match : match;
    }
    form.addEventListener('change', sync);
    sync();
  });

  // data-filter-by="other": only offer the options whose data-group equals the
  // value chosen in the other select (e.g. doctors at the chosen centre).
  Array.prototype.forEach.call(document.querySelectorAll('select[data-filter-by]'), function (select) {
    var form = select.closest('form');
    var by = form && form.elements[select.getAttribute('data-filter-by')];
    if (!by) return;
    function sync() {
      Array.prototype.forEach.call(select.options, function (opt) {
        var group = opt.getAttribute('data-group');
        var off = group !== null && group !== by.value;
        opt.hidden = off;
        opt.disabled = off;
        if (off && opt.selected) select.value = select.options[0].value;
      });
    }
    by.addEventListener('change', sync);
    sync();
  });

  // data-reload-param="name": choosing a value reloads the page with it, so
  // the server can show context for that choice (the draft is kept).
  Array.prototype.forEach.call(document.querySelectorAll('select[data-reload-param]'), function (select) {
    select.addEventListener('change', function () {
      if (!select.value) return;
      var p = new URLSearchParams(location.search);
      p.set(select.getAttribute('data-reload-param'), select.value);
      location.search = p.toString();
    });
  });

  // Demo account buttons on the login page (development only).
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-fill-login]') : null;
    if (!b) return;
    var form = document.getElementById('login-form');
    if (!form) return;
    form.elements.login_id.value = b.getAttribute('data-fill-login');
    form.elements.password.value = b.getAttribute('data-fill-pw');
    form.submit();
  });

  // Quick-reply chips append a common phrase to a note.
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-append]') : null;
    if (!b) return;
    var field = document.getElementById(b.getAttribute('data-append'));
    if (!field) return;
    var text = b.getAttribute('data-text');
    if (field.value.indexOf(text) !== -1) return;
    field.value = (field.value ? field.value.replace(/\s+$/, '') + ' ' : '') + text;
    field.dispatchEvent(new Event('input', { bubbles: true }));
    field.focus();
  });
})();

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
})();

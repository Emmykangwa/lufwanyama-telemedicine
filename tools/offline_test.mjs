/**
 * tools/offline_test.mjs -- tests assets/js/app.js without a browser.
 *
 *   node tools/offline_test.mjs
 *
 * app.js is the interruption tolerance of NFR3: form drafts and a submission
 * queue in localStorage. Clicking through that by hand is slow and easy to get
 * wrong, so this harness runs the real file against stubs for the handful of
 * browser objects it touches, and asserts what it stored and sent.
 *
 * The browser-side checks that still have to be done by hand (DevTools
 * throttling, the visible banner, responsive layout) are in docs/TESTING.md.
 */

import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, '..', 'assets', 'js', 'app.js'), 'utf8');

let pass = 0;
let fail = 0;

function check(what, ok, detail = '') {
  if (ok) {
    pass++;
    console.log(`  ok    ${what}`);
  } else {
    fail++;
    console.log(`  FAIL  ${what}${detail ? ' -- ' + detail : ''}`);
  }
}

function section(title) {
  console.log('\n' + title);
}

// ---------------------------------------------------------------------
// Stubs: just enough of a browser for app.js, and nothing more.
// ---------------------------------------------------------------------
function makeStorage(initial = {}) {
  const map = new Map(Object.entries(initial));
  return {
    getItem: (k) => (map.has(k) ? map.get(k) : null),
    setItem: (k, v) => void map.set(k, String(v)),
    removeItem: (k) => void map.delete(k),
    get length() { return map.size; },
    key: (i) => [...map.keys()][i],
    _map: map,
    _keys: () => [...map.keys()],
  };
}

function makeField(name, type = 'text', value = '') {
  return { name, type, value };
}

function makeForm(id, fields, action = '/health/new_referral.php') {
  const listeners = {};
  const elements = fields.slice();
  for (const f of fields) {
    elements[f.name] = f;          // named access, as the DOM provides
  }
  return {
    elements,
    _fields: fields,
    getAttribute: (a) => (a === 'data-offline' ? id : a === 'action' ? action : null),
    addEventListener: (type, fn) => {
      (listeners[type] ||= []).push(fn);
    },
    dispatch(type, event) {
      for (const fn of listeners[type] || []) fn(event);
    },
  };
}

function makeSandbox({ online = true, local = {}, session = {}, clear = false, forms = [], fetchImpl } = {}) {
  const localStorage = makeStorage(local);
  const sessionStorage = makeStorage(session);
  const banner = { hidden: true, textContent: 'No connection. Your entry is saved.', scrollIntoView() {} };
  const docListeners = {};
  const winListeners = {};
  const reloads = { count: 0 };
  const sent = [];

  const sandbox = {
    console,
    URLSearchParams,
    URL,
    Date,
    JSON,
    Object,
    Array,
    window: {
      addEventListener: (t, fn) => void ((winListeners[t] ||= []).push(fn)),
      confirm: () => true,
      print: () => void (sandbox.__printed = true),
    },
    navigator: { onLine: online },
    localStorage,
    sessionStorage,
    location: { href: 'http://localhost/health/new_referral.php', reload: () => void reloads.count++ },
    document: {
      getElementById: (id) => (id === 'net' ? banner : null),
      querySelectorAll: (sel) => (sel === 'form[data-offline]' ? forms : []),
      body: { hasAttribute: (a) => (a === 'data-clear' ? clear : false) },
      addEventListener: (t, fn) => void ((docListeners[t] ||= []).push(fn)),
    },
    FormData: class {
      constructor(form) { this.form = form; }
      *[Symbol.iterator]() {
        for (const f of this.form._fields) {
          if (f.type !== 'submit' && f.type !== 'button') yield [f.name, f.value];
        }
      }
    },
    fetch: fetchImpl || ((url, opts) => {
      sent.push({ url, body: opts.body, credentials: opts.credentials });
      return Promise.resolve({ ok: true, status: 200 });
    }),
  };
  sandbox.window.window = sandbox.window;

  return { sandbox, localStorage, sessionStorage, banner, docListeners, winListeners, reloads, sent };
}

function run(ctx) {
  runInNewContext(source, ctx.sandbox);
  return ctx;
}

const tick = () => new Promise((r) => setImmediate(r));

console.log('Offline behaviour self-test (assets/js/app.js)\n=============================================');

// ---------------------------------------------------------------------
section('1. Drafts are saved while typing');
// ---------------------------------------------------------------------
{
  const fields = [
    makeField('csrf', 'hidden', 'tok'),
    makeField('patient_id', 'select-one', '3'),
    makeField('reason', 'text', 'Fever for five days'),
    makeField('clinical_notes', 'textarea', 'Temp 38.9'),
    makeField('password', 'password', 'secret'),
  ];
  const form = makeForm('referral', fields);
  const ctx = run(makeSandbox({ forms: [form] }));

  form.dispatch('input', {});
  const raw = ctx.localStorage.getItem('tm_draft_referral');
  check('a draft is written under tm_draft_<id>', raw !== null);

  const draft = JSON.parse(raw || '{}');
  check('a text field is kept', draft.reason === 'Fever for five days', JSON.stringify(draft));
  check('a textarea is kept', draft.clinical_notes === 'Temp 38.9');
  check('a select is kept', draft.patient_id === '3');
  check('the CSRF token is never stored', !('csrf' in draft));
  check('the password is never stored', !('password' in draft));
}

// ---------------------------------------------------------------------
section('2. A draft is restored after a reload');
// ---------------------------------------------------------------------
{
  const fields = [
    makeField('reason', 'text', ''),
    makeField('clinical_notes', 'textarea', ''),
    makeField('department', 'select-one', ''),
  ];
  const form = makeForm('referral', fields);
  run(makeSandbox({
    forms: [form],
    local: { tm_draft_referral: JSON.stringify({ reason: 'Swollen leg', clinical_notes: 'BP 150/95', department: 'Surgery' }) },
  }));

  check('an empty field is refilled from the draft', fields[0].value === 'Swollen leg', fields[0].value);
  check('the notes come back too', fields[1].value === 'BP 150/95');
  check('the select comes back too', fields[2].value === 'Surgery');
}

{
  const fields = [makeField('reason', 'text', 'Typed just now')];
  const form = makeForm('referral', fields);
  run(makeSandbox({ forms: [form], local: { tm_draft_referral: JSON.stringify({ reason: 'Older draft' }) } }));
  check('a field the user has already filled is not overwritten', fields[0].value === 'Typed just now', fields[0].value);
}

// ---------------------------------------------------------------------
section('3. Submitting while offline queues instead of failing');
// ---------------------------------------------------------------------
{
  const fields = [makeField('csrf', 'hidden', 'tok'), makeField('reason', 'text', 'Offline referral')];
  const form = makeForm('referral', fields);
  const ctx = run(makeSandbox({ online: false, forms: [form] }));

  check('the banner is visible while offline', ctx.banner.hidden === false);

  let prevented = false;
  form.dispatch('submit', { preventDefault: () => void (prevented = true) });

  check('the submission is intercepted', prevented);
  const queue = JSON.parse(ctx.localStorage.getItem('tm_queue') || '[]');
  check('one entry is queued', queue.length === 1, JSON.stringify(queue));
  check('the queued entry keeps the form action', queue[0] && queue[0].url === '/health/new_referral.php');
  check('the queued body carries the field', (queue[0]?.body || '').includes('reason=Offline+referral'), queue[0]?.body);
  check('the queued body carries the CSRF token', (queue[0]?.body || '').includes('csrf=tok'));
  check('the banner shows how many are waiting', ctx.banner.textContent.includes('(1)'), ctx.banner.textContent);

  form.dispatch('submit', { preventDefault: () => {} });
  const two = JSON.parse(ctx.localStorage.getItem('tm_queue') || '[]');
  check('a second entry is added rather than replacing the first', two.length === 2, String(two.length));
  check('the banner count follows', ctx.banner.textContent.includes('(2)'));
}

// ---------------------------------------------------------------------
section('4. The queue is sent when the network returns');
// ---------------------------------------------------------------------
{
  const queued = [
    { id: 'referral', url: '/health/new_referral.php', body: 'reason=one', at: 1, tries: 0 },
    { id: 'add-patient', url: '/health/add_patient.php', body: 'full_name=two', at: 2, tries: 0 },
  ];
  const ctx = run(makeSandbox({ online: false, local: { tm_queue: JSON.stringify(queued) } }));

  check('nothing is sent while offline', ctx.sent.length === 0);

  ctx.sandbox.navigator.onLine = true;
  for (const fn of ctx.winListeners.online || []) fn();
  await tick(); await tick(); await tick();

  check('both entries were posted', ctx.sent.length === 2, JSON.stringify(ctx.sent.map((s) => s.url)));
  check('they went in the order they were queued', ctx.sent[0]?.url === '/health/new_referral.php');
  check('the session cookie is sent with them', ctx.sent[0]?.credentials === 'same-origin');
  check('the queue is emptied', JSON.parse(ctx.localStorage.getItem('tm_queue') || '[]').length === 0);
  check('the page is reloaded so the user sees the result', ctx.reloads.count === 1, String(ctx.reloads.count));
  check('the banner is hidden again', ctx.banner.hidden === true);
}

// ---------------------------------------------------------------------
section('5. A rejected entry is kept, not silently lost');
// ---------------------------------------------------------------------
{
  const ctx = run(makeSandbox({
    online: true,
    local: { tm_queue: JSON.stringify([{ id: 'referral', url: '/x', body: 'a=1', at: 1, tries: 0 }]) },
    fetchImpl: () => Promise.resolve({ ok: false, status: 400 }),
  }));
  await tick(); await tick();

  const q = JSON.parse(ctx.localStorage.getItem('tm_queue') || '[]');
  check('the entry is still in the queue after a 400', q.length === 1, JSON.stringify(q));
  check('the failure is counted', q[0]?.tries === 1, String(q[0]?.tries));
  check('the page is not reloaded on failure', ctx.reloads.count === 0);
}

{
  const ctx = run(makeSandbox({
    online: true,
    local: { tm_queue: JSON.stringify([{ id: 'referral', url: '/x', body: 'a=1', at: 1, tries: 4 }]) },
    fetchImpl: () => Promise.resolve({ ok: false, status: 400 }),
  }));
  await tick(); await tick();
  const q = JSON.parse(ctx.localStorage.getItem('tm_queue') || '[]');
  check('an entry is given up after five tries', q.length === 0, JSON.stringify(q));
}

{
  const ctx = run(makeSandbox({
    online: true,
    local: { tm_queue: JSON.stringify([{ id: 'referral', url: '/x', body: 'a=1', at: 1, tries: 0 }]) },
    fetchImpl: () => Promise.reject(new Error('network down')),
  }));
  await tick(); await tick();
  const q = JSON.parse(ctx.localStorage.getItem('tm_queue') || '[]');
  check('a dropped connection leaves the entry untouched for the next try', q.length === 1 && q[0].tries === 0);
}

// ---------------------------------------------------------------------
section('6. A saved entry clears only its own draft');
// ---------------------------------------------------------------------
{
  const ctx = run(makeSandbox({
    clear: true,
    session: { tm_submitted: 'referral' },
    local: {
      tm_draft_referral: JSON.stringify({ reason: 'saved on the server' }),
      'tm_draft_add-patient': JSON.stringify({ full_name: 'still being typed' }),
    },
  }));

  check('the draft just accepted is removed', ctx.localStorage.getItem('tm_draft_referral') === null);
  check('an unrelated draft is left alone', ctx.localStorage.getItem('tm_draft_add-patient') !== null);
  check('the marker is cleared so it does not fire twice', ctx.sessionStorage.getItem('tm_submitted') === null);
}

{
  const fields = [makeField('reason', 'text', 'x')];
  const form = makeForm('referral', fields);
  const ctx = run(makeSandbox({ online: true, forms: [form] }));
  form.dispatch('submit', { preventDefault: () => {} });
  check('an online submit records which draft to clear', ctx.sessionStorage.getItem('tm_submitted') === 'referral');
  check('an online submit is not queued', ctx.localStorage.getItem('tm_queue') === null);
}

// ---------------------------------------------------------------------
section('7. Storage being unavailable must not break the page');
// ---------------------------------------------------------------------
{
  const fields = [makeField('reason', 'text', 'private window')];
  const form = makeForm('referral', fields);
  const ctx = makeSandbox({ forms: [form] });
  const boom = () => { throw new Error('storage disabled'); };
  ctx.sandbox.localStorage = { getItem: boom, setItem: boom, removeItem: boom };
  ctx.sandbox.sessionStorage = { getItem: boom, setItem: boom, removeItem: boom };

  let threw = null;
  try {
    runInNewContext(source, ctx.sandbox);
    form.dispatch('input', {});
    form.dispatch('submit', { preventDefault: () => {} });
  } catch (e) {
    threw = e;
  }
  check('app.js survives localStorage throwing on every call', threw === null, String(threw));
}

// ---------------------------------------------------------------------
console.log('\n=============================================');
console.log(`${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);

#!/usr/bin/env node
/**
 * The mock's two auth states, verified as RENDERED, in a real headless
 * browser. Covers the session-state machinery in design/preview.html:
 * setAuth()'s three header controls + footer links, the GATED auto-gate in
 * go(), both sign-out controls, reload reset, and the whole cycle at 420px.
 *
 *     node scripts/mock-state-pass.mjs                 # committed design copy
 *     node scripts/mock-state-pass.mjs --page preview.html   # local root copy
 *
 * WHY THIS EXISTS — the first run of this pass caught a shipped defect that
 * four other instruments (contract suite, route audit, overflow sweep, and a
 * hand walk of the flag) had all passed: the hidden attribute is only a
 * UA-stylesheet display:none, and author rules beat UA rules at equal
 * specificity, so .btn{display:inline-flex} silently defeated hidden on the
 * session controls and the header rendered all three auth states at once.
 * The flag and the attribute toggled exactly as designed; only the render
 * lied. Two rules follow, and this file keeps both:
 *
 *   1. Assert getComputedStyle().display, never el.hidden — attribute state
 *      is not rendered state.
 *   2. Anchor controls by ID, never by text scan — a first-match find() in
 *      the original probe nearly misattributed the very defect it had found.
 *
 * INSTRUMENT SELF-CHECK — the IDE browser pane reports innerWidth: 0 for
 * file:// pages, so any geometry read there is zero and every derived
 * assertion is void, silently (Main, 11 Aug 2026). This script asserts the
 * viewport it asked for is the viewport it got before trusting a single
 * reading; if that ever fails, the environment is the defect.
 *
 * The default target is design/preview.html — committed and keyless, so it
 * exists on a fresh CI checkout. The root copy is generated and gitignored
 * (it carries the API key); point --page at it locally when you want to
 * check the shippable artifact itself. No check here touches the key.
 */
import { spawn, spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const args = process.argv.slice(2);
function argOf(flag, dflt) {
  const i = args.indexOf(flag);
  return i >= 0 && args[i + 1] ? args[i + 1] : dflt;
}
const PORT = Number(argOf('--port', '9346'));

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const pageArg = argOf('--page', join(repoRoot, 'design', 'preview.html'));
const PAGE = /^[a-z]+:\/\//.test(pageArg) ? pageArg : pathToFileURL(resolve(pageArg)).href;

/* Browser resolution is this script's job, not the caller's — the wrapper
   (live-checks.sh) invokes us with no path, on Windows dev boxes AND ubuntu
   CI runners, and a hardcoded default fails on whichever it wasn't written
   on. Order: explicit flag, UI_BROWSER env, the Windows Edge install, then
   whatever Chromium the PATH offers. The --edge flag name is historical;
   any Chromium-family binary works. */
function findBrowser() {
  const cli = argOf('--edge', null);
  if (cli) return cli;
  if (process.env.UI_BROWSER) return process.env.UI_BROWSER;
  const winEdge = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
  if (existsSync(winEdge)) return winEdge;
  for (const name of ['google-chrome', 'google-chrome-stable', 'chromium-browser', 'chromium']) {
    const r = spawnSync('which', [name], { encoding: 'utf8' });
    if (r.status === 0 && r.stdout.trim()) return r.stdout.trim();
  }
  throw new Error('no Chromium-family browser found — pass --edge <path> or set UI_BROWSER');
}
const EDGE = findBrowser();

// --no-sandbox and --disable-dev-shm-usage are for CI, and both are required
// there rather than nice to have. GitHub's runners restrict the user namespaces
// Chrome's sandbox needs, so without the first flag the process starts and dies
// immediately; /dev/shm is 64 MB in most containers, which the second flag
// routes around. Locally they change nothing — this harness renders one static
// file it already trusts, so the sandbox is not protecting anything here.
//
// stderr is captured rather than discarded. It used to be `stdio: 'ignore'`,
// and when this failed in CI the entire diagnosis available was "browser did
// not open its debugging port" — true, useless, and indistinguishable between
// "no browser installed", "browser crashed" and "port already in use". Chrome
// prints the actual reason; there is no reason to throw it away.
const launchArgs = ['--headless=new', '--disable-gpu', '--no-sandbox',
  '--disable-dev-shm-usage', `--remote-debugging-port=${PORT}`,
  '--no-first-run', '--allow-file-access-from-files',
  '--user-data-dir=' + join(tmpdir(), 'edge-mock-state'), 'about:blank'];

const edge = spawn(EDGE, launchArgs, { stdio: ['ignore', 'ignore', 'pipe'] });

let browserErr = '';
edge.stderr.on('data', (b) => { browserErr += b.toString(); });
edge.on('error', (e) => { browserErr += `spawn failed: ${e.message}\n`; });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let ws, msgId = 0;
const pending = new Map();
const events = [];
function send(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++msgId;
    pending.set(id, { resolve, reject });
    ws.send(JSON.stringify({ id, method, params }));
  });
}
function connect(url) {
  return new Promise((resolve, reject) => {
    ws = new WebSocket(url);
    ws.onopen = () => resolve();
    ws.onerror = reject;
    ws.onmessage = (m) => {
      const msg = JSON.parse(m.data);
      if (msg.id && pending.has(msg.id)) {
        const p = pending.get(msg.id);
        pending.delete(msg.id);
        msg.error ? p.reject(new Error(JSON.stringify(msg.error))) : p.resolve(msg.result);
      } else if (msg.method) events.push(msg);
    };
  });
}
async function json(path, method = 'GET') {
  const res = await fetch(`http://127.0.0.1:${PORT}${path}`, { method });
  return res.json();
}
async function evalIn(expr) {
  const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails));
  return r.result.value;
}
async function navigate(url) {
  events.length = 0;
  await send('Page.navigate', { url });
  for (let i = 0; i < 60; i++) {
    if (events.some((e) => e.method === 'Page.loadEventFired')) break;
    await sleep(150);
  }
  await sleep(400);
}
/* The self-check: the viewport we asked for is the viewport we got. A pane
   or misconfigured target reads innerWidth 0 and every assertion after that
   would be void while looking like data. */
async function setViewport(width, height, mobile) {
  await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
  await sleep(150);
  const got = await evalIn('({ w: window.innerWidth, h: window.innerHeight })');
  if (got.w !== width) {
    throw new Error(`viewport self-check failed: asked ${width}px, page reports ${got.w}px — ` +
      'a zero or wrong reading means the environment (pane, emulation) is the defect, not the mock');
  }
}

// Installed once per load. Reads the three header controls and the footer
// auth links by ID/selector, as RENDERED (computed display + offsetParent),
// alongside the flag and the active screen.
const INSTALL = `window.__hdr = function () {
  var shown = function (el) {
    return !!el && getComputedStyle(el).display !== 'none' && el.offsetParent !== null;
  };
  var row = document.querySelector('.ftr p');
  return {
    flag: signedIn,
    authin: shown(document.getElementById('authin')),
    authacct: shown(document.getElementById('authacct')),
    authout: shown(document.getElementById('authout')),
    /* The footer row is the preview's screen index, not product chrome, so it
       does NOT track the session — see the note at setAuth(). Session state
       used to hide two of its four entries, which orphaned their separators
       ("· · Reset password · 404"). Asserted as text, because counting the
       links would have called that footer correct. */
    ftrLinks: Array.from(document.querySelectorAll('.ftr__link')).filter(shown).length,
    ftrText: row ? row.innerText.trim() : '',
    onScreen: document.querySelector('.screen.on').id,
  };
}; true`;
/* Orphaned or doubled separators: a leading "·", or two with only space between. */
const ftrIntact = (h) => h.ftrLinks === 4 && !/^\s*·/.test(h.ftrText) && !/·\s*·/.test(h.ftrText);

// Signed out: Sign in shown, account + Sign out gone, footer auth links shown.
// Signed in: the inverse. Anything else is a header lying about the session.
const consistent = (h) => (h.flag
  ? (!h.authin && h.authacct && h.authout)
  : (h.authin && !h.authacct && !h.authout)) && ftrIntact(h);

const results = [];
function check(name, pass, detail) {
  results.push({ name, pass });
  console.log((pass ? 'ok      ' : 'NOT OK  ') + name + (pass ? '' : '\n          ' + JSON.stringify(detail)));
}

try {
  let ver = null;
  for (let i = 0; i < 40 && !ver; i++) { try { ver = await json('/json/version'); } catch { await sleep(250); } }
  if (!ver) {
    // Say which browser, and what it printed on the way down. "Did not open its
    // debugging port" alone sent one CI failure to a dead end: it is equally
    // consistent with no browser installed, a browser that crashed on launch,
    // and a port already in use, and it names none of them.
    const why = browserErr.trim() || '(the browser printed nothing at all — it may not have started)';
    throw new Error(
      `browser did not open its debugging port on ${PORT}\n` +
      `  browser: ${EDGE}\n` +
      `  exited:  ${edge.exitCode === null ? 'still running' : `code ${edge.exitCode}`}\n` +
      `  stderr:  ${why.split('\n').slice(0, 6).join('\n           ')}`
    );
  }
  const target = await json('/json/new?about:blank', 'PUT');
  await connect(target.webSocketDebuggerUrl);
  await send('Runtime.enable');
  await send('Page.enable');
  await navigate(PAGE);
  await setViewport(1400, 900, false);
  await evalIn(INSTALL);

  check('zero JS exceptions on load',
    events.filter((e) => e.method === 'Runtime.exceptionThrown').length === 0, null);

  /* 1. Fresh load: signed out, header honest (this is the check that caught
        the [hidden] defeat — three auth states rendered at once). */
  let h = await evalIn('__hdr()');
  check('fresh load: signed out, only Sign in rendered', h.flag === false && consistent(h), h);

  /* 2. Happy path: the login screen's own submit. */
  h = await evalIn(`(() => { go('login'); document.getElementById('li-submit').click(); return __hdr(); })()`);
  check('login submit: dashboard, flag true, header flips', h.onScreen === 's-dashboard' && h.flag === true && consistent(h), h);

  /* 3. Header sign out. */
  h = await evalIn(`(() => { document.getElementById('authout').click(); return __hdr(); })()`);
  check('header Sign out: home, flag false, header restores', h.onScreen === 's-home' && h.flag === false && consistent(h), h);

  /* 4. Deep entry to a gated screen gates (the GATED literal in go()). */
  h = await evalIn(`(() => { go('dashboard'); return __hdr(); })()`);
  check('deep entry to dashboard: auto-gated, header consistent', h.onScreen === 's-dashboard' && h.flag === true && consistent(h), h);

  /* 5. Sign out, then deep-link the profile (the other GATED member). */
  h = await evalIn(`(() => { document.getElementById('authout').click(); go('profile'); return __hdr(); })()`);
  check('deep entry to profile: auto-gated, header consistent', h.onScreen === 's-profile' && h.flag === true && consistent(h), h);

  /* 6. The profile's own sign-out control. */
  h = await evalIn(`(() => { document.getElementById('pf-signout').click(); return __hdr(); })()`);
  check('profile Sign out: home, cleared', h.onScreen === 's-home' && h.flag === false && consistent(h), h);

  /* 7. Register path signs in too. */
  h = await evalIn(`(() => { go('register'); document.getElementById('reg-submit').click(); return __hdr(); })()`);
  check('register submit: signed in, header flips', h.flag === true && consistent(h), h);

  /* 8. No persistence: a reload must land signed out — the stuck-state guard. */
  await navigate(PAGE + '?reload=1');
  await evalIn(INSTALL);
  h = await evalIn('__hdr()');
  check('reload: signed out again (no persistence)', h.flag === false && consistent(h), h);

  /* 9. Storage audit: nothing auth-shaped was written. */
  const store = await evalIn(`Object.keys(localStorage)`);
  check('localStorage holds no auth state', !store.some((k) => /auth|sign|session|logged/i.test(k)), store);

  /* 10. Both states at 420: same consistency contract, mobile layout. */
  await setViewport(420, 900, true);
  const m1 = await evalIn('__hdr()');
  check('420px signed out: only Sign in rendered', m1.flag === false && consistent(m1), m1);
  const m2 = await evalIn(`(() => { go('dashboard'); return __hdr(); })()`);
  check('420px gate: flag flips, header consistent, Sign out reachable', m2.flag === true && consistent(m2), m2);
  const m3 = await evalIn(`(() => { document.getElementById('authout').click(); return __hdr(); })()`);
  check('420px sign out: cleared, header restores', m3.flag === false && consistent(m3), m3);

  const failed = results.filter((r) => !r.pass).length;
  console.log(failed ? `\n${failed} of ${results.length} failed` : `\nall ${results.length} state-pass checks pass`);
  process.exitCode = failed ? 1 : 0;
} catch (e) {
  console.error('FAILED: ' + e.message);
  process.exitCode = 1;
} finally {
  try { ws && ws.close(); } catch {}
  edge.kill();
}

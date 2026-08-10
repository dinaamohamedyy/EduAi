#!/usr/bin/env node
/**
 * Signed-in UI geometry checks against the running site, in a real headless
 * browser. The only instrument on this project that can see the states where
 * the layout bugs actually lived.
 *
 *     node scripts/ui-geometry.mjs                # run every check
 *     node scripts/ui-geometry.mjs --url http://localhost:8080
 *
 * WHY THIS EXISTS — do not measure scrolled geometry in the IDE browser pane:
 * `scrollTo` SILENTLY NO-OPS while the pane is hidden (no compositing), so
 * every scrolled reading comes back unscrolled wearing a scrolled label.
 * Three sessions produced confident, wrong findings that way — a near-false
 * pass on the prep report, a "doesn't reproduce" on a bug that reproduces,
 * and a 46px "gap" that never existed. This harness therefore asserts its own
 * instrument before it trusts a single number: the scroll actually moved
 * (window.scrollY), and the stylesheet under test actually loaded (?ver=).
 *
 * Prerequisites (see docs/05): the Docker stack up on :8080, and wp-cli
 * reachable via `docker compose --profile tools run cli` — the auth cookie is
 * minted fresh through wp-cli each run (user id 1), so nothing here types or
 * stores a password. Edge is used because every Windows box ships it.
 *
 * Checks, all signed in:
 *   1. sticky header vs admin bar — desktop (fixed 32px) and mobile
 *      (absolute 46px, scrolls away), at top AND scrolled
 *   2. nav row integrity above the 900px toggle — one row, no internal
 *      label wrap (the min-content trap), clear of brand and actions
 *   3. mobile nav panel rides the header (backdrop-filter containing block)
 */
import { spawn, spawnSync } from 'node:child_process';

const args = process.argv.slice(2);
function argOf(flag, dflt) {
  const i = args.indexOf(flag);
  return i >= 0 && args[i + 1] ? args[i + 1] : dflt;
}
const BASE = argOf('--url', 'http://localhost:8080').replace(/\/$/, '');
const EDGE = argOf('--edge', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe');
const PORT = Number(argOf('--port', '9345'));

/* ---------------------------------------------------------------- cookie -- */
function mintCookie() {
  const php = 'echo "wordpress_logged_in_" . COOKIEHASH . "|" . ' +
    'wp_generate_auth_cookie( 1, time() + 3600, "logged_in" ) . "\\n";';
  const run = spawnSync('docker', [
    'compose', '--profile', 'tools', 'run', '--rm', 'cli',
    'wp', 'eval', php, '--allow-root', '--path=/var/www/html',
  ], { encoding: 'utf8', env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
  const line = (run.stdout || '').trim().split('\n').pop() || '';
  const cut = line.indexOf('|');
  if (cut < 0) {
    throw new Error('could not mint a logged_in cookie via wp-cli: ' +
      (run.stderr || run.stdout || 'no output').slice(0, 300));
  }
  return { name: line.slice(0, cut), value: line.slice(cut + 1) };
}

/* ------------------------------------------------------------------- CDP -- */
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
async function setWidth(w, h = 800) {
  await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 783 });
  await sleep(80);
}
async function navigate(url) {
  events.length = 0;
  await send('Page.navigate', { url });
  for (let i = 0; i < 80; i++) {
    if (events.some((e) => e.method === 'Page.loadEventFired')) break;
    await sleep(150);
  }
  await sleep(300);
}

/* The instrument self-check rides inside the scrolled probe: a reading is
   only produced if the scroll it claims actually happened. */
const SCROLLED_PROBE = `(async () => {
  const raf = () => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
  window.scrollTo({ top: 600, behavior: 'instant' });
  await raf(); await new Promise(r => setTimeout(r, 120)); await raf();
  if (window.scrollY < 100) return { instrumentFailure: 'scroll did not move (scrollY=' + window.scrollY + ')' };
  const hdr = document.querySelector('.site-header');
  const bar = document.getElementById('wpadminbar');
  const out = {
    scrollY: window.scrollY,
    headerTop: Math.round(hdr.getBoundingClientRect().top),
    barVisible: bar ? bar.getBoundingClientRect().bottom > 0 : null,
    barHeightVar: getComputedStyle(document.body).getPropertyValue('--wp-admin--admin-bar--height').trim(),
  };
  window.scrollTo({ top: 0, behavior: 'instant' });
  await raf();
  return out;
})()`;

const results = [];
function check(name, pass, detail) {
  results.push({ name, pass, detail });
  console.log((pass ? 'ok      ' : 'NOT OK  ') + name + (pass ? '' : '\n          ' + detail));
}

/* ------------------------------------------------------------------ main -- */
const cookie = mintCookie();
const edge = spawn(EDGE, [
  '--headless=new', '--disable-gpu', `--remote-debugging-port=${PORT}`,
  '--no-first-run', '--user-data-dir=' + process.env.TEMP + '\\edge-ui-geometry',
  'about:blank',
], { stdio: 'ignore' });

try {
  let ver = null;
  for (let i = 0; i < 40 && !ver; i++) {
    try { ver = await json('/json/version'); } catch { await sleep(250); }
  }
  if (!ver) throw new Error('Edge debugger never came up on port ' + PORT);

  const target = await json('/json/new?about:blank', 'PUT');
  await connect(target.webSocketDebuggerUrl);
  await send('Network.enable');
  await send('Network.setCookie', {
    name: cookie.name, value: cookie.value,
    domain: new URL(BASE).hostname, path: '/',
  });
  await send('Page.enable');

  await setWidth(1024);
  await navigate(BASE + '/?uigeom=1');

  const signedIn = await evalIn(`document.body.classList.contains('admin-bar')`);
  check('signed-in session established (body.admin-bar present)', signedIn === true,
    'cookie did not authenticate — is user id 1 present?');
  if (!signedIn) throw new Error('cannot measure signed-in geometry while signed out');

  const cssHref = await evalIn(`(document.querySelector('link[href*="main.css"]')||{}).href || ''`);
  check('theme stylesheet is version-tagged (cache-bust visible)', /\?ver=/.test(cssHref), cssHref);

  /* 1a. desktop regime */
  const d = await evalIn(SCROLLED_PROBE);
  if (d.instrumentFailure) throw new Error('instrument: ' + d.instrumentFailure);
  check('desktop scrolled: header pins at bar height (32), bar visible',
    d.headerTop === 32 && d.barVisible === true,
    JSON.stringify(d));

  /* 1b. mobile regime */
  await setWidth(420);
  await navigate(BASE + '/?uigeom=2');
  const m = await evalIn(SCROLLED_PROBE);
  if (m.instrumentFailure) throw new Error('instrument: ' + m.instrumentFailure);
  check('mobile scrolled: header pins at true top (0), absolute bar gone',
    m.headerTop === 0 && m.barVisible === false,
    JSON.stringify(m));

  /* 2. nav row integrity above the toggle */
  await setWidth(1024);
  await navigate(BASE + '/?uigeom=3');
  for (const w of [905, 1000, 1200]) {
    await setWidth(w);
    const p = await evalIn(`(() => {
      const links = Array.from(document.querySelectorAll('#primary-nav a'));
      const tops = links.map(a => Math.round(a.getBoundingClientRect().top));
      const hs = links.map(a => Math.round(a.getBoundingClientRect().height));
      const nav = document.getElementById('primary-nav').getBoundingClientRect();
      const brand = document.querySelector('.brand').getBoundingClientRect();
      const actions = document.querySelector('.header-actions').getBoundingClientRect();
      return { spread: Math.max(...tops) - Math.min(...tops),
        tallest: Math.max(...hs),
        clearBrand: Math.round(nav.left - brand.right),
        clearActions: Math.round(actions.left - nav.right) };
    })()`);
    check(`nav at ${w}px: one row, no internal wrap, clearances ≥4px`,
      p.spread <= 4 && p.tallest < 60 && p.clearBrand >= 4 && p.clearActions >= 4,
      JSON.stringify(p));
  }

  /* 3. mobile nav panel rides the header */
  await setWidth(420);
  await navigate(BASE + '/?uigeom=4');
  const nv = await evalIn(`(() => {
    document.querySelector('.nav-toggle').click();
    const nav = document.getElementById('primary-nav').getBoundingClientRect();
    const hdr = document.querySelector('.site-header').getBoundingClientRect();
    document.querySelector('.nav-toggle').click();
    return { navTop: Math.round(nav.top), headerBottom: Math.round(hdr.bottom) };
  })()`);
  check('mobile nav panel anchored to header (containing block intact)',
    Math.abs(nv.navTop - nv.headerBottom) <= 2, JSON.stringify(nv));

  const failed = results.filter((r) => !r.pass).length;
  console.log(failed ? `\n${failed} of ${results.length} checks failed` : `\nall ${results.length} geometry checks pass`);
  process.exitCode = failed ? 1 : 0;
} catch (e) {
  console.error('FAILED: ' + e.message);
  process.exitCode = 1;
} finally {
  try { ws && ws.close(); } catch {}
  edge.kill();
}

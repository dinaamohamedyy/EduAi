/**
 * Can the product be SEEN? — the visual harness.
 *
 *   1. Sign in as an administrator (fixture: `ui-admin`, see docs/05).
 *   2. Load a front-end page.
 *   3. Paste this whole file into the browser console.
 *
 * Two checks, and they exist because of the same gap found twice in one day:
 *
 *   adminBar()  — is the site header occluded by the WordPress admin bar?
 *   tools()     — do the four tools actually render their own styling, or are
 *                 their surfaces invisible because a token never resolved?
 *
 * Why this exists. Every other harness in scripts/ runs as a student or logged
 * out, and every one of them asserts on *behaviour*. Both of today's bugs were
 * invisible to all seven: the admin bar only exists when signed in as someone
 * with rights, and a transparent button still clicks, still posts, still
 * returns the right answer. Seven live harnesses and fifteen contract checks
 * were green while the owner's homepage was visibly broken and three of the
 * four tools had unstyled primary buttons.
 *
 * "The right user" had only ever been a question about authorisation, never
 * about layout — and "does it work" had never included "can you see it".
 *
 * NOT A REPLACEMENT FOR scripts/ui-geometry.mjs, and it does not replace this
 * one either. That harness drives headless Edge, mints its own cookie through
 * wp-cli, and asserts its own instrument (that the scroll moved, that the
 * stylesheet under test loaded) — so it runs unattended and can measure
 * scrolled states, which the IDE browser pane cannot. This file runs in
 * whatever browser a human already has open, on whatever page they are
 * looking at, with no setup. Use that one in a gate; use this one when
 * something looks wrong and you want an answer in ten seconds.
 *
 * FULL COVERAGE NEEDS EIGHT RUNS: the four tool pages (/ask/, /summarise/,
 * /calc/, /prepare/) under the real theme and again under a stock one. The
 * stock-theme pass is not redundant — it is the only thing that exercises the
 * `var(--token, fallback)` defaults rather than the theme's real values, and
 * a mistyped fallback fails silently in exactly that configuration and no
 * other.
 *
 * WHY IT DOES NOT SCROLL. A sticky header pins to its `top` in viewport
 * coordinates the moment the page scrolls, and the admin bar is fixed at 0..h
 * with a far higher z-index. So occlusion is decidable *at rest*: if the
 * header's sticky `top` is less than the bar's height, the bar will cover it.
 * That matters more than elegance here — the in-app browser pane silently
 * no-ops `scrollTo` on live-site tabs, so a scroll-and-measure check reports
 * unscrolled numbers wearing a scrolled label. Two sessions were misled by
 * exactly that on this bug, in opposite directions. If you ever add a scrolled
 * reading, assert `window.scrollY` moved before believing it.
 *
 * The instrument checks are deliberately behavioural, not a version string.
 * An assertion that `main.css?ver=` matches some literal rots the first time
 * anyone bumps the version — it was already stale between being suggested and
 * being written. Instead they assert the *consequences* of the fix being
 * loaded, which a cached pre-fix stylesheet cannot fake.
 */
(() => {
  const pass = [], fail = [];
  /* The detail string is the *diagnosis of a failure*, so it is attached only
   * when the assertion fails. Appending it to a pass produces lines like
   * "ok — not signed in as an admin", which reads as a problem and is how a
   * green run gets misread as a red one. */
  const assert = (ok, name, detail) =>
    ok ? pass.push(name) : fail.push(detail ? `${name} — ${detail}` : name);

  const bar = document.getElementById('wpadminbar');
  const hdr = document.querySelector('.site-header');

  /* ---- instrument: prove the harness before believing the verdict ------- */

  assert(!!bar, 'instrument: admin bar present',
    'not signed in as someone with an admin bar — every check below would pass vacuously');
  assert(!!hdr, 'instrument: site header found');
  if (!bar || !hdr) return { pass, fail };

  const htmlMargin = getComputedStyle(document.documentElement).marginTop;
  assert(htmlMargin === '0px', 'instrument: the fix is the stylesheet actually loaded',
    `html margin-top is ${htmlMargin}; core's own bump is still applied, so this is a pre-fix sheet`);

  const spacer = getComputedStyle(document.body, '::before');
  const spacerH = parseFloat(spacer.height) || 0;
  assert(spacerH > 0, 'instrument: the theme spacer is rendering',
    `body.admin-bar::before is ${spacerH}px`);

  const barCS = getComputedStyle(bar);
  const hdrCS = getComputedStyle(hdr);
  const barH  = Math.round(bar.getBoundingClientRect().height);
  const barFixed  = barCS.position === 'fixed';
  const hdrSticky = ['sticky', 'fixed'].includes(hdrCS.position);
  const hdrTop = parseFloat(hdrCS.top) || 0;
  const barZ = parseInt(barCS.zIndex, 10) || 0;
  const hdrZ = parseInt(hdrCS.zIndex, 10) || 0;

  assert(hdrSticky, 'instrument: header is sticky, so the invariant applies',
    `position is ${hdrCS.position}`);

  /* ---- the invariant --------------------------------------------------- */

  if (!barFixed) {
    /* Below core's 782px breakpoint the bar is position:absolute and scrolls
     * away with the document, so a header pinned at top:0 is correct and the
     * occlusion question does not arise. Reporting this rather than silently
     * passing: a check that quietly does not apply looks exactly like one that
     * applied and found nothing. */
    pass.push(`invariant does not apply at ${innerWidth}px — the bar is ${barCS.position} and scrolls away (header top:${hdrTop}, bar ${barH}px)`);
  } else {
    assert(!(hdrSticky && barZ > hdrZ && hdrTop < barH),
      'signed in as admin, the sticky header clears the admin bar',
      `header top:${hdrTop}px vs bar height:${barH}px (bar z:${barZ} > header z:${hdrZ}) — covered by ${barH - hdrTop}px once scrolled`);
  }

  /* Both offsets should derive from core's own variable rather than a literal,
   * so they track core's 32→46 flip without a second number to drift. */
  const coreVar = getComputedStyle(document.documentElement)
    .getPropertyValue('--wp-admin--admin-bar--height').trim();
  assert(coreVar !== '', 'core exposes its own admin-bar height variable',
    'absent — any offset here is a magic number that will drift');
  assert(parseFloat(coreVar) === barH, 'the variable matches the rendered bar',
    `variable ${coreVar} vs rendered ${barH}px`);

  /* ---- can the tool on this page be seen? ------------------------------ */

  /* A tool whose tokens never resolved still works perfectly: the button
   * clicks, the request posts, the answer comes back. It is simply invisible —
   * `background: hsl(var(--eduai-brand))` with an unresolved alias computes to
   * `rgba(0, 0, 0, 0)`, so a primary call-to-action renders as bare text.
   *
   * Text keeps looking right, because `color` inherits from the theme's body
   * rather than from the plugin's aliases. That is why this survives a
   * screenshot review: layout, type and spacing are all correct and only the
   * filled surfaces are missing. Assert the computed value; do not look. */
  const tool = document.querySelector('.eduai-app, .eduai-calc, .eduai-sum, .eduai-prep, .eduai-card');

  if (tool) {
    const alias = getComputedStyle(tool).getPropertyValue('--eduai-brand').trim();
    assert(alias !== '', 'the tool\'s token aliases resolve',
      `--eduai-brand is empty on .${tool.className.split(/\s+/)[0]} — the alias block is scoped to selectors this root does not carry, so every rule built on it is invalid`);

    const primary = tool.querySelector('.eduai-btn--primary');
    if (primary) {
      const bg = getComputedStyle(primary).backgroundColor;
      const transparent = bg === 'transparent' || /rgba\([^)]*,\s*0\)$/.test(bg);
      assert(!transparent, 'the primary button has a visible background',
        `background-color is ${bg} — the button renders as plain text`);
    } else {
      pass.push('no primary button on this page to check');
    }
  } else {
    pass.push('no tool root on this page — run this on /ask/, /summarise/, /calc/ or /prepare/ for the tool checks');
  }

  console.table({ width: innerWidth, barPosition: barCS.position, barHeight: barH, headerTop: hdrTop, coreVar });
  pass.forEach(p => console.log('ok   ', p));
  fail.forEach(f => console.error('FAIL ', f));
  console.log(`\n${pass.length} passed, ${fail.length} failed`);
  return { pass, fail };
})();

/**
 * Is the site header occluded by the WordPress admin bar?
 *
 *   1. Sign in as an administrator (fixture: `ui-admin`, see docs/05).
 *   2. Load any front-end page.
 *   3. Paste this whole file into the browser console.
 *
 * Why this exists. Every other harness in scripts/ runs as a student or logged
 * out, and asserts on behaviour. This bug was invisible to all of them: the
 * admin bar only exists when signed in as someone with rights, and "the header
 * is fine" is a statement about appearance, not behaviour. Seven live harnesses
 * and fourteen contract checks were green while the owner's homepage was
 * visibly broken. "The right user" had only ever been a question about
 * authorisation, never about layout.
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

  console.table({ width: innerWidth, barPosition: barCS.position, barHeight: barH, headerTop: hdrTop, coreVar });
  pass.forEach(p => console.log('ok   ', p));
  fail.forEach(f => console.error('FAIL ', f));
  console.log(`\n${pass.length} passed, ${fail.length} failed`);
  return { pass, fail };
})();

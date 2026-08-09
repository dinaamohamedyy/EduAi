#!/usr/bin/env node
/*
 * Syntax-checks the JavaScript embedded in the project's HTML pages.
 *
 *     node scripts/check-inline-js.js
 *
 * The CI JavaScript step only globs .js files under wp-content, which misses
 * the two standalone HTML pages — each carrying around a thousand lines of
 * inline script, one of which (design/preview.html) is a shipped artefact.
 * A typo in either used to be caught by nobody.
 *
 * Parsing only: the script is compiled, never run, so nothing in the page
 * executes here.
 */

'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');

// Resolved against the repository, not the shell's working directory, so this
// behaves the same from CI, from scripts/, or from anywhere else.
const ROOT = path.resolve(__dirname, '..');
const FILES = ['design/preview.html', 'tools/agent-test.html'];

// <script> with a src, or with a non-JavaScript type (JSON-LD, templates), is
// not ours to parse.
const SCRIPT = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
const JS_TYPE = /\btype\s*=\s*["']?(text\/javascript|application\/javascript|module)["']?/i;

let failed = false;

for (const file of FILES) {
  const abs = path.join(ROOT, file);

  if (!fs.existsSync(abs)) {
    console.error(`${file}: missing — expected it to be there`);
    failed = true;
    continue;
  }

  const html = fs.readFileSync(abs, 'utf8');
  let match;
  let blocks = 0;

  SCRIPT.lastIndex = 0;

  while ((match = SCRIPT.exec(html)) !== null) {
    const [, attrs, body] = match;

    if (/\bsrc\s*=/i.test(attrs)) continue;
    if (/\btype\s*=/i.test(attrs) && !JS_TYPE.test(attrs)) continue;
    if (!body.trim()) continue;

    blocks++;

    try {
      new vm.Script(body, { filename: `${file} (inline script ${blocks})` });
    } catch (err) {
      console.error(`${file}: inline script ${blocks} — ${err.message}`);
      failed = true;
    }
  }

  // A checker that silently examines nothing passes forever. If the markup
  // changes shape and the regex stops matching, that is a failure, not a pass.
  if (blocks === 0) {
    console.error(`${file}: no inline script found — this checker is looking at nothing`);
    failed = true;
    continue;
  }

  console.log(`${file}: ${blocks} inline script block(s) OK`);
}

process.exit(failed ? 1 : 0);

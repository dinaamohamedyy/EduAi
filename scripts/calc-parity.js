#!/usr/bin/env node
'use strict';

/*
 * Generates and verifies the AiCalc parity fixture.
 *
 *     node scripts/calc-parity.js            # verify the fixture still matches
 *     node scripts/calc-parity.js --write    # regenerate it
 *
 * design/preview.html holds the reference arithmetic implementation. The PHP
 * port in class-eduai-calc.php has to agree with it exactly — same result, same
 * intermediate lines, same refusals — or a student gets one answer in the demo
 * and a different one on the live site.
 *
 * This script is the reference half: it lifts the functions straight out of the
 * preview page, runs the case table through them, and writes the expected
 * output to scripts/calc-cases.json. scripts/calc-parity.php is the other half
 * and asserts the PHP port produces byte-identical results from that same file.
 * Both run in CI.
 *
 * Lifting the source rather than duplicating it is the whole point: a copy
 * would drift, and drift is exactly what this is meant to catch.
 */

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const PREVIEW = path.join(root, 'design', 'preview.html');
const FIXTURE = path.join(root, 'scripts', 'calc-cases.json');

/* Every case the port has to agree on. Grouped by what each one is actually
   testing, because an unexplained expected value is impossible to review. */
const CASES = [
  // Basic binary operations.
  '2+3',
  '12 * 8',
  '3.5 * 2',

  // Left associativity — these are the ones a naive right-to-left reducer
  // silently gets wrong.
  '10 - 4 - 3',
  '100 / 5 / 2',

  // Precedence.
  '2 + 3 * 4',
  '20 - 2 * 3 + 4',

  // Brackets, including nesting and a bracket that is not the first token.
  '(2 + 3) * 4',
  '((1+2)*(3+4))',
  '2 * (3 + (4 - 1))',

  // Right-associative exponentiation: 2^(3^2) = 512, not (2^3)^2 = 64.
  '2 ^ 3 ^ 2',
  '2^0.5',

  // Unary minus in each position it can legally appear.
  '-4 + 10',
  '2 * -3',
  '-2 ^ 2',

  // Floating point that must not leak its binary representation.
  '0.1 + 0.2',
  '1/3',

  // Conversational wrappers and pasted symbols.
  'what is 7 * 6?',
  'calculate 5 + 5',
  "what's 9 - 4",
  '8 ÷ 2 × 3',
  '2 − 5',

  // Everything below must be refused, so that it routes to the model instead.
  '5 / 0',          // not a finite result
  '2 +',            // trailing operator
  '(1+2',           // unbalanced bracket
  '42',             // a bare number is not a sum
  '10 % 3',         // unsupported operator
  'x + 1',          // algebra belongs to the model
  'derivative of x^2',
  'how long until it cools to 20 degrees',
  '',
];

/* Values asserted from mathematics, not from whatever the reference happens to
   produce.
 *
 * calc-cases.json is generated from design/preview.html, so the reference's
 * behaviour becomes the expected value by construction. That makes the fixture
 * excellent at catching drift between the two implementations and completely
 * blind to both of them being wrong in the same way — which is exactly what
 * happened: the reference folded unary minus into the number, recorded
 * -2^2 = 4, the PHP port matched it, and 31/31 passed while the product taught
 * a student the wrong precedence.
 *
 * These are the answers a maths textbook, Python, and Wolfram give. They are
 * checked on every run *including* --write, so a regeneration can never again
 * quietly enshrine a wrong convention.
 */
const TRUTH = {
  // Exponentiation binds tighter than unary minus: -a^b is -(a^b).
  '-2 ^ 2': -4,
  '-3^2': -9,
  // ...but a minus is still allowed to bind into an exponent on the right.
  '2^-1': 0.5,
  // ...and brackets still override, so the fix has not merely inverted the bug.
  '(-2)^2': 4,
  '-(3^2)': -9,
  // Right associativity, the other precedence rule that is easy to get backwards.
  '2 ^ 3 ^ 2': 512,
  // Ordinary precedence and associativity, as anchors.
  '2 + 3 * 4': 14,
  '10 - 4 - 3': 3,
  '100 / 5 / 2': 10,
  '(2 + 3) * 4': 20,
  '-4 + 10': 6,
  '2 * -3': -6,
  // Sign handling. Negative zero renders differently in the two languages by
  // default (JS String(-0) is "0"), so it is pinned rather than left to luck.
  '0 * -1': 0,
  '-0.5 * 2': -1,
  '5 - -3': 8,
  // An odd power keeps the sign, which -(2^3) must still do.
  '-2^3': -8,
};

// Every truth case must also be exercised, or the assertion is unreachable.
for (const input of Object.keys(TRUTH)) {
  if (!CASES.includes(input)) CASES.push(input);
}

/* Pull the reference implementation out of the preview page. The block runs
   from the arithmetic comment banner to the first function that is not part of
   it, and is evaluated in a scope of its own. */
function reference() {
  const html = fs.readFileSync(PREVIEW, 'utf8');

  const start = html.indexOf('/* ---------- arithmetic ----------');
  const end = html.indexOf('function arithmetic(');

  if (start < 0 || end < 0 || end <= start) {
    throw new Error(
      'could not locate the arithmetic block in design/preview.html — ' +
      'if it was renamed or moved, update the markers in this script'
    );
  }

  const src = html.slice(start, end);

  for (const name of ['expression', 'tokens', 'show', 'apply', 'reduce', 'solve']) {
    if (!src.includes('function ' + name + '(')) {
      throw new Error('the arithmetic block no longer defines ' + name + '()');
    }
  }

  // eslint-disable-next-line no-new-func
  return new Function(src + '\nreturn {expression:expression, solve:solve};')();
}

/* The one range where the two implementations are allowed to be compared.
   JavaScript's String() switches to exponential notation below 1e-6 and at or
   above 1e21; the PHP port always prints fixed. Inside these bounds the two are
   identical character for character, outside them they are not, so a case that
   strays outside is rejected at generation time rather than recorded as a
   parity claim that does not hold. Nothing a student types at a calculator gets
   near either bound. */
const FIXED_MIN = 1e-6;
const FIXED_MAX = 1e21;

function comparable(value) {
  const magnitude = Math.abs(value);
  return magnitude === 0 || (magnitude >= FIXED_MIN && magnitude < FIXED_MAX);
}

/* The shape both halves compare on. `null` anywhere means "refused, hand it to
   the model", and a refusal is as much a contract as a result is. */
function run(ref, input) {
  const expression = ref.expression(input);

  if (expression === null) {
    return { input, expression: null, lines: null, value: null };
  }

  const solved = ref.solve(expression);

  if (!solved) {
    return { input, expression, lines: null, value: null };
  }

  // Every intermediate value is displayed too, so every one of them has to be
  // inside the comparable range — not just the final answer.
  for (const line of solved.lines) {
    for (const token of line.split(/[^0-9.\-]+/)) {
      if (token === '' || token === '-' || token === '.') { continue; }
      if (!comparable(parseFloat(token))) {
        throw new Error(
          'case ' + JSON.stringify(input) + ' produces ' + token +
          ', which JavaScript prints in exponential notation and the PHP port ' +
          'does not. Drop the case, or teach EduAI_Calc::num() to match.'
        );
      }
    }
  }

  return {
    input,
    expression,
    lines: solved.lines,
    value: String(solved.value),
  };
}

/* Independent correctness gate. Runs before anything is written or compared,
   because a fixture that records a wrong answer is worse than no fixture. */
function assertTruth(results) {
  const wrong = [];

  for (const [input, want] of Object.entries(TRUTH)) {
    const got = results.find((r) => r.input === input);

    if (!got) {
      wrong.push(`${JSON.stringify(input)} is asserted but never evaluated`);
      continue;
    }
    if (got.value === null) {
      wrong.push(`${JSON.stringify(input)} was refused; it should evaluate to ${want}`);
      continue;
    }
    if (Math.abs(Number(got.value) - want) > 1e-9) {
      wrong.push(`${JSON.stringify(input)} gave ${got.value}, mathematics says ${want}`);
    }
  }

  if (wrong.length) {
    console.error('the reference implementation is mathematically wrong:\n');
    wrong.forEach((w) => console.error('  - ' + w));
    console.error(
      '\nThis is not a drift failure — both implementations may well agree.\n' +
      'Fix design/preview.html, re-port to PHP, then regenerate.'
    );
    process.exit(1);
  }
}

function main() {
  const write = process.argv.includes('--write');
  const ref = reference();
  const results = CASES.map((c) => run(ref, c));

  assertTruth(results);

  const payload = {
    note:
      'Generated by scripts/calc-parity.js from design/preview.html. ' +
      'Do not hand-edit — run `node scripts/calc-parity.js --write` instead. ' +
      'scripts/calc-parity.php asserts the PHP port matches this exactly.',
    cases: results,
  };

  const json = JSON.stringify(payload, null, 2) + '\n';

  if (write) {
    fs.writeFileSync(FIXTURE, json);
    const solved = results.filter((r) => r.value !== null).length;
    console.log(
      'calc-cases.json written: ' + results.length + ' cases, ' +
      solved + ' evaluated, ' + (results.length - solved) + ' refused'
    );
    return;
  }

  if (!fs.existsSync(FIXTURE)) {
    console.error('calc-cases.json is missing — run: node scripts/calc-parity.js --write');
    process.exit(1);
  }

  const onDisk = fs.readFileSync(FIXTURE, 'utf8');

  if (onDisk !== json) {
    console.error(
      'design/preview.html no longer produces the recorded results.\n' +
      'Either the reference implementation changed (regenerate with --write, ' +
      'and check the PHP port still agrees) or it regressed.'
    );

    const before = JSON.parse(onDisk).cases;
    for (let i = 0; i < results.length; i++) {
      const a = JSON.stringify(before[i]);
      const b = JSON.stringify(results[i]);
      if (a !== b) {
        console.error('\n  case: ' + JSON.stringify(results[i].input));
        console.error('  recorded: ' + a);
        console.error('  now:      ' + b);
      }
    }
    process.exit(1);
  }

  console.log('reference arithmetic matches calc-cases.json (' + results.length + ' cases)');
}

main();

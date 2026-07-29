#!/usr/bin/env node
/* ============================================================================
   CGA — scripts/i18n/extract_flows.mjs
   A purpose-built sweep for resources/js/registry/flows.js.

   WHY THIS IS NOT PART OF extract.mjs. flows.js is GENERATED
   (scripts/education/build_education_payload.mjs, from the mockups' 80 workflow
   walkthroughs) and its player-facing copy — the Learn flyout's "where this
   fits" block — lives under keys the general Vue extractor does not know:
   `wfName`, `familyLabel`, `trigger`, `terminal`, each step's `action`, and the
   `branches[]` outcome strings. Teaching the general extractor a one-file shape
   would couple it to a generated artifact; a dedicated sweep keeps that shape
   where it belongs.

   KEYS ARE A PURE FUNCTION OF THE TEXT — `<slug>_<sha8>`. Deterministic and
   collision-safe, so:
     - re-running this after flows.js is regenerated is idempotent for every
       unchanged string (same text -> same key -> same catalog entry), and
     - a consumer can resolve a raw flows.js string to its key AT RUNTIME with
       the same function, WITHOUT flows.js carrying hand-added keys it would lose
       on the next regeneration. That contract is documented for lane 6/15 in
       docs/plans/i18n/waves/flows.md (--wave writes it).

   next/prev are deliberately NOT extracted: each is a neighbouring step's
   `action`, so it is already captured and would only add duplicate instances.

   Usage:
     node scripts/i18n/extract_flows.mjs                 # report only
     node scripts/i18n/extract_flows.mjs --write         # + en/flows.json + meta/flows.json
     node scripts/i18n/extract_flows.mjs --wave PATH     # write the lane-6 wave doc
     node scripts/i18n/extract_flows.mjs --json PATH     # machine-readable dump
   ============================================================================ */

import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createHash } from 'node:crypto';

const ROOT     = fileURLToPath(new URL('../../', import.meta.url));
const FLOWS_JS = join(ROOT, 'resources', 'js', 'registry', 'flows.js');
const OUT_LOC  = join(ROOT, 'resources', 'js', 'i18n', 'locales', 'en', 'flows.json');
const OUT_META = join(ROOT, 'resources', 'js', 'i18n', 'meta', 'en', 'flows.json');
const NAMESPACE = 'flows';

/* ── The fields that hold player-facing prose. Everything else in flows.js is
   structure (n, total, minStep), an id token (wf), or a duplicate (next/prev). */
const WF_FIELDS   = ['wfName', 'familyLabel', 'trigger', 'terminal'];
const STEP_FIELD  = 'action';
const LIST_FIELD  = 'branches';

const ID_TOKEN   = /^(R|WF|F|I|CLK)-[\dA-Z-]*$/;
const HAS_LETTER = /[A-Za-z]/;

/* Prose filter — the same intent as extract.mjs isProse(), trimmed to what this
   data can contain. A bare id token or a numberish token is not copy. */
function isProse(raw) {
    const s = String(raw ?? '').trim();
    if (s.length < 3) return false;
    if (!HAS_LETTER.test(s)) return false;
    if (ID_TOKEN.test(s)) return false;
    if ((s.match(/[A-Za-z]/g) || []).length < 2) return false;
    return true;
}

/* vue-i18n reserved characters, escaped on emit exactly as extract.mjs does —
   `|` is the plural separator, `{` opens an interpolation, `@` opens a link. */
function esc(s) {
    return String(s).replace(/[|{}@]/g, (c) => `{'${c}'}`);
}

function slugify(s) {
    const base = s.toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 40);
    return base || 'step';
}

function sha(s) {
    return createHash('sha256').update(s, 'utf8').digest('hex');
}

/* THE CONTRACT (shared with the runtime consumer, see the wave doc): the key of
   a flows string is a pure function of its RAW text. */
export function flowsKey(rawText) {
    const s = String(rawText).replace(/\s+/g, ' ').trim();
    return `${slugify(s)}_${sha(s).slice(0, 8)}`;
}

function collect() {
    return import(pathToFileURL(FLOWS_JS).href).then((mod) => {
        const F = mod.FLOWS_BY_SURFACE || {};
        /* map raw-text -> {key, field, sample surface} so dedup is by text and
           the same string on twelve surfaces is one catalog entry. */
        const byText = new Map();
        const fieldCounts = {};

        const take = (raw, field, surface) => {
            const s = String(raw ?? '').replace(/\s+/g, ' ').trim();
            if (!isProse(s)) return;
            fieldCounts[field] = (fieldCounts[field] || 0) + 1;
            if (byText.has(s)) return;
            byText.set(s, { key: flowsKey(s), text: s, field, surface });
        };

        for (const surface of Object.keys(F)) {
            for (const wf of F[surface] || []) {
                for (const f of WF_FIELDS) take(wf[f], f, surface);
                for (const step of wf.steps || []) {
                    take(step[STEP_FIELD], STEP_FIELD, surface);
                    for (const b of step[LIST_FIELD] || []) take(b, LIST_FIELD, surface);
                }
            }
        }
        return { byText, fieldCounts };
    });
}

/* ── Main ────────────────────────────────────────────────────────────────── */
const argv  = process.argv.slice(2);
const opt   = (f) => { const i = argv.indexOf(f); return i >= 0 ? argv[i + 1] : null; };
const WRITE = argv.includes('--write');
const WAVEP = opt('--wave');
const JSONP = opt('--json');

const { byText, fieldCounts } = await collect();
const entries = [...byText.values()].sort((a, b) => a.key.localeCompare(b.key));

/* Collision guard: two different strings must never resolve to one key, or the
   runtime resolver would silently serve the wrong translation. sha8 makes this
   astronomically unlikely, but a sweep that ASSUMED it never printed would be
   the exact kind of silent cap this project forbids. */
const keys = new Set();
const collisions = [];
for (const e of entries) {
    if (keys.has(e.key)) collisions.push(e.key);
    keys.add(e.key);
}

console.log('\nCGA flows.js extraction');
console.log('=======================');
console.log(`source            resources/js/registry/flows.js`);
console.log(`unique strings    ${entries.length}`);
console.log('by field (instances, pre-dedup):');
for (const [k, v] of Object.entries(fieldCounts).sort((a, b) => b[1] - a[1])) {
    console.log(`  ${k.padEnd(14)} ${v}`);
}
if (collisions.length) {
    console.error(`\n!! KEY COLLISION on ${collisions.length} key(s): ${collisions.slice(0, 5).join(', ')}`);
    console.error('   Two distinct strings share a key — the runtime resolver would be wrong. Aborting write.');
    process.exit(1);
}

if (JSONP) {
    writeFileSync(JSONP, JSON.stringify({ count: entries.length, fieldCounts, entries }, null, 2));
    console.log(`\njson  -> ${JSONP}`);
}

if (WRITE) {
    const cat = {};
    const meta = {};
    for (const e of entries) {
        cat[e.key] = esc(e.text);         // stored value is escaped English
        meta[e.key] = {
            source_hash: sha(e.text),     // hash of the RAW text — what the consumer resolves against
            shape: `flows:${e.field}`,
            file: 'registry/flows.js',
            status: 'source',
        };
    }
    mkdirSync(dirname(OUT_LOC), { recursive: true });
    mkdirSync(dirname(OUT_META), { recursive: true });
    writeFileSync(OUT_LOC, JSON.stringify(cat, null, 2) + '\n');
    writeFileSync(OUT_META, JSON.stringify(meta, null, 2) + '\n');
    console.log(`\ncatalog -> ${OUT_LOC.replace(ROOT, '')}  (${entries.length} keys, namespace "${NAMESPACE}")`);
    console.log(`meta    -> ${OUT_META.replace(ROOT, '')}`);
}

if (WAVEP) {
    const sample = entries.slice(0, 8);
    const lines = [];
    lines.push('# Wave doc — wiring `registry/flows.js` to i18n (lane 6 / lane 15)\n');
    lines.push('_Generated by `scripts/i18n/extract_flows.mjs --wave`. Re-run to refresh._\n');
    lines.push('## What lane 5 did\n');
    lines.push(`- Extracted **${entries.length} unique player-facing strings** from \`resources/js/registry/flows.js\` into the \`${NAMESPACE}\` namespace: \`resources/js/i18n/locales/en/${NAMESPACE}.json\` (+ meta). These are the Learn flyout "where this fits" strings — workflow names, family labels, triggers, terminals, step actions, and branch outcomes.`);
    lines.push('- These land as English source now; they become translatable/verifiable through the standard queue (state `none` until a machine or human pass fills them). They are **not** in the current NLLB pass, which is scoped to `c_education` + `c_achievements`.\n');
    lines.push('## The load-bearing caveat: flows.js is GENERATED\n');
    lines.push('`flows.js` carries `GENERATED — do not edit` (built by `scripts/education/build_education_payload.mjs`). So it **cannot carry hand-added `$t()` keys** — the next regeneration would erase them. The extraction is therefore built around a key that is a **pure function of the English text**, so no key ever needs to live in flows.js.\n');
    lines.push('## The contract — resolve the key at runtime\n');
    lines.push('The key for a flows string is `slug(text)_<first 8 hex of sha256(text)>`. A consumer computes it from the raw string it already holds:\n');
    lines.push('```js');
    lines.push('// keep in sync with scripts/i18n/extract_flows.mjs flowsKey()');
    lines.push('import { createHash } from \'node:crypto\'; // browser: use a tiny sha256');
    lines.push('function flowsKey(raw) {');
    lines.push('  const s = String(raw).replace(/\\s+/g, \' \').trim();');
    lines.push('  const slug = s.toLowerCase().replace(/[^a-z0-9]+/g, \'_\').replace(/^_+|_+$/g, \'\').slice(0, 40) || \'step\';');
    lines.push('  const h = sha256hex(s).slice(0, 8);');
    lines.push('  return `flows.${slug}_${h}`; // vue-i18n: namespace-qualified');
    lines.push('}');
    lines.push('// render:  t(flowsKey(step.action))   — falls back to the English source when untranslated');
    lines.push('```\n');
    lines.push('Because vue-i18n falls back to `en` (and `missingWarn`/`fallbackWarn` are off), wiring this is **safe to land before translations exist**: an unresolved key renders the English source string, exactly as today.\n');
    lines.push('### Recommended split of work\n');
    lines.push('- **lane 15** (owns the generator): optionally have `build_education_payload.mjs` call the same `flowsKey()` and emit a parallel `keyForStep` map, so consumers need no runtime hashing. Either way the key scheme is identical.');
    lines.push('- **lane 6** (owns the flyout markup): wrap the rendered flows strings in `t(flowsKey(...))`. The catalog already carries the English, so the visible result is unchanged until a translation is verified.\n');
    lines.push('## Sample of the extracted keys\n');
    lines.push('| key | field | English |');
    lines.push('|---|---|---|');
    for (const e of sample) {
        lines.push(`| \`${e.key}\` | ${e.field} | ${e.text.replace(/\|/g, '\\|').slice(0, 80)} |`);
    }
    lines.push('');
    mkdirSync(dirname(WAVEP), { recursive: true });
    writeFileSync(WAVEP, lines.join('\n'));
    console.log(`\nwave  -> ${WAVEP}`);
}

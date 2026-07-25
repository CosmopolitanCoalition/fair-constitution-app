#!/usr/bin/env node
/* ============================================================================
   CGA — scripts/i18n/extract.mjs
   Phase N string extractor. Finds every user-facing English string hardcoded
   in the Vue tree and turns it into a keyed message with a stable id.

   THE SHAPES IT HANDLES (measured against this repo, see docs/plans/i18n/):
     a) template text nodes, with INLINE GLUE merged so a sentence wrapping a
        <Link> stays ONE message instead of fragmenting into three
     b) text-bearing attributes — static (title="…") and bound-with-a-literal
        (:label="cond ? 'A' : 'B'" yields TWO messages)
     c) string literals in <script setup> (label banks, enum→copy maps, toasts)
     d) interpolation-mixed nodes ({{ n }} clocks → "{n} clocks")
     e) component prop DEFAULTS declared inside component definitions, which a
        call-site-only scanner never sees

   RESERVED CHARACTERS: vue-i18n is NOT ICU. `|` is the plural separator, `{`
   opens an interpolation and `@:` opens a linked message, so every literal one
   is escaped on emit as {'|'} {'{'} {'@'}. Getting this wrong corrupts messages
   silently, because missingWarn/fallbackWarn are false.

   Usage:
     node scripts/i18n/extract.mjs                    # report only, no writes
     node scripts/i18n/extract.mjs --write            # + write en catalogs & meta
     node scripts/i18n/extract.mjs --json out.json    # machine-readable dump
     node scripts/i18n/extract.mjs --audit out.md     # per-page markdown audit

   Options:
     --write        emit resources/js/i18n/locales/en/<ns>.json + i18n/meta/en/
     --json PATH    write the full finding set as JSON
     --audit PATH   write the per-page/per-namespace audit table
     --quiet        suppress the console summary
   ============================================================================ */

import { readFileSync, writeFileSync, mkdirSync, readdirSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { parse as parseSfc } from '@vue/compiler-sfc';
import { parse as parseBabel } from '@babel/parser';

/* ─── Paths ──────────────────────────────────────────────────────────────── */
const ROOT      = fileURLToPath(new URL('../../', import.meta.url));
const JS_ROOT   = join(ROOT, 'resources', 'js');
const SCAN_DIRS = ['Pages', 'Components', 'Layouts'];
const REGISTRY  = ['registry/surfaces.js', 'registry/journeys.js', 'lib/csrf.js'];
const OUT_LOC   = join(JS_ROOT, 'i18n', 'locales', 'en');
const OUT_META  = join(JS_ROOT, 'i18n', 'meta', 'en');

/* ─── Extraction policy ──────────────────────────────────────────────────── */

/* Tags that are INLINE GLUE: their text belongs to the surrounding sentence.
   Without this a sentence wrapping a <Link> becomes three untranslatable
   fragments (Pages/System/Clocks.vue:176 is the canonical case). */
const GLUE = new Set([
    'strong', 'em', 'b', 'i', 'span', 'a', 'code', 'small', 'abbr', 'sup', 'sub', 'br',
    'Link', 'Icon', 'FormChip', 'TagChip', 'CitationLine', 'EngineChip', 'AdmChip',
]);

/* Attributes whose value is rendered to the user. */
const TEXT_ATTRS = new Set([
    'title', 'label', 'hint', 'placeholder', 'caption', 'eyebrow', 'text',
    'alt', 'aria-label', 'description', 'summary', 'empty', 'emptyText', 'confirmLabel',
]);

/* Never translate: code-ish, layout-ish, machine-ish. */
const SKIP_ATTRS = new Set([
    'class', 'style', 'id', 'key', 'ref', 'href', 'src', 'to', 'name', 'type', 'value',
    'width', 'height', 'viewBox', 'd', 'fill', 'stroke', 'role', 'for', 'method', 'as',
    'variant', 'tone', 'size', 'align', 'icon', 'kind', 'status', 'colspan', 'rowspan',
]);

/* Script-literal keys whose values are user-facing. */
const SCRIPT_KEYS = new Set([
    'label', 'title', 'text', 'hint', 'desc', 'description', 'message', 'caption',
    'name', 'heading', 'subtitle', 'placeholder', 'summary', 'empty', 'eyebrow',
]);

/* Calls whose string arguments are user-facing. */
const SCRIPT_CALLS = new Set(['announce', 'alert', 'confirm']);

const ID_TOKEN   = /^(R|WF|F|I|CLK)-[\dA-Z-]*$/;
const CITATION   = /Art\.\s|§/;
const URLISH     = /^(https?:|\/|#|mailto:|tel:|data:)/;
const SLUGGY     = /^[a-z0-9_.\-/#:@[\]]+$/;
const CSSY       = /^[\w\-\s]*(px|rem|em|%|vh|vw|fr)\b|^(flex|grid|none|auto|hidden|block|inline)/;
const HAS_LETTER = /[A-Za-z]/;

/* ─── Prose filter ───────────────────────────────────────────────────────── */
function isProse(raw) {
    const s = String(raw ?? '').trim();
    if (s.length < 3) return false;
    if (!HAS_LETTER.test(s)) return false;
    if (URLISH.test(s)) return false;
    if (ID_TOKEN.test(s)) return false;
    if (CSSY.test(s)) return false;
    /* a bare slug with no spaces and no capital is machine text, not copy */
    if (SLUGGY.test(s) && !/\s/.test(s)) return false;
    /* needs at least two letters somewhere */
    if ((s.match(/[A-Za-z]/g) || []).length < 2) return false;
    /* SCREAMING_CONST / PascalCaseIdentifier with no space */
    if (!/\s/.test(s) && /^[A-Z][A-Za-z0-9]*$/.test(s) && s.length < 24) return false;
    if (!/\s/.test(s) && /^[A-Z0-9_]+$/.test(s)) return false;
    return true;
}

/* vue-i18n reserved characters. Escaped at CAPTURE time, on raw source text
   only — never on the {placeholders} this extractor generates from {{ expr }},
   which must stay live. Escaping the whole assembled message would neuter every
   interpolation, which is exactly the bug this comment exists to prevent. */
function esc(s) {
    return String(s).replace(/[|{}@]/g, (c) => `{'${c}'}`);
}

function slugify(s) {
    const base = s.toLowerCase()
        .replace(/\{[^}]*\}/g, '')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 42);
    return base || 'msg';
}

function hash(s) {
    return createHash('sha256').update(s, 'utf8').digest('hex');
}

/* ─── Namespace mapping (per page-folder, single level — matches the loader
   glob ./locales/*​/*.json exactly; a nested path would NOT match) ────────── */
function namespaceFor(relPath) {
    const parts = relPath.split(sep);
    if (parts[0] === 'Pages') {
        return parts.length > 2 ? parts[1].toLowerCase() : 'pages';
    }
    if (parts[0] === 'Components') {
        return parts.length > 2 ? `c_${parts[1].toLowerCase()}` : 'components';
    }
    if (parts[0] === 'Layouts') return 'chrome';
    return 'registry';
}

/* ─── File walking ───────────────────────────────────────────────────────── */
function walk(dir, ext, acc = []) {
    let entries;
    try { entries = readdirSync(dir); } catch { return acc; }
    for (const e of entries) {
        const p = join(dir, e);
        let st;
        try { st = statSync(p); } catch { continue; }
        if (st.isDirectory()) walk(p, ext, acc);
        else if (e.endsWith(ext)) acc.push(p);
    }
    return acc;
}

/* ─── Template walking ───────────────────────────────────────────────────── */
/* @vue/compiler-sfc node types: 1=ELEMENT 2=TEXT 5=INTERPOLATION 9=IF 11=FOR */

function hasNoI18n(node) {
    return (node.props || []).some((p) => p.type === 6 && p.name === 'data-no-i18n');
}

function interpName(exp, counters) {
    const raw = String(exp || '').trim();
    const m = raw.match(/([A-Za-z_$][\w$]*)\s*$/) || raw.match(/([A-Za-z_$][\w$]*)/);
    let name = m ? m[1] : 'value';
    if (/^(true|false|null|undefined)$/.test(name)) name = 'value';
    counters[name] = (counters[name] || 0) + 1;
    return counters[name] > 1 ? `${name}${counters[name]}` : name;
}

/* Collect the inline run under an element into ONE message. Returns null when
   the run holds no prose. */
function collectRun(children) {
    let out = '';
    let sawText = false;
    const counters = {};
    let rawProbe = '';
    const walkRun = (nodes) => {
        for (const n of nodes) {
            if (n.type === 2) {                       // TEXT
                const t = n.content.replace(/\s+/g, ' ');
                if (t.trim()) sawText = true;
                rawProbe += t;
                out += esc(t);                        // escape SOURCE text only
            } else if (n.type === 5) {                // INTERPOLATION
                out += `{${interpName(n.content?.content, counters)}}`;
            } else if (n.type === 1 && GLUE.has(n.tag)) {
                if (hasNoI18n(n)) { out += ' '; continue; }
                walkRun(n.children || []);
            } else {
                return false;                          // block child ends the run
            }
        }
        return true;
    };
    const clean = walkRun(children);
    const msg = out.replace(/\s+/g, ' ').trim();
    /* prose-test the RAW text: escaping must not decide translatability */
    if (!clean || !sawText || !isProse(rawProbe.replace(/\s+/g, ' ').trim())) return null;
    return msg;
}

function isPureInlineRun(node) {
    return (node.children || []).every(
        (c) => c.type === 2 || c.type === 5 || (c.type === 1 && GLUE.has(c.tag)),
    );
}

/* Pull string literals out of a bound-attribute expression (:label="a ? 'X' : 'Y'"). */
function literalsInExpression(src) {
    const found = [];
    const re = /(['"])((?:(?!\1)[^\\]|\\.)*)\1/g;
    let m;
    while ((m = re.exec(src)) !== null) {
        const v = m[2].replace(/\\(['"\\])/g, '$1');
        if (isProse(v)) found.push(v);
    }
    /* template literals with no ${} */
    const tre = /`([^`$]*)`/g;
    while ((m = tre.exec(src)) !== null) {
        if (isProse(m[1])) found.push(m[1]);
    }
    return found;
}

function scanTemplate(root, push) {
    const visit = (node, inNoI18n) => {
        if (node.type === 1) {
            const skip = inNoI18n || hasNoI18n(node);

            /* attributes */
            for (const p of node.props || []) {
                if (p.type === 6) {                                     // static attr
                    if (SKIP_ATTRS.has(p.name) || !TEXT_ATTRS.has(p.name)) continue;
                    const v = p.value?.content;
                    if (v && isProse(v)) push(v, `attr:${p.name}`, p.loc?.start?.line);
                } else if (p.type === 7 && p.name === 'bind') {         // :attr
                    const arg = p.arg?.content;
                    if (!arg || !TEXT_ATTRS.has(arg) || SKIP_ATTRS.has(arg)) continue;
                    for (const lit of literalsInExpression(p.exp?.content || '')) {
                        push(lit, `bound:${arg}`, p.loc?.start?.line);
                    }
                }
            }

            if (!skip && isPureInlineRun(node) && (node.children || []).length) {
                const msg = collectRun(node.children);
                if (msg) push(msg, 'template', node.loc?.start?.line);
                return;                                                 // run consumed
            }
            for (const c of node.children || []) visit(c, skip);
            return;
        }
        if (node.type === 9) {                                          // v-if
            for (const b of node.branches || []) {
                for (const c of b.children || []) visit(c, inNoI18n);
            }
            return;
        }
        if (node.type === 11) {                                         // v-for
            for (const c of node.children || []) visit(c, inNoI18n);
            return;
        }
        if (node.children) for (const c of node.children) visit(c, inNoI18n);
    };
    for (const c of root.children || []) visit(c, false);
}

/* ─── Script scanning ────────────────────────────────────────────────────── */
function scanScript(code, push, offsetLine = 0) {
    let ast;
    try {
        ast = parseBabel(code, {
            sourceType: 'module',
            plugins: ['typescript', 'jsx'],
            errorRecovery: true,
        });
    } catch {
        return;
    }
    const line = (n) => (n.loc ? n.loc.start.line + offsetLine : undefined);

    const visit = (n, parent, key) => {
        if (!n || typeof n !== 'object') return;
        if (Array.isArray(n)) { for (const c of n) visit(c, parent, key); return; }
        if (!n.type) return;

        if (n.type === 'StringLiteral' && isProse(n.value)) {
            let take = false;
            let why = 'script';
            /* object property whose key is user-facing */
            if (parent?.type === 'ObjectProperty' && key === 'value') {
                const k = parent.key?.name ?? parent.key?.value;
                if (SCRIPT_KEYS.has(k)) { take = true; why = `script:${k}`; }
            }
            /* announce('…') / alert('…') */
            if (parent?.type === 'CallExpression' && key === 'arguments') {
                const callee = parent.callee?.name ?? parent.callee?.property?.name;
                if (SCRIPT_CALLS.has(callee)) { take = true; why = `script:${callee}`; }
            }
            /* prop default: { type: String, default: '…' } */
            if (parent?.type === 'ObjectProperty' && key === 'value'
                && (parent.key?.name === 'default')) {
                take = true; why = 'prop-default';
            }
            if (take) push(n.value, why, line(n));
        }

        for (const k of Object.keys(n)) {
            if (k === 'loc' || k === 'leadingComments' || k === 'trailingComments') continue;
            visit(n[k], n, k);
        }
    };
    visit(ast.program, null, null);
}

/* ─── Per-file extraction ────────────────────────────────────────────────── */
function extractVue(absPath) {
    const rel = relative(JS_ROOT, absPath);
    const src = readFileSync(absPath, 'utf8');
    const out = [];
    const seen = new Set();
    const push = (text, shape, line) => {
        const raw = String(text).replace(/\s+/g, ' ').trim();
        if (!isProse(raw)) return;
        /* template runs arrive pre-escaped with live placeholders; every other
           shape is a bare source literal and is escaped here */
        const msg = shape === 'template' ? raw : esc(raw);
        const dedupe = `${msg}::${shape}::${line}`;
        if (seen.has(dedupe)) return;
        seen.add(dedupe);
        out.push({ file: rel, ns: namespaceFor(rel), text: msg, shape, line });
    };

    let desc;
    try { desc = parseSfc(src, { filename: absPath }).descriptor; } catch { return out; }

    if (desc.template?.ast) {
        try { scanTemplate(desc.template.ast, push); } catch { /* keep going */ }
    }
    const blocks = [desc.script, desc.scriptSetup].filter(Boolean);
    for (const b of blocks) {
        const startLine = src.slice(0, b.loc.start.offset).split('\n').length - 1;
        scanScript(b.content, push, startLine);
    }
    return out;
}

function extractJs(absPath) {
    const rel = relative(JS_ROOT, absPath);
    const src = readFileSync(absPath, 'utf8');
    const out = [];
    const push = (text, shape, line) => {
        const raw = String(text).replace(/\s+/g, ' ').trim();
        if (!isProse(raw)) return;
        out.push({ file: rel, ns: namespaceFor(rel), text: esc(raw), shape, line });
    };
    scanScript(src, push, 0);
    return out;
}

/* ─── Main ───────────────────────────────────────────────────────────────── */
const argv   = process.argv.slice(2);
const opt    = (f) => { const i = argv.indexOf(f); return i >= 0 ? argv[i + 1] : null; };
const WRITE  = argv.includes('--write');
const QUIET  = argv.includes('--quiet');
const JSONP  = opt('--json');
const AUDITP = opt('--audit');

const vueFiles = SCAN_DIRS.flatMap((d) => walk(join(JS_ROOT, d), '.vue')).sort();
const jsFiles  = REGISTRY.map((r) => join(JS_ROOT, r)).filter((p) => {
    try { statSync(p); return true; } catch { return false; }
});

const findings = [];
for (const f of vueFiles) findings.push(...extractVue(f));
for (const f of jsFiles)  findings.push(...extractJs(f));

/* i18n adoption: which files already call t()/$t() */
const wired = new Set();
for (const f of vueFiles) {
    const src = readFileSync(f, 'utf8');
    if (/\buseI18n\b|\$t\(/.test(src)) wired.add(relative(JS_ROOT, f));
}

/* ─── Key assignment + dedup ─────────────────────────────────────────────── */
const byNs = new Map();
const uniqueByText = new Map();
for (const f of findings) {
    const fileSlug = f.file
        .replace(/\.(vue|js)$/, '')
        .split(sep).slice(1).join('_')
        .replace(/[^A-Za-z0-9_]/g, '_')
        .toLowerCase() || 'root';
    const textKey = `${f.ns}::${f.text}`;
    let key = uniqueByText.get(textKey);
    if (!key) {
        const base = `${fileSlug}.${slugify(f.text)}`;
        let candidate = base;
        let n = 2;
        const nsKeys = byNs.get(f.ns) ?? new Map();
        while (nsKeys.has(candidate)) { candidate = `${base}_${n++}`; }
        key = candidate;
        uniqueByText.set(textKey, key);
        nsKeys.set(key, { text: f.text, shape: f.shape, file: f.file, line: f.line });
        byNs.set(f.ns, nsKeys);
    }
    f.key = key;
}

/* ─── Report ─────────────────────────────────────────────────────────────── */
const perFile = new Map();
for (const f of findings) {
    const e = perFile.get(f.file) ?? { file: f.file, ns: f.ns, total: 0, shapes: {} };
    e.total += 1;
    e.shapes[f.shape.split(':')[0]] = (e.shapes[f.shape.split(':')[0]] || 0) + 1;
    perFile.set(f.file, e);
}
const perNs = new Map();
for (const [ns, keys] of byNs) perNs.set(ns, keys.size);

const totals = {
    files_scanned: vueFiles.length + jsFiles.length,
    vue_files: vueFiles.length,
    files_with_strings: perFile.size,
    files_already_wired: wired.size,
    instances: findings.length,
    unique_keys: uniqueByText.size,
    namespaces: byNs.size,
    dedup_ratio: +(findings.length / Math.max(uniqueByText.size, 1)).toFixed(3),
    by_shape: findings.reduce((a, f) => {
        const k = f.shape.split(':')[0];
        a[k] = (a[k] || 0) + 1;
        return a;
    }, {}),
};

if (!QUIET) {
    console.log('\nCGA i18n extraction');
    console.log('===================');
    console.log(`files scanned          ${totals.files_scanned} (${totals.vue_files} .vue)`);
    console.log(`files with copy        ${totals.files_with_strings}`);
    console.log(`files already wired    ${totals.files_already_wired}`);
    console.log(`message instances      ${totals.instances}`);
    console.log(`unique messages        ${totals.unique_keys}  (dedup ${totals.dedup_ratio}x)`);
    console.log(`namespaces             ${totals.namespaces}`);
    console.log('\nby shape:');
    for (const [k, v] of Object.entries(totals.by_shape).sort((a, b) => b[1] - a[1])) {
        console.log(`  ${k.padEnd(14)} ${v}`);
    }
    console.log('\ntop namespaces:');
    for (const [ns, n] of [...perNs].sort((a, b) => b[1] - a[1]).slice(0, 12)) {
        console.log(`  ${ns.padEnd(18)} ${n}`);
    }
    console.log('\ntop files:');
    for (const e of [...perFile.values()].sort((a, b) => b.total - a.total).slice(0, 10)) {
        console.log(`  ${String(e.total).padStart(4)}  ${e.file}`);
    }
}

if (JSONP) {
    writeFileSync(JSONP, JSON.stringify({ totals, perNs: [...perNs], perFile: [...perFile.values()], findings }, null, 2));
    if (!QUIET) console.log(`\njson  -> ${JSONP}`);
}

if (AUDITP) {
    const lines = [];
    lines.push('# Extraction audit — hardcoded user-facing strings\n');
    lines.push(`Generated by \`scripts/i18n/extract.mjs\`. Re-run to refresh.\n`);
    lines.push('## Totals\n');
    lines.push('| Metric | Value |');
    lines.push('|---|---:|');
    for (const [k, v] of Object.entries(totals)) {
        if (typeof v === 'object') continue;
        lines.push(`| ${k.replace(/_/g, ' ')} | ${v} |`);
    }
    lines.push('\n## By shape\n');
    lines.push('| Shape | Instances |');
    lines.push('|---|---:|');
    for (const [k, v] of Object.entries(totals.by_shape).sort((a, b) => b[1] - a[1])) {
        lines.push(`| ${k} | ${v} |`);
    }
    lines.push('\n## By namespace\n');
    lines.push('| Namespace | Unique keys |');
    lines.push('|---|---:|');
    for (const [ns, n] of [...perNs].sort((a, b) => b[1] - a[1])) lines.push(`| ${ns} | ${n} |`);
    lines.push('\n## By file\n');
    lines.push('| File | Namespace | Instances | Wired |');
    lines.push('|---|---|---:|---|');
    for (const e of [...perFile.values()].sort((a, b) => b.total - a.total)) {
        lines.push(`| ${e.file.replace(/\\/g, '/')} | ${e.ns} | ${e.total} | ${wired.has(e.file) ? 'yes' : ''} |`);
    }
    mkdirSync(join(AUDITP, '..'), { recursive: true });
    writeFileSync(AUDITP, lines.join('\n') + '\n');
    if (!QUIET) console.log(`audit -> ${AUDITP}`);
}

if (WRITE) {
    mkdirSync(OUT_LOC, { recursive: true });
    mkdirSync(OUT_META, { recursive: true });
    for (const [ns, keys] of byNs) {
        const cat = {};
        const meta = {};
        for (const [key, v] of [...keys].sort((a, b) => a[0].localeCompare(b[0]))) {
            cat[key] = v.text;   // already escaped at capture
            meta[key] = {
                source_hash: hash(v.text),
                shape: v.shape,
                file: v.file.replace(/\\/g, '/'),
                line: v.line ?? null,
                status: 'source',
            };
        }
        writeFileSync(join(OUT_LOC, `${ns}.json`), JSON.stringify(cat, null, 2) + '\n');
        writeFileSync(join(OUT_META, `${ns}.json`), JSON.stringify(meta, null, 2) + '\n');
    }
    if (!QUIET) console.log(`\ncatalogs -> ${relative(ROOT, OUT_LOC)}  (${byNs.size} namespaces)`);
    if (!QUIET) console.log(`meta     -> ${relative(ROOT, OUT_META)}  (outside the loader glob)`);
}

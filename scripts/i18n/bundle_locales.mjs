#!/usr/bin/env node
/* ============================================================================
   CGA — scripts/i18n/bundle_locales.mjs
   One JSON bundle per locale, served as a static file and fetched at runtime.

   WHY THIS EXISTS (WoS beta, 2026-09-17): resources/js/i18n/index.js used to
   inline EVERY locale into the client bundle through an eager import.meta.glob.
   With 76 languages that is 160 MB of JSON in one module graph: the
   production build ran out of heap on a 16 GB host and, had it finished, every
   visitor would have downloaded the whole world's catalogs to read one. A
   locale is now a static file the browser fetches when it is needed:

     public/i18n/<code>.json   = the root chrome dict (resources/js/i18n/<code>.json,
                                 nested: app.*, nav.*, header.*, ...) with every
                                 namespace catalog (locales/<code>/<ns>.json)
                                 merged on top under its namespace key, exactly
                                 the shape index.js used to build in memory.
     public/i18n/manifest.json = {code: {bytes, hash, namespaces}} for diagnostics.

   The bundles are generated, never committed (.gitignore /public/i18n/). The
   Vite plugin in vite.config.js runs this at build start and at dev-server
   start, and re-runs it when a catalog changes in dev, so `npm run build` and
   `npm run dev` need no extra step. It also runs standalone:

     node scripts/i18n/bundle_locales.mjs            # write public/i18n
     node scripts/i18n/bundle_locales.mjs --check    # exit 1 if a bundle is stale
   ============================================================================ */
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readdirSync, readFileSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
export const ROOT = resolve(HERE, '..', '..');
export const I18N_DIR = join(ROOT, 'resources', 'js', 'i18n');
export const LOCALES_DIR = join(I18N_DIR, 'locales');
export const OUT_DIR = join(ROOT, 'public', 'i18n');

function readJson(path) {
    return JSON.parse(readFileSync(path, 'utf8'));
}

/** Every locale code that ships a catalog: a locales/<code>/ directory or a root <code>.json. */
export function localeCodes({ i18nDir = I18N_DIR, localesDir = LOCALES_DIR } = {}) {
    const codes = new Set();
    if (existsSync(localesDir)) {
        for (const d of readdirSync(localesDir)) {
            if (statSync(join(localesDir, d)).isDirectory()) codes.add(d);
        }
    }
    for (const f of readdirSync(i18nDir)) {
        const m = f.match(/^([A-Za-z]{2,3}(?:-[A-Za-z0-9]+)*)\.json$/);
        if (m && m[1] !== 'coverage') codes.add(m[1]);
    }
    return [...codes].sort();
}

/** The bundle for one locale: root chrome dict + namespaces, the index.js shape. */
export function bundleFor(code, { i18nDir = I18N_DIR, localesDir = LOCALES_DIR } = {}) {
    const chromePath = join(i18nDir, `${code}.json`);
    const out = existsSync(chromePath) ? { ...readJson(chromePath) } : {};
    const namespaces = [];
    const dir = join(localesDir, code);
    if (existsSync(dir)) {
        for (const f of readdirSync(dir).sort()) {
            if (!f.endsWith('.json')) continue;
            const ns = f.slice(0, -5);
            out[ns] = { ...(out[ns] || {}), ...readJson(join(dir, f)) };
            namespaces.push(ns);
        }
    }
    return { messages: out, namespaces };
}

/**
 * Write every bundle. Returns the manifest. Stale <code>.json files that no
 * longer have a catalog are removed so a retired locale never lingers.
 */
export function bundleLocales({ i18nDir = I18N_DIR, localesDir = LOCALES_DIR, outDir = OUT_DIR, log = () => {} } = {}) {
    mkdirSync(outDir, { recursive: true });
    const codes = localeCodes({ i18nDir, localesDir });
    const manifest = {};
    let total = 0;
    for (const code of codes) {
        const { messages, namespaces } = bundleFor(code, { i18nDir, localesDir });
        const text = JSON.stringify(messages);
        const hash = createHash('sha256').update(text).digest('hex').slice(0, 12);
        const path = join(outDir, `${code}.json`);
        if (!existsSync(path) || readFileSync(path, 'utf8') !== text) {
            writeFileSync(path, text, 'utf8');
        }
        manifest[code] = { bytes: Buffer.byteLength(text, 'utf8'), hash, namespaces: namespaces.length };
        total += manifest[code].bytes;
    }
    const keep = new Set(codes.map((c) => `${c}.json`));
    for (const f of readdirSync(outDir)) {
        if (f.endsWith('.json') && f !== 'manifest.json' && !keep.has(f)) unlinkSync(join(outDir, f));
    }
    writeFileSync(join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n', 'utf8');
    log(`i18n bundles: ${codes.length} locales, ${(total / 1048576).toFixed(1)} MB -> ${outDir}`);
    return manifest;
}

/** True when every bundle on disk matches the catalogs (for --check). */
export function bundlesFresh({ i18nDir = I18N_DIR, localesDir = LOCALES_DIR, outDir = OUT_DIR } = {}) {
    for (const code of localeCodes({ i18nDir, localesDir })) {
        const path = join(outDir, `${code}.json`);
        if (!existsSync(path)) return false;
        const { messages } = bundleFor(code, { i18nDir, localesDir });
        if (readFileSync(path, 'utf8') !== JSON.stringify(messages)) return false;
    }
    return true;
}

const isMain = process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isMain) {
    if (process.argv.includes('--check')) {
        const fresh = bundlesFresh();
        console.log(fresh ? 'i18n bundles: fresh' : 'i18n bundles: STALE (run node scripts/i18n/bundle_locales.mjs)');
        process.exit(fresh ? 0 : 1);
    }
    bundleLocales({ log: console.log });
}

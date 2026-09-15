// @ts-check
// resolve-ids.mjs (host) — turn the live-DB sample values from a11y:roster-ids
// into substituted parameterised URLs for the accessibility sweep, and pin a
// committed sample of the result.
//
//   node tests/browser/roster/resolve-ids.mjs
//
// It runs `docker exec fc_app php artisan a11y:roster-ids --json`, substitutes
// each sample into every parameterised PAGE route (paramPages from roster.mjs),
// verifies each built URL with a GET to the app on the host (200 or a 302 to
// login = resolved; 404 = wrong id), writes tests/browser/roster/param-ids.json
// (regenerated on demand), and prints the resolved URL list. Routes whose
// sample is null are printed as NO SAMPLE with the missing parameter name.
//
// Required params with no sample make a route NO SAMPLE. Optional params
// ({module?}, {sub?}) are dropped from the URL so the route resolves at its base.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { deriveRoster } from './roster.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const BASE = process.env.CGA_BROWSER_BASE_URL || 'http://localhost:8080';

function sampleMap() {
    const r = spawnSync('docker', ['exec', 'fc_app', 'php', 'artisan', 'a11y:roster-ids', '--json'], {
        encoding: 'utf8',
        maxBuffer: 8 * 1024 * 1024,
    });
    if (r.status !== 0) {
        process.stderr.write((r.stderr || r.stdout || 'a11y:roster-ids failed') + '\n');
        process.exit(1);
    }
    // The JSON array is the last bracketed block in stdout.
    const text = r.stdout;
    const start = text.indexOf('[');
    const end = text.lastIndexOf(']');
    const rows = JSON.parse(text.slice(start, end + 1));
    const map = new Map();
    for (const row of rows) map.set(row.param, row);
    return { rows, map };
}

// Build a URL for a param route: required params substituted from the sample,
// optional params ({p?}) dropped. Returns { url, missing:[names] }.
// `overrides` maps a parameter name to a different sample key (e.g. the /cgc
// route substitutes {organization} from the CGC sample 'organization_cgc').
function buildUrl(uri, map, overrides = null) {
    const missing = [];
    // Drop optional segments entirely: "/a/{p?}" -> "/a".
    let u = uri.replace(/\/\{[^}]+\?\}/g, '');
    u = u.replace(/\{([^}?]+)\}/g, (_m, name) => {
        const key = overrides && overrides[name] ? overrides[name] : name;
        const s = map.get(key);
        const v = s ? s.value : null;
        if (v === null || v === undefined) {
            missing.push(name);
            return `{${name}}`;
        }
        return encodeURIComponent(v);
    });
    return { url: missing.length ? null : u, missing };
}

// Per-route sample overrides. The CGC profile route wants an is_cgc org so the
// CGC page itself is swept, not the oldest (non-CGC) organization.
const URL_OVERRIDES = {
    '/organizations/{organization}/cgc': { organization: 'organization_cgc' },
};

const NULL_DEVICE = process.platform === 'win32' ? 'NUL' : '/dev/null';
function httpCode(url) {
    const r = spawnSync('curl', ['-s', '-o', NULL_DEVICE, '-w', '%{http_code}', BASE + url], {
        encoding: 'utf8',
    });
    return r.status === 0 ? r.stdout.trim() : `curl-err:${r.status}`;
}

const { rows, map } = sampleMap();
const { paramPages } = deriveRoster();

const resolved = [];
const noSample = [];
for (const p of paramPages) {
    const { url, missing } = buildUrl(p.uri, map, URL_OVERRIDES[p.uri] || null);
    if (url === null) {
        noSample.push({ uri: p.uri, missing });
        continue;
    }
    const code = httpCode(url);
    resolved.push({ uri: p.uri, url, code });
}

// The setup wizard has seven step pages behind one template; sample every step,
// not only step 0, so each page component is swept (W-0447, 2026-09-15).
const step0 = resolved.find((r) => r.uri === '/setup/step/{n}');
if (step0) {
    for (const n of [1, 2, 3, 4, 5, 6]) {
        const url = step0.url.slice(0, -1) + n;
        resolved.push({ uri: '/setup/step/{n}', url, code: httpCode(url), note: 'wizard step ' + n });
    }
}

const out = {
    _note: 'Committed sample of resolved parameterised URLs for the a11y sweep. Regenerate: node tests/browser/roster/resolve-ids.mjs. 200 or 302 (to login) = resolved; 404 = wrong id.',
    _generatedAt: new Date().toISOString().slice(0, 10),
    base: BASE,
    samples: rows,
    resolved,
    noSample,
};
fs.writeFileSync(path.join(HERE, 'param-ids.json'), JSON.stringify(out, null, 2));

console.log(`base ${BASE}`);
console.log(`resolved ${resolved.length} / ${paramPages.length} param routes; ${noSample.length} NO SAMPLE\n`);
for (const r of resolved) console.log(`  ${r.code}  ${r.url}   (${r.uri})`);
if (noSample.length) {
    console.log('\nNO SAMPLE (no live row for the parameter):');
    for (const n of noSample) console.log(`  ${n.uri}   missing: ${n.missing.join(', ')}`);
}
console.log(`\nwrote ${path.relative(process.cwd(), path.join(HERE, 'param-ids.json'))}`);

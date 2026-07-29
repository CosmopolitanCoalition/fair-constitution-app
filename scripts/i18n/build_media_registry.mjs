#!/usr/bin/env node
/* ============================================================================
   build_media_registry.mjs — generate the CGA media registry.

   The app's multi-track video player (Learn · video library) needs a catalog
   of what films exist, in which languages, and where their tracks live. Lane 11
   (the dubbing pipeline) owns the media and its language table; this generator
   turns that data into the two mirrored artifacts the app reads, exactly as
   scripts/i18n/languages.py mirrors the locale registry into PHP + JS:

     config/cga/media.php            server source of truth (MediaMeta::for)
     resources/js/registry/media.js  client mirror (the player + library page)

   SOURCE (lane 11, read-only, outside the repo — the operator regenerates when
   the coalition library changes):
     <source>/config/subjects.json     61 subjects: subject / token / slug / master
     <source>/config/languages.json    77 languages: bcp47 / name / native / dir / locale
     <source>/out/<Subject>/manifest.json   (optional) per-subject duration

   The five things lane 11's APP_PORT_NOTE says to get right, all honored here:
     1. <track srclang> uses `bcp47`, never `wp_code` (browsers reject 639-2/B).
     2. token -> subject is a LOOKUP (subjects.json), never hyphen->space.
     3. Chinese: the track code is `bcp47` (cmn-Hans); the app `locale` is zh-Hans
        — both are carried so the player can link a track to the user's locale.
     4. Coverage comes from the language table's in_library flag, not a fixture,
        so a language with no media (e.g. ht) never yields a 404-ing track.
     5. Media filenames embed the language ENGLISH NAME and the subject verbatim
        (spaces): "<Subject>-<Name>.<ext>" — the player percent-encodes PER PATH
        SEGMENT (encodeURIComponent), which is a player concern, not this file's.

   No media ships in the repo. `base_url` is env-driven (CGA_MEDIA_BASE_URL);
   null -> the player renders the labelled poster placeholder (dev/demo), and
   lights up with real playback the moment the operator points it at the host.

   Usage:
     node scripts/i18n/build_media_registry.mjs            # write both artifacts
     node scripts/i18n/build_media_registry.mjs --check    # exit 1 if stale
     node scripts/i18n/build_media_registry.mjs --source D:\path\to\video-translate
   ============================================================================ */
import { readFileSync, writeFileSync, existsSync, readdirSync } from 'node:fs';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..', '..');

function argOf(flag, dflt) {
    const i = process.argv.indexOf(flag);
    return i >= 0 && process.argv[i + 1] ? process.argv[i + 1] : dflt;
}
const SOURCE = argOf('--source', 'E:\\workflows\\video-translate');
const CHECK = process.argv.includes('--check');

const OUT_PHP = join(ROOT, 'config', 'cga', 'media.php');
const OUT_JS = join(ROOT, 'resources', 'js', 'registry', 'media.js');

function readJson(p) {
    return JSON.parse(readFileSync(p, 'utf-8'));
}

/* ── Poster gradient: subject -> one of the ten shipped .vposter--* values.
   Cosmetic (the placeholder gradient / module tint); a keyword map with a
   'learn' default, since the library is a teaching surface. ───────────────── */
const POSTER_RULES = [
    [/login|onboard|profile|account|role|individual|residen/i, 'civic'],
    [/vote|voting|ballot|electoral|ranked|plurality|proportional/i, 'elections'],
    [/committee|legislat|constitutional|preamble|ratif|template|principle|order|amendment/i, 'chamber'],
    [/judiciar|court|case|challenge/i, 'court'],
    [/government|operator|jurisdiction|legitimacy/i, 'operator'],
    [/organization|co-?determination|regulatory|parity|business|corp/i, 'org'],
    [/affiliate|donor|shop|resource|econom|market|unit|stipend|ledger/i, 'econ'],
    [/communit|global|engage|discord|event|focus|directory|supporter|volunteer|creator|leader|square|social/i, 'social'],
    [/coalition|introduction|cosmopolitan|welcome|thank/i, 'brand'],
];
function posterFor(subject) {
    for (const [re, poster] of POSTER_RULES) if (re.test(subject)) return poster;
    return 'learn';
}

/* Human title: keep the subject, but split a trailing part-number so
   "Legislatures2" reads "Legislatures (Part 2)" rather than a bare digit. */
function titleFor(subject) {
    const m = subject.match(/^(.*?)(\d+)$/);
    if (m && m[1].trim()) return `${m[1].trim()} (Part ${m[2]})`;
    return subject;
}

function build() {
    const langsRaw = readJson(join(SOURCE, 'config', 'languages.json')).languages;
    const subjectsRaw = readJson(join(SOURCE, 'config', 'subjects.json')).subjects;

    /* The coverage language table: every in-library language keyed by its
       BCP-47 tag (the <track srclang>). name is the FILENAME token; locale is
       the app locale when one exists (so the player can link a track to the
       viewer's language); dir drives the caption bar direction. */
    const languages = {};
    for (const l of langsRaw) {
        if (!l.in_library || !l.bcp47) continue;
        languages[l.bcp47] = {
            name: l.name,            // English name — the media filename token
            native: l.native || l.name,
            dir: l.dir || 'ltr',
            locale: l.locale || null, // app locale (zh-Hans, ar, …) or null
        };
    }
    const coverage = Object.keys(languages).sort();

    /* Optional per-subject duration from a local manifest (only a few ship
       locally; the rest read duration from the media at play time). */
    function durationFor(subject) {
        const p = join(SOURCE, 'out', subject, 'manifest.json');
        if (!existsSync(p)) return null;
        try {
            const d = readJson(p).duration;
            return typeof d === 'number' ? d : null;
        } catch { return null; }
    }

    const videos = subjectsRaw.map((s) => ({
        id: 'v-' + s.slug,
        subject: s.subject,       // spaces preserved — the filesystem token
        token: s.token,           // hyphens — id/slug only, never inverted
        slug: s.slug,
        master: s.master,         // "<Subject>-Silent.mp4"
        title: titleFor(s.subject),
        poster: posterFor(s.subject),
        seconds: durationFor(s.subject),
        /* subjects.json asserts a uniform 77 audio + 77 caption tracks per
           subject; coverage is the in-library language set. Where a per-subject
           manifest later narrows this, the generator will read it. */
        audio: coverage,
        captions: coverage,
    }));

    return { languages, videos, coverage_count: coverage.length };
}

/* ── Emitters ─────────────────────────────────────────────────────────────── */
function phpValue(v, indent) {
    if (v === null) return 'null';
    if (typeof v === 'number') return String(v);
    if (typeof v === 'boolean') return v ? 'true' : 'false';
    if (typeof v === 'string') return "'" + v.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
    if (Array.isArray(v)) {
        if (v.every((x) => typeof x === 'string')) {
            return '[' + v.map((x) => phpValue(x)).join(', ') + ']';
        }
        const pad = ' '.repeat(indent + 4);
        return '[\n' + v.map((x) => pad + phpValue(x, indent + 4) + ',').join('\n') + '\n' + ' '.repeat(indent) + ']';
    }
    const pad = ' '.repeat(indent + 4);
    const rows = Object.entries(v).map(([k, val]) => `${pad}'${k}' => ${phpValue(val, indent + 4)},`);
    return '[\n' + rows.join('\n') + '\n' + ' '.repeat(indent) + ']';
}

function renderPhp(d) {
    return `<?php

/*
|--------------------------------------------------------------------------
| GENERATED FILE — DO NOT EDIT
|--------------------------------------------------------------------------
|
| Written by scripts/i18n/build_media_registry.mjs from lane 11's
| config/subjects.json + config/languages.json. Re-run:
|
|     node scripts/i18n/build_media_registry.mjs
|
| The server half of the media registry (App\\Support\\MediaMeta reads it).
| No media ships in the repo: 'base_url' is env-driven (CGA_MEDIA_BASE_URL);
| null means the player renders the labelled poster placeholder.
|
| Track codes are BCP-47 (the <track srclang>); media filenames embed the
| language ENGLISH NAME and the subject verbatim ("<Subject>-<Name>.<ext>").
*/

return [

    'base_url' => env('CGA_MEDIA_BASE_URL'),

    'languages' => ${phpValue(d.languages, 4)},

    'videos' => ${phpValue(d.videos, 4)},
];
`;
}

function renderJs(d) {
    return `/* ============================================================================
   GENERATED FILE — DO NOT EDIT
   Written by scripts/i18n/build_media_registry.mjs from lane 11's
   config/subjects.json + config/languages.json. Re-run:
       node scripts/i18n/build_media_registry.mjs

   The client half of the media registry — the multi-track player and the
   video-library page render from it. Display + coverage data only; the media
   base URL is env-driven and arrives as a page prop (never hardcoded here).

   Track codes are BCP-47 (the <track srclang>). Media filenames embed the
   language ENGLISH NAME and the subject verbatim: "<Subject>-<Name>.<ext>".
   ============================================================================ */

/** BCP-47 track code -> { name (filename token), native, dir, locale }. */
export const MEDIA_LANGUAGES = ${JSON.stringify(d.languages, null, 4)};

/** The film catalog. audio/captions are BCP-47 codes into MEDIA_LANGUAGES. */
export const MEDIA_VIDEOS = ${JSON.stringify(d.videos, null, 4)};

/** All BCP-47 codes that have media, sorted. */
export const MEDIA_COVERAGE = Object.keys(MEDIA_LANGUAGES).sort();
`;
}

/* ── Main ─────────────────────────────────────────────────────────────────── */
if (!existsSync(join(SOURCE, 'config', 'subjects.json'))) {
    console.error(`No source at ${SOURCE} — pass --source <path to video-translate>.`);
    process.exit(2);
}

const data = build();
const php = renderPhp(data);
const js = renderJs(data);

if (CHECK) {
    const stalePhp = !existsSync(OUT_PHP) || readFileSync(OUT_PHP, 'utf-8') !== php;
    const staleJs = !existsSync(OUT_JS) || readFileSync(OUT_JS, 'utf-8') !== js;
    if (stalePhp || staleJs) {
        console.error('STALE media registry — re-run: node scripts/i18n/build_media_registry.mjs');
        process.exit(1);
    }
    console.log('media registry is current');
    process.exit(0);
}

writeFileSync(OUT_PHP, php, 'utf-8');
writeFileSync(OUT_JS, js, 'utf-8');
console.log(`media registry written:
  ${data.videos.length} videos · ${data.coverage_count} languages
  ${OUT_PHP.replace(ROOT + '\\', '')}
  ${OUT_JS.replace(ROOT + '\\', '')}`);

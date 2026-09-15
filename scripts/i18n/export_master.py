#!/usr/bin/env python3
"""
CGA - scripts/i18n/export_master.py
The master string export. Writes the strings a locale still needs, packaged so
a competent AI can translate them in one paste and hand them back.

THE GOAL THIS SERVES: scale translation up fast. One export produces
self-describing JSON chunk files. Each file carries a context header (the
surface, the pages that use it, the glossary, and a complete prompt) so any
AI - local or hosted - translates with the same context this repo's own
providers get. The translated files come back through import_translated.py,
which validates them against the same QA the machine pass uses.

WHAT COUNTS AS "NEEDS TRANSLATION" (the same rules translate_catalog.py honours,
plus a still-English check):
  - a namespace file absent for the locale, or
  - a key absent from the locale, or
  - a key whose value is still the English source (untranslated),
  AND the string has translatable content (a pure citation, ID token or number
  needs no AI and would be refused on import), AND the string is not locked or
  human-reviewed in the meta tree.

Non-translatable strings (pure citation / ID token / number) are left to the
ordinary machine pass, which copies them through unchanged.

Usage:
  python3 scripts/i18n/export_master.py --locale es
  python3 scripts/i18n/export_master.py --locale all
  python3 scripts/i18n/export_master.py --locale fr --namespace auth --out /tmp/x
  python3 scripts/i18n/export_master.py --self-test

Options:
  --locale CODE     target locale, `all` for every registry row with target: true
                    (75 non-English today), or `conference` for the six conference locales
  --namespace NS    restrict to one namespace (default: all)
  --chunk N         max strings per file (default 250)
  --out DIR         output root (default storage/app/i18n-export)
  --i18n-dir DIR    i18n root to read (default resources/js/i18n) - for tests
  --self-test       run the built-in fixture test and exit
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from pathlib import Path

# Run as `python3 scripts/i18n/export_master.py`, so this directory is on the
# path and the machine pass is importable. Reuse its rails so the export honours
# EXACTLY the same masking and status rules.
sys.path.insert(0, str(Path(__file__).resolve().parent))
import translate_catalog as tc  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_I18N = ROOT / "resources" / "js" / "i18n"
DEFAULT_OUT = ROOT / "storage" / "app" / "i18n-export"

# The settled conference locales (CLAUDE.md, i18nCoverage.test.mjs).
CONFERENCE = ["ar", "es", "fr", "hi", "pt", "zh-Hans"]

_MARKER = re.compile(r"\[\d+\]")
_LETTER = re.compile(r"[^\W\d_]", re.UNICODE)


# One-line surface descriptions, hand-authored, one per namespace. Context for
# the AI: what the strings on this surface are about. Not a claim about code
# behaviour - a plain label for the translator.
NAMESPACE_CONTEXT = {
    "auth": "Sign-in, registration, and operator-login screens.",
    "c_achievements": "Civic achievement and progress badges.",
    "c_bill": "Bill drafting and reading views.",
    "c_civic": "Shared civic-surface labels used across pages.",
    "c_community": "Community landing and member views.",
    "c_education": "Lessons, courses, and training content.",
    "c_electoral": "Electoral surface controls and status labels.",
    "c_executive": "Executive-branch surface labels.",
    "c_explore": "Explore and discovery surface.",
    "c_federation": "Federation and mesh surface labels.",
    "c_geodata": "Map and geodata surface labels.",
    "c_host": "Live-session host controls.",
    "c_invite": "Invitation surface labels.",
    "c_judiciary": "Judiciary surface labels.",
    "c_learn": "Guided learning flyout and walkthroughs.",
    "c_legislature": "Legislature surface labels.",
    "c_legislature_workspace": "Legislature workspace tools and panels.",
    "c_live_commons": "Live commons shared room labels.",
    "c_loading": "Loading and progress messages.",
    "c_navigation": "Primary navigation labels.",
    "c_organizations": "Organizations surface labels.",
    "c_references": "Constitutional reference text and citations.",
    "c_rooms": "Live room controls: chamber, court, and floor.",
    "c_setup": "Setup wizard surface labels.",
    "c_shell": "Application shell chrome.",
    "c_shellv2": "Application shell chrome, version 2.",
    "c_surface": "Generic surface scaffolding labels.",
    "c_term_sync": "Term-sync and glossary alignment labels.",
    "c_ui": "Shared UI control labels.",
    "chrome": "Layout chrome: header, footer, and navigation.",
    "civic": "Civic pages body copy.",
    "components": "Shared component labels.",
    "dev": "Developer tools and dev-bar. Not a product surface.",
    "elections": "Elections pages: ballots, candidacy, and results.",
    "executive": "Executive pages body copy.",
    "flows": "Guided flow and journey step copy.",
    "invite": "Invitation pages body copy.",
    "judiciary": "Judiciary pages body copy.",
    "jurisdictions": "Jurisdiction pages: places and boundaries.",
    "lang": "Server-produced messages: validation text, refusals, and record labels.",
    "legislature": "Legislature pages body copy.",
    "operator": "Operator console: instance and mesh administration.",
    "organizations": "Organizations pages body copy.",
    "pages": "Top-level page copy not tied to a folder.",
    "places": "Place and jurisdiction directory labels.",
    "registry": "Surface and journey registry labels.",
    "setup": "Setup pages body copy.",
    "support": "Help and support copy.",
    "system": "System, clocks, and status pages.",
}


def _norm(s: str) -> str:
    """NFC and trim, so a normalisation difference is not read as a change."""
    return unicodedata.normalize("NFC", s).strip()


def has_translatable_content(text: str) -> bool:
    """
    True when the string has words to translate. A pure citation, ID token or
    number masks to nothing but markers, needs no AI, and would be refused on
    import as identical to English. Those are left to the ordinary machine pass.
    """
    masked, _ = tc.mask(text)
    remainder = _MARKER.sub("", masked)
    return bool(_LETTER.search(remainder))


def _decode_js(s: str) -> str:
    """Turn the registry's \\uXXXX ascii escapes into real characters."""
    return re.sub(r"\\u([0-9a-fA-F]{4})", lambda m: chr(int(m.group(1), 16)), s)


def read_registry(registry_js: Path) -> dict[str, dict]:
    """Locale metadata from the generated registry. Path-injectable for tests."""
    src = registry_js.read_text(encoding="utf-8")
    rows: dict[str, dict] = {}
    for m in re.finditer(r"\{ code: \"([\w-]+)\",(.*?)\}", src):
        code, rest = m.group(1), m.group(2)

        def field(name, default="", _rest=rest):
            mm = re.search(rf'{name}: "([^"]*)"', _rest)
            return _decode_js(mm.group(1)) if mm else default

        rows[code] = {
            "code": code,
            "name": field("name"),
            "endonym": field("endonym"),
            "dir": field("dir", "ltr"),
            "script": field("script"),
            # the translation pass target set (operator order 2026-09-14)
            "target": bool(re.search(r"target: true", rest)),
        }
    return rows


def read_glossary(glossary_file: Path, locale: str) -> dict[str, str]:
    """The locale's must-keep terms. Same shape translate_catalog injects."""
    g = tc.load(glossary_file)
    out: dict[str, str] = {}
    for term, val in g.items():
        if term.startswith("_") or not isinstance(val, dict):
            continue
        if val.get("do_not_translate"):
            out[term] = term
            continue
        rendering = (val.get("translations") or {}).get(locale)
        if rendering:
            out[term] = rendering
    return out


def used_by(ns: str, meta_en_dir: Path, js_root: Path) -> list[str]:
    """
    The pages and components that own or reference the namespace.

    Two signals, unioned:
      - the extraction manifest (meta/en/<ns>.json `file` fields), authoritative
        for the auto-extracted namespaces.
      - explicit t('<ns>.  references in resources/js, which catch the
        hand-authored content namespaces that carry no extraction manifest.
    """
    files: set[str] = set()

    manifest = tc.load(meta_en_dir / f"{ns}.json")
    for entry in manifest.values():
        if isinstance(entry, dict) and entry.get("file"):
            files.add(entry["file"].replace("\\", "/"))

    if js_root.exists():
        ref = re.compile(r"""[^\w]t\(\s*['"`]""" + re.escape(ns) + r"\.")
        for path in js_root.rglob("*"):
            if path.suffix not in (".vue", ".js", ".ts") or not path.is_file():
                continue
            if "i18n" in path.parts and "locales" in path.parts:
                continue
            try:
                text = path.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            if ref.search(text):
                files.add(str(path.relative_to(js_root.parent.parent)).replace("\\", "/"))

    return sorted(files)


def build_instructions(language: str, native: str, direction: str) -> str:
    """A complete prompt for any AI. Self-contained, no repo knowledge assumed."""
    lines = [
        f"You are translating user-interface strings for the Cosmopolitan "
        f"Governance App into {language} ({native}).",
        'Translate only the values in the "strings" object. Rules, all mandatory:',
        "1. Keep every key exactly as written. Do not add, remove, reorder, or rename keys.",
        "2. Keep every {placeholder} token unchanged. Same name, same braces.",
        "3. Never translate ID tokens such as F-LEG-017, R-09, WF-SYS-03, or CLK-06. "
        "Copy them verbatim.",
        "4. Never translate article citations such as Art. II §2 or Art. V §1–2. "
        "Copy them verbatim.",
        "5. Keep HTML tags, markdown, and vue-i18n syntax unchanged. The pipe | separates "
        "plural forms, @:key links a message, and {'{'} escapes a literal brace.",
        '6. Use the settled glossary in "_meta.glossary". When an English term there appears, '
        f"use its {language} rendering.",
        "7. Keep the tone plain, precise, and non-bureaucratic. Short sentences.",
        '8. Return only a JSON object of the same shape: {"strings": {key: value, ...}}. '
        "No prose. No code fences.",
    ]
    if direction == "rtl":
        lines.append(f"9. {language} is written right-to-left. Return natural "
                     f"right-to-left text; do not reorder the placeholders.")
    return "\n".join(lines)


def collect_pending(locale: str, ns: str, locales_dir: Path, meta_dir: Path,
                    lang_dir: Path | None = None) -> dict[str, str]:
    """The strings this locale needs for one namespace. English key -> English text."""
    en = tc.load(tc.english_source(ns, locales_dir, lang_dir))
    if not en:
        return {}
    target = tc.load(tc.locale_catalog(ns, locale, locales_dir, lang_dir))
    meta = tc.load(meta_dir / locale / f"{ns}.json")
    pending: dict[str, str] = {}
    for key, text in en.items():
        if meta.get(key, {}).get("status") in tc.PROTECTED_STATUS:
            continue
        if not has_translatable_content(text):
            continue
        absent = key not in target
        still_english = (not absent) and _norm(target.get(key, "")) == _norm(text)
        if absent or still_english:
            pending[key] = text
    return pending


def export_locale(locale: str, locales_dir: Path, meta_dir: Path, glossary_file: Path,
                  registry_js: Path, js_root: Path, out_root: Path, chunk: int,
                  only_ns: str | None = None, lang_dir: Path | None = None) -> dict:
    """Write every needed string for one locale as chunk files. Returns a summary."""
    reg = read_registry(registry_js)
    row = reg.get(locale, {"name": locale, "endonym": locale, "dir": "ltr", "script": "Latn"})
    glossary = read_glossary(glossary_file, locale)
    meta_en_dir = meta_dir / "en"

    namespaces = tc.list_namespaces(locales_dir, only_ns, lang_dir)

    out_dir = out_root / locale
    summary = {"locale": locale, "language": row["name"], "namespaces": 0,
               "strings": 0, "files": 0, "detail": []}

    for ns in namespaces:
        pending = collect_pending(locale, ns, locales_dir, meta_dir, lang_dir)
        if not pending:
            continue
        summary["namespaces"] += 1
        keys = list(pending.keys())
        surface = NAMESPACE_CONTEXT.get(ns)
        if surface is None:
            surface = f"Strings for the {ns} namespace."
            print(f"  note: no NAMESPACE_CONTEXT entry for [{ns}] - using a generic line")
        refs = used_by(ns, meta_en_dir, js_root)
        instructions = build_instructions(row["name"], row["endonym"], row["dir"])

        parts = [keys[i:i + chunk] for i in range(0, len(keys), chunk)]
        out_dir.mkdir(parents=True, exist_ok=True)
        for idx, part in enumerate(parts, 1):
            name = f"{ns}.json" if len(parts) == 1 else f"{ns}.{idx}.json"
            payload = {
                "_meta": {
                    "locale": locale,
                    "language": row["name"],
                    "native_name": row["endonym"],
                    "direction": row["dir"],
                    "namespace": ns,
                    "surface": surface,
                    "used_by": refs,
                    "glossary": glossary,
                    "instructions": instructions,
                    "string_count": len(part),
                },
                "strings": {k: pending[k] for k in part},
            }
            (out_dir / name).write_text(
                json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
            summary["files"] += 1
            summary["strings"] += len(part)
        summary["detail"].append({"namespace": ns, "strings": len(keys), "files": len(parts)})

    return summary


def print_summary(summaries: list[dict], out_root: Path) -> None:
    print(f"\nexport root: {out_root}")
    print(f"\n  {'locale':<9}{'language':<22}{'namespaces':>11}{'strings':>10}{'files':>7}")
    print(f"  {'-' * 9:<9}{'-' * 21:<22}{'-' * 11:>11}{'-' * 10:>10}{'-' * 7:>7}")
    tn = ts = tf = 0
    for s in summaries:
        print(f"  {s['locale']:<9}{s['language']:<22}{s['namespaces']:>11}"
              f"{s['strings']:>10}{s['files']:>7}")
        tn += s["namespaces"]
        ts += s["strings"]
        tf += s["files"]
    print(f"  {'-' * 9:<9}{'-' * 21:<22}{'-' * 11:>11}{'-' * 10:>10}{'-' * 7:>7}")
    print(f"  {'total':<9}{'':<22}{tn:>11}{ts:>10}{tf:>7}\n")


# ------------------------------------------------------------------ self-test
def self_test() -> int:
    import shutil
    import tempfile

    cases: list[tuple[str, bool, str]] = []

    def check(label, ok, detail=""):
        cases.append((label, ok, detail))

    tmp = Path(tempfile.mkdtemp(prefix="i18n-export-selftest-"))
    try:
        i18n = tmp / "i18n"
        loc = i18n / "locales"
        meta = i18n / "meta"
        (loc / "en").mkdir(parents=True)
        (loc / "es").mkdir(parents=True)
        (meta / "en").mkdir(parents=True)
        (meta / "es").mkdir(parents=True)
        (i18n / "glossary").mkdir(parents=True)

        # English source: one absent, one still-English, one translated, one
        # locked, one pure citation (non-translatable).
        (loc / "en" / "auth.json").write_text(json.dumps({
            "auth_login.log_in": "Log in",
            "auth_login.welcome": "Welcome back, {name}.",
            "auth_login.done": "Done",
            "auth_login.locked_term": "Residency",
            "auth_login.cite_only": "Art. II §2",
        }, ensure_ascii=False), encoding="utf-8")
        # es target: 'done' translated, 'welcome' still English, 'log_in' absent,
        # 'locked_term' present, 'cite_only' present.
        (loc / "es" / "auth.json").write_text(json.dumps({
            "auth_login.welcome": "Welcome back, {name}.",
            "auth_login.done": "Hecho",
            "auth_login.locked_term": "Residency",
            "auth_login.cite_only": "Art. II §2",
        }, ensure_ascii=False), encoding="utf-8")
        (meta / "es" / "auth.json").write_text(json.dumps({
            "auth_login.locked_term": {"status": "locked"},
        }, ensure_ascii=False), encoding="utf-8")
        (meta / "en" / "auth.json").write_text(json.dumps({
            "auth_login.log_in": {"file": "Pages/Auth/Login.vue", "line": 1, "status": "source"},
        }, ensure_ascii=False), encoding="utf-8")
        (i18n / "glossary" / "term-base.json").write_text(json.dumps({
            "_schema": {"note": "x"},
            "Residency": {"translations": {"es": "Residencia"}},
        }, ensure_ascii=False), encoding="utf-8")
        (i18n / "locales.generated.js").write_text(
            'export const LOCALES = [\n'
            '    { code: "en", name: "English", endonym: "English", dir: "ltr", script: "Latn", enabled: true },\n'
            '    { code: "es", name: "Spanish", endonym: "Espa\\u00f1ol", dir: "ltr", script: "Latn", enabled: true },\n'
            '];\n', encoding="utf-8")

        # The Laravel PHP catalog, keyed by the English string: one absent
        # (translatable), one absent with a :placeholder, one pure citation
        # (non-translatable). No es catalog yet, so the two words are pending.
        lang = tmp / "lang"
        lang.mkdir(parents=True)
        (lang / "en.json").write_text(json.dumps({
            "Art. II §2": "Art. II §2",
            "Log in": "Log in",
            "Unknown organization type [:type].": "Unknown organization type [:type].",
        }, ensure_ascii=False), encoding="utf-8")
        (lang / "es.json").write_text(json.dumps({}, ensure_ascii=False), encoding="utf-8")

        out = tmp / "out"
        summary = export_locale("es", loc, meta, i18n / "glossary" / "term-base.json",
                                i18n / "locales.generated.js", tmp / "nojs", out, chunk=250,
                                lang_dir=lang)

        f = out / "es" / "auth.json"
        check("a chunk file was written", f.exists())
        payload = json.loads(f.read_text(encoding="utf-8")) if f.exists() else {}
        strings = payload.get("strings", {})
        check("absent key exported", "auth_login.log_in" in strings, str(list(strings)))
        check("still-English key exported", "auth_login.welcome" in strings, str(list(strings)))
        check("translated key NOT exported", "auth_login.done" not in strings, str(list(strings)))
        check("locked key NOT exported", "auth_login.locked_term" not in strings, str(list(strings)))
        check("pure-citation key NOT exported", "auth_login.cite_only" not in strings, str(list(strings)))
        m = payload.get("_meta", {})
        check("_meta carries the locale", m.get("locale") == "es")
        check("_meta carries language name", m.get("language") == "Spanish")
        check("_meta carries native name", m.get("native_name") == "Español")
        check("_meta carries direction", m.get("direction") == "ltr")
        check("_meta carries namespace", m.get("namespace") == "auth")
        check("_meta carries a surface line", bool(m.get("surface")))
        check("_meta carries instructions", "placeholder" in (m.get("instructions") or ""))
        check("_meta glossary carries the term", m.get("glossary", {}).get("Residency") == "Residencia")
        check("_meta string_count matches", m.get("string_count") == len(strings))
        check("_meta used_by carries the manifest file",
              "Pages/Auth/Login.vue" in m.get("used_by", []), str(m.get("used_by")))

        # the Laravel lang namespace exports beside the JS namespaces.
        lf = out / "es" / "lang.json"
        check("lang namespace chunk written", lf.exists())
        lpayload = json.loads(lf.read_text(encoding="utf-8")) if lf.exists() else {}
        lstrings = lpayload.get("strings", {})
        check("lang plain string exported", "Log in" in lstrings, str(list(lstrings)))
        check("lang :placeholder string exported",
              "Unknown organization type [:type]." in lstrings, str(list(lstrings)))
        check("lang pure-citation string NOT exported",
              "Art. II §2" not in lstrings, str(list(lstrings)))
        check("lang _meta names the namespace", lpayload.get("_meta", {}).get("namespace") == "lang")
        check("summary counts every namespace's strings",
              summary["strings"] == len(strings) + len(lstrings),
              f"{summary['strings']} vs {len(strings)}+{len(lstrings)}")

        # chunking: a small chunk splits into numbered files.
        out2 = tmp / "out2"
        export_locale("es", loc, meta, i18n / "glossary" / "term-base.json",
                      i18n / "locales.generated.js", tmp / "nojs", out2, chunk=1,
                      only_ns="auth", lang_dir=lang)
        n_files = len(list((out2 / "es").glob("auth.*.json")))
        check("chunk=1 splits into numbered files", n_files == 2, f"got {n_files}")
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    failed = [c for c in cases if not c[1]]
    for label, ok, detail in cases:
        print(f"  {'ok  ' if ok else 'FAIL'}  {label}" + (f"   [{detail}]" if not ok and detail else ""))
    print(f"\n  {len(cases) - len(failed)}/{len(cases)} passed")
    return 1 if failed else 0


def main() -> int:
    if "--self-test" in sys.argv:
        return self_test()
    ap = argparse.ArgumentParser(description="Export the strings a locale still needs to translate.")
    ap.add_argument("--locale", required=True)
    ap.add_argument("--namespace")
    ap.add_argument("--chunk", type=int, default=250)
    ap.add_argument("--out")
    ap.add_argument("--i18n-dir")
    ap.add_argument("--lang-dir")
    args = ap.parse_args()

    i18n = Path(args.i18n_dir).resolve() if args.i18n_dir else DEFAULT_I18N
    locales_dir = i18n / "locales"
    meta_dir = i18n / "meta"
    glossary_file = i18n / "glossary" / "term-base.json"
    registry_js = i18n / "locales.generated.js"
    js_root = i18n.parent  # resources/js
    lang_dir = Path(args.lang_dir).resolve() if args.lang_dir else tc.LANG_DIR
    out_root = Path(args.out).resolve() if args.out else DEFAULT_OUT

    if not (locales_dir / "en").exists():
        print(f"no English catalogs under {locales_dir} - run scripts/i18n/extract.mjs --write first")
        return 2

    reg = read_registry(registry_js)
    if args.locale == "all":
        # THE registry's target rows (operator order 2026-09-14: the UN six,
        # Polish, Italian, Turkish and the Coalition website programme).
        locales = [c for c, r in reg.items() if r.get("target") and c != "en"]
        if not locales:
            locales = [c for c in CONFERENCE if c in reg]
    elif args.locale == "conference":
        locales = [c for c in CONFERENCE if c in reg]
    else:
        if args.locale not in reg:
            print(f"[{args.locale}] is not in the locale registry")
            return 2
        locales = [args.locale]

    summaries = []
    for locale in locales:
        summaries.append(export_locale(
            locale, locales_dir, meta_dir, glossary_file, registry_js, js_root,
            out_root, args.chunk, args.namespace, lang_dir))
    print_summary(summaries, out_root)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

"""
CGA - scripts/i18n/export_master.py
The language package export. One layout for every language, English included
(operator order 2026-09-15):

  <code>/
    README.txt            the language, the layout, the counts, the rules, the glossary
    ui/<namespace>.json   the browser catalogues (vue-i18n): key -> text, COMPLETE
    php/<code>.json       the server catalogue (Laravel __()): English line -> text, COMPLETE

COMPLETE means every key of the English source is in every file. For a target
language the value is the translation the app holds today, or the English
source where it holds none; a value still in English is exactly a string that
language does not have yet. For English (the master) every value is the source
itself, so the package reconstitutes the catalogues line for line.

The return path is scripts/i18n/import_translated.py: the whole zip, or any
one file from it, addressed to its language. Values left in English are
skipped as untranslated; a value that drops a placeholder, an ID token or a
citation is rejected with the reason.

Usage:
  python3 scripts/i18n/export_master.py --locale hi
  python3 scripts/i18n/export_master.py --locale en      # the English master
  python3 scripts/i18n/export_master.py --locale all
  python3 scripts/i18n/export_master.py --self-test

Options:
  --locale CODE     a registry locale, `all` for every registry row with target: true,
                    or `conference` for the six conference locales
  --namespace NS    restrict to one namespace (default: all)
  --out DIR         output root (default storage/app/i18n-export)
  --i18n-dir DIR    i18n root to read (default resources/js/i18n) - for tests
  --lang-dir DIR    Laravel lang root to read (default lang/) - for tests
  --self-test       run the built-in fixture test and exit
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from datetime import datetime, timezone
from pathlib import Path

# Run as `python3 scripts/i18n/export_master.py`, so this directory is on the
# path and the machine pass is importable. Reuse its rails so the export honours
# EXACTLY the same masking and status rules.
sys.path.insert(0, str(Path(__file__).resolve().parent))
import translate_catalog as tc  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_I18N = ROOT / "resources" / "js" / "i18n"

# The source locale. Exporting it yields the ENGLISH MASTER: the target IS the
# source, so every translatable key counts as still-English and every one is
# written, in exactly the layout every language package uses (operator order
# 2026-09-15: one hierarchy for every language, English included).
SOURCE_LOCALE = "en"
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
    The pages and components that own the namespace, from the extraction
    manifest (meta/en/<ns>.json `file` fields): one small JSON read.

    THE EXPORT READS FILES, NEVER WALKS THEM (operator order 2026-09-15).
    A package is English keys minus the keys the locale already holds; that
    is JSON arithmetic over the catalogues and takes seconds for any
    language. An earlier version of this function also walked every file
    under resources/js for each namespace to find t('<ns>. references, which
    turned a Hindi export into minutes through the Docker bind mount and
    past the queue timeout. js_root is kept in the signature for the
    callers and is not read.
    """
    files: set[str] = set()

    manifest = tc.load(meta_en_dir / f"{ns}.json")
    for entry in manifest.values():
        if isinstance(entry, dict) and entry.get("file"):
            files.add(entry["file"].replace("\\", "/"))

    return sorted(files)


def build_instructions(language: str, native: str, direction: str, source: bool = False) -> str:
    """A complete prompt for any AI. Self-contained, no repo knowledge assumed."""
    if source:
        head = [
            "This is the English SOURCE package of the Cosmopolitan Governance App: every "
            "translatable user-interface string, in the same file layout every language "
            "package uses.",
            "Translate only the values (the right-hand side of every key) in each ui/ and php/ "
            "file into the language you were asked for, and import the result under that "
            "language. Rules, all mandatory:",
        ]
        glossary_line = ('6. Use the settled glossary in "_meta.glossary". When an English term there '
                         "appears, use its rendering in your target language.")
    else:
        head = [
            f"You are translating user-interface strings for the Cosmopolitan "
            f"Governance App into {language} ({native}).",
            "Translate only the values (the right-hand side of every key) in each ui/ and php/ "
            "file. A value still in English is one this language does not have yet. Rules, all mandatory:",
        ]
        glossary_line = ('6. Use the settled glossary in "_meta.glossary". When an English term there appears, '
                         f"use its {language} rendering.")
    lines = [
        *head,
        "1. Keep every key exactly as written. Do not add, remove, reorder, or rename keys.",
        "2. Keep every {placeholder} token unchanged. Same name, same braces.",
        "3. Never translate ID tokens such as F-LEG-017, R-09, WF-SYS-03, or CLK-06. "
        "Copy them verbatim.",
        "4. Never translate article citations such as Art. II §2 or Art. V §1–2. "
        "Copy them verbatim.",
        "5. Keep HTML tags, markdown, and vue-i18n syntax unchanged. The pipe | separates "
        "plural forms, @:key links a message, and {'{'} escapes a literal brace.",
        glossary_line,
        "7. Keep the tone plain, precise, and non-bureaucratic. Short sentences.",
        "8. Return each file as the same JSON object, every key present, values translated. "
        "No prose. No code fences.",
    ]
    if direction == "rtl" and not source:
        lines.append(f"9. {language} is written right-to-left. Return natural "
                     f"right-to-left text; do not reorder the placeholders.")
    return "\n".join(lines)


def _package_file(ns: str, locale: str, out_dir: Path) -> Path:
    """Where a namespace lands inside the package: php/<code>.json for the Laravel lines, ui/<ns>.json otherwise."""
    if ns == tc.LANG_NS:
        return out_dir / "php" / f"{locale}.json"
    return out_dir / "ui" / f"{ns}.json"


def complete_namespace(locale: str, ns: str, locales_dir: Path, lang_dir: Path | None = None) -> tuple[dict, dict]:
    """
    The COMPLETE file for one namespace: every English key, in English order,
    with the translation the app holds or the English source. Returns
    (strings, counts) where counts = {strings, translated, to_translate, verbatim}.
    """
    en = tc.load(tc.english_source(ns, locales_dir, lang_dir))
    if not en:
        return {}, {"strings": 0, "translated": 0, "to_translate": 0, "verbatim": 0}
    target = {} if locale == SOURCE_LOCALE else tc.load(tc.locale_catalog(ns, locale, locales_dir, lang_dir))
    out: dict[str, str] = {}
    counts = {"strings": 0, "translated": 0, "to_translate": 0, "verbatim": 0}
    for key, text in en.items():
        cur = target.get(key)
        translated = isinstance(cur, str) and _norm(cur) != _norm(text) and cur.strip() != ""
        out[key] = cur if translated else text
        counts["strings"] += 1
        if locale == SOURCE_LOCALE:
            continue
        if translated:
            counts["translated"] += 1
        elif not has_translatable_content(text):
            counts["verbatim"] += 1
        else:
            counts["to_translate"] += 1
    return out, counts


def write_readme(out_dir: Path, locale: str, row: dict, glossary: dict, rows: list[dict],
                 totals: dict, instructions: str) -> None:
    source = locale == SOURCE_LOCALE
    title = (f"CGA language package: {row['name']} ({locale})"
             + (" - THE ENGLISH SOURCE MASTER" if source else f" - {row['endonym']}"))
    lines = [
        title,
        f"Exported {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M')} UTC",
        "",
        "LAYOUT (the same for every language, English included)",
        "  ui/<namespace>.json   the browser catalogues (vue-i18n): key -> text",
        f"  php/{locale}.json" + " " * max(1, 22 - len(f"php/{locale}.json")) + "the server catalogue (Laravel __()): English line -> text",
        "  README.txt            this file",
        "",
        "CONTENT",
        "  Every file is COMPLETE: every key of the English source is present, in the English order.",
    ]
    if source:
        lines += [
            "  This is the source: every value IS the English text. Nothing here is translated.",
            f"  Strings: {totals['strings']} in {totals['files']} files.",
        ]
    else:
        lines += [
            "  A value that is still English is a string this language does not have yet.",
            f"  Strings: {totals['strings']} total | translated {totals['translated']} | "
            f"to translate {totals['to_translate']} | copy verbatim {totals['verbatim']} "
            "(citations, ID tokens, placeholder-only lines)",
        ]
    lines += ["", "HOW TO TRANSLATE", *("  " + ln for ln in instructions.split("\n"))]
    if glossary and not source:
        lines += ["", "GLOSSARY (settled terms: use these renderings)"]
        lines += [f"  {term} -> {val}" for term, val in sorted(glossary.items())]
    lines += ["", "FILES"]
    for r in rows:
        c = r["counts"]
        stat = (f"strings {c['strings']}" if source else
                f"strings {c['strings']} | translated {c['translated']} | to translate {c['to_translate']} | verbatim {c['verbatim']}")
        lines.append(f"  {r['file']:<34} {stat}")
        lines.append(f"  {'':<34} {r['surface']}")
        if r["used_by"]:
            lines.append(f"  {'':<34} used by: {', '.join(r['used_by'][:8])}" + (" ..." if len(r["used_by"]) > 8 else ""))
    lines += [
        "",
        "RETURN",
        f"  Import the whole zip, or any one file from it, on /system/translations, addressed to {row['name']}.",
        "  Values left in English are skipped as untranslated. A value that drops a placeholder,",
        "  an ID token or a citation is rejected with the reason. Nothing is written before you confirm.",
        "",
    ]
    (out_dir / "README.txt").write_text("\n".join(lines), encoding="utf-8")


def export_locale(locale: str, locales_dir: Path, meta_dir: Path, glossary_file: Path,
                  registry_js: Path, js_root: Path, out_root: Path,
                  only_ns: str | None = None, lang_dir: Path | None = None) -> dict:
    """Write the complete package tree for one locale. Returns a summary."""
    reg = read_registry(registry_js)
    row = reg.get(locale, {"name": locale, "endonym": locale, "dir": "ltr", "script": "Latn"})
    glossary = read_glossary(glossary_file, locale)
    meta_en_dir = meta_dir / "en"
    source = locale == SOURCE_LOCALE

    namespaces = tc.list_namespaces(locales_dir, only_ns, lang_dir)
    out_dir = out_root / locale
    summary = {"locale": locale, "language": row["name"], "namespaces": 0, "strings": 0,
               "files": 0, "translated": 0, "to_translate": 0, "verbatim": 0, "detail": []}
    rows: list[dict] = []

    for ns in namespaces:
        strings, counts = complete_namespace(locale, ns, locales_dir, lang_dir)
        if not strings:
            continue
        surface = NAMESPACE_CONTEXT.get(ns)
        if surface is None:
            surface = ("Server text: error replies, validation, notices (Laravel __())."
                       if ns == tc.LANG_NS else f"Strings for the {ns} namespace.")
        dest = _package_file(ns, locale, out_dir)
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(json.dumps(strings, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        rel = dest.relative_to(out_dir).as_posix()
        rows.append({"file": rel, "surface": surface, "used_by": used_by(ns, meta_en_dir, js_root), "counts": counts})
        summary["namespaces"] += 1
        summary["files"] += 1
        for k in ("strings", "translated", "to_translate", "verbatim"):
            summary[k] += counts[k]
        summary["detail"].append({"namespace": ns, "file": rel, **counts})

    if rows:
        instructions = build_instructions(row["name"], row["endonym"], row["dir"], source=source)
        write_readme(out_dir, locale, row, glossary, rows, summary, instructions)
        summary["files"] += 1  # README.txt
    return summary


def print_summary(summaries: list[dict], out_root: Path) -> None:
    print(f"\nexport root: {out_root}")
    print(f"\n  {'locale':<9}{'language':<22}{'files':>6}{'strings':>9}{'translated':>12}{'to do':>8}")
    print(f"  {'-' * 9:<9}{'-' * 21:<22}{'-' * 6:>6}{'-' * 9:>9}{'-' * 12:>12}{'-' * 8:>8}")
    tf = ts = tt = td = 0
    for s in summaries:
        print(f"  {s['locale']:<9}{s['language']:<22}{s['files']:>6}{s['strings']:>9}"
              f"{s['translated']:>12}{s['to_translate']:>8}")
        tf += s["files"]
        ts += s["strings"]
        tt += s["translated"]
        td += s["to_translate"]
    print(f"  {'-' * 9:<9}{'-' * 21:<22}{'-' * 6:>6}{'-' * 9:>9}{'-' * 12:>12}{'-' * 8:>8}")
    print(f"  {'total':<9}{'':<22}{tf:>6}{ts:>9}{tt:>12}{td:>8}\n")


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
        # locked, one pure citation (copy verbatim).
        (loc / "en" / "auth.json").write_text(json.dumps({
            "auth_login.log_in": "Log in",
            "auth_login.welcome": "Welcome back, {name}.",
            "auth_login.done": "Done",
            "auth_login.locked_term": "Residency",
            "auth_login.cite_only": "Art. II §2",
        }, ensure_ascii=False), encoding="utf-8")
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

        # The Laravel PHP catalog, keyed by the English string.
        lang = tmp / "lang"
        lang.mkdir(parents=True)
        (lang / "en.json").write_text(json.dumps({
            "Log in": "Log in",
            "Art. II §2": "Art. II §2",
            "Unknown organization type [:type].": "Unknown organization type [:type].",
        }, ensure_ascii=False), encoding="utf-8")
        (lang / "es.json").write_text(json.dumps({"Log in": "Iniciar sesión"}, ensure_ascii=False), encoding="utf-8")

        out = tmp / "out"
        summary = export_locale("es", loc, meta, i18n / "glossary" / "term-base.json",
                                i18n / "locales.generated.js", tmp / "nojs", out, lang_dir=lang)

        # THE TREE
        check("ui/auth.json written", (out / "es" / "ui" / "auth.json").exists())
        check("php/es.json written", (out / "es" / "php" / "es.json").exists())
        check("README.txt written", (out / "es" / "README.txt").exists())
        check("nothing else at the top", sorted(p.name for p in (out / "es").iterdir()) == ["README.txt", "php", "ui"],
              str(sorted(p.name for p in (out / "es").iterdir())))

        # COMPLETE: every English key, English order, translation where held.
        ui = json.loads((out / "es" / "ui" / "auth.json").read_text(encoding="utf-8"))
        check("every English key present", list(ui) == ["auth_login.log_in", "auth_login.welcome", "auth_login.done",
                                                          "auth_login.locked_term", "auth_login.cite_only"], str(list(ui)))
        check("absent key carries the English", ui["auth_login.log_in"] == "Log in")
        check("still-English key carries the English", ui["auth_login.welcome"] == "Welcome back, {name}.")
        check("translated key carries the translation", ui["auth_login.done"] == "Hecho")
        check("citation carried verbatim", ui["auth_login.cite_only"] == "Art. II §2")
        php = json.loads((out / "es" / "php" / "es.json").read_text(encoding="utf-8"))
        check("php file is the complete lang catalogue", list(php) == ["Log in", "Art. II §2", "Unknown organization type [:type]."], str(list(php)))
        check("php file carries the held translation", php["Log in"] == "Iniciar sesión")
        check("php file carries English where none is held", php["Unknown organization type [:type]."] == "Unknown organization type [:type].")

        # COUNTS in the summary and the README
        check("summary counts", (summary["strings"], summary["translated"], summary["to_translate"], summary["verbatim"]) == (8, 2, 4, 2),
              str({k: summary[k] for k in ("strings", "translated", "to_translate", "verbatim")}))
        readme = (out / "es" / "README.txt").read_text(encoding="utf-8")
        check("README names the language", "Spanish (es)" in readme)
        check("README states the layout", "ui/<namespace>.json" in readme and "php/es.json" in readme)
        check("README carries the counts", "translated 2" in readme and "to translate 4" in readme and "copy verbatim 2" in readme, readme)
        check("README carries the rules", "placeholder" in readme)
        check("README carries the glossary", "Residency -> Residencia" in readme)
        check("README lists the files with their surface", "ui/auth.json" in readme and "used by: Pages/Auth/Login.vue" in readme)

        # THE ENGLISH MASTER: same tree, every value the source itself.
        out_en = tmp / "out-en"
        export_locale("en", loc, meta, i18n / "glossary" / "term-base.json",
                      i18n / "locales.generated.js", tmp / "nojs", out_en, lang_dir=lang)
        check("master has the same layout", sorted(p.name for p in (out_en / "en").iterdir()) == ["README.txt", "php", "ui"])
        en_ui = json.loads((out_en / "en" / "ui" / "auth.json").read_text(encoding="utf-8"))
        en_php = json.loads((out_en / "en" / "php" / "en.json").read_text(encoding="utf-8"))
        check("master ui file IS the English catalogue", en_ui == json.loads((loc / "en" / "auth.json").read_text(encoding="utf-8")))
        check("master php file IS lang/en.json", en_php == json.loads((lang / "en.json").read_text(encoding="utf-8")))
        check("master README names the source", "THE ENGLISH SOURCE MASTER" in (out_en / "en" / "README.txt").read_text(encoding="utf-8"))

        # --namespace restricts the tree.
        out2 = tmp / "out2"
        export_locale("es", loc, meta, i18n / "glossary" / "term-base.json",
                      i18n / "locales.generated.js", tmp / "nojs", out2, only_ns="auth", lang_dir=lang)
        check("--namespace writes only that file", not (out2 / "es" / "php").exists() and (out2 / "es" / "ui" / "auth.json").exists())
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
    ap = argparse.ArgumentParser(description="Export the complete language package for a locale.")
    ap.add_argument("--locale", required=True)
    ap.add_argument("--namespace")
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
            out_root, args.namespace, lang_dir))
    print_summary(summaries, out_root)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

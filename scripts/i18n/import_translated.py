#!/usr/bin/env python3
"""
CGA - scripts/i18n/import_translated.py
The return path for the master export. Reads translated export files and writes
the accepted strings into the locale catalogs, under the same QA the machine
pass uses.

WHAT IT ACCEPTS
  The package tree export_master.py writes (operator order 2026-09-15, one
  layout for every language): ui/<namespace>.json and php/<code>.json, each a
  plain key -> text map, complete. The whole tree, or any one file from it,
  with --locale naming the language (php/<code>.json also carries it in its
  name). The retired chunk shape (a "_meta" block plus a "strings" map) is
  still read, so an old file imports too.

WHAT IT SKIPS, per string, counted as unchanged (not an error):
  - a value identical to the English source: the translator left it, or it is
    a citation / ID token / placeholder-only line that is copied verbatim

WHAT IT REFUSES, per string, and reports with a reason:
  - a key that does not exist in the English namespace
  - a locked or human-reviewed string (status lives in the meta tree)
  - a value that fails placeholder / ID token / citation parity (the same QA
    the machine pass runs)
  - a value vue-i18n cannot compile

WHAT IT WRITES
  Accepted strings go into resources/js/i18n/locales/<locale>/<namespace>.json,
  creating the file when absent and preserving the English key order. Each
  accepted string is marked in the meta tree as an AI first pass with the source
  file name. Nothing else in the catalog is touched.

IDEMPOTENT. Re-importing the same translated files reaches the same catalog
state: an already-written translation passes the identical-to-English and QA
gates again and is rewritten unchanged.

Usage:
  python3 scripts/i18n/import_translated.py storage/app/i18n-export/hi --locale hi
  python3 scripts/i18n/import_translated.py hi/ui/auth.json --locale hi --dry-run
  python3 scripts/i18n/import_translated.py --self-test

Options:
  --locale CODE     the language the files are translated into (required for
                    ui/ files; php/<code>.json and the retired _meta shape carry it)
  --dry-run         validate and report; write nothing
  --i18n-dir DIR    i18n root to write into (default resources/js/i18n)
  --self-test       run the built-in fixture test and exit
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import translate_catalog as tc  # noqa: E402
from export_master import read_registry  # noqa: E402

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_I18N = ROOT / "resources" / "js" / "i18n"

_LITERAL = re.compile(r"\{'[^']*'\}")
_INTERP = re.compile(r"\{\s*[A-Za-z_$][\w$]*\s*\}|\{\s*\d+\s*\}")
_DANGLING_LINK = re.compile(r"@[.:]\s*(?![\w(])")


def _norm(s: str) -> str:
    return unicodedata.normalize("NFC", s).strip()


def compiles_vue_i18n(text: str) -> bool:
    """
    A Python approximation of the vue-i18n message compiler's structural gate.

    The authoritative compile is check.mjs C5 (@intlify/message-compiler), which
    the operator runs after an import. This catches the failures a translation
    can introduce on this side: a stray or empty brace, or a dangling @: link.
    Placeholder parity is already enforced by qa(), so valid interpolations are
    intact; what remains to test is that no BROKEN brace slipped in.
    """
    stripped = _LITERAL.sub("", text)
    stripped = _INTERP.sub("", stripped)
    if "{" in stripped or "}" in stripped:
        return False
    if _DANGLING_LINK.search(text):
        return False
    return True


UNCHANGED = "unchanged"  # not a rejection: the value is still the English source


def _validate(key: str, value: str, en: dict, meta: dict, script: str) -> str | None:
    """Return a rejection reason, UNCHANGED for a value still in English, or None when admissible."""
    if key not in en:
        return "key not in the English namespace"
    if not isinstance(value, str) or not value.strip():
        return "empty value"
    if _norm(value) == _norm(en[key]):
        return UNCHANGED
    if meta.get(key, {}).get("status") in tc.PROTECTED_STATUS:
        return f"locked or human-reviewed ({meta[key]['status']})"
    reason = tc.qa(en[key], value, script)
    if reason:
        return reason
    if not compiles_vue_i18n(value):
        return "vue-i18n cannot compile"
    return None


def _ordered_dump(path: Path, en: dict, target: dict) -> None:
    """Write the target catalog in the English key order, extras appended."""
    ordered = {k: target[k] for k in en if k in target}
    for k in target:
        if k not in ordered:
            ordered[k] = target[k]
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(ordered, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def _meta_dump(path: Path, meta: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(meta, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def gather_files(target: Path) -> list[Path]:
    if target.is_dir():
        return sorted(p for p in target.rglob("*.json"))
    return [target]


def identify(path: Path, payload, force_locale: str | None) -> tuple[str | None, str | None, dict | None, str | None]:
    """
    (locale, namespace, strings, problem) for one file. The package tree:
    ui/<ns>.json (locale from --locale) and php/<code>.json (locale from the
    file name unless forced). The retired chunk shape carries both in _meta.
    """
    if not isinstance(payload, dict):
        return None, None, None, "not a JSON object"
    if isinstance(payload.get("strings"), dict) and isinstance(payload.get("_meta"), dict):
        m = payload["_meta"]
        return force_locale or m.get("locale"), m.get("namespace"), payload["strings"], None
    parent = path.parent.name
    if parent == "ui":
        return force_locale, path.stem, payload, (None if force_locale else "pass --locale for a ui/ file")
    if parent == "php":
        return force_locale or path.stem, tc.LANG_NS, payload, None
    return None, None, None, "not a package file (expected ui/<namespace>.json or php/<code>.json)"


def import_files(paths: list[Path], locales_dir: Path, meta_dir: Path, registry_js: Path,
                 force_locale: str | None, dry_run: bool, lang_dir: Path | None = None) -> dict:
    reg = read_registry(registry_js)
    totals = {"accepted": 0, "rejected": 0, "unchanged": 0, "files": 0, "rejections": []}

    # One file per (locale, namespace): each catalog is read and written once.
    for path in paths:
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            print(f"  skip {path.name}: not readable JSON ({exc})")
            continue
        locale, ns, strings, problem = identify(path, payload, force_locale)
        if problem or not locale or not ns:
            print(f"  skip {path.name}: {problem or 'locale and namespace unknown'}")
            continue
        if locale == "en":
            print(f"  skip {path.name}: English is the source, never imported")
            continue
        totals["files"] += 1

        script = reg.get(locale, {}).get("script") or "Latn"
        en = tc.load(tc.english_source(ns, locales_dir, lang_dir))
        if not en:
            print(f"  skip {path.name}: no English namespace [{ns}]")
            continue
        tgt_path = tc.locale_catalog(ns, locale, locales_dir, lang_dir)
        meta_path = meta_dir / locale / f"{ns}.json"
        target = tc.load(tgt_path)
        meta = tc.load(meta_path)

        accepted = 0
        unchanged = 0
        for key, value in strings.items():
            reason = _validate(key, value, en, meta, script)
            if reason is UNCHANGED:
                unchanged += 1
                totals["unchanged"] += 1
                continue
            if reason:
                totals["rejected"] += 1
                totals["rejections"].append((locale, ns, key, reason))
                continue
            target[key] = value.strip()
            meta.setdefault(key, {})
            meta[key].update({"status": "machine", "provider": "ai-first-pass",
                              "source": path.name})
            accepted += 1
            totals["accepted"] += 1

        print(f"  {path.parent.name}/{path.name}: {accepted} accepted, {unchanged} unchanged, "
              f"{len(strings) - accepted - unchanged} rejected  ({locale}/{ns})")
        if accepted and not dry_run:
            _ordered_dump(tgt_path, en, target)
            _meta_dump(meta_path, meta)

    return totals


def report(totals: dict, dry_run: bool) -> None:
    if totals["rejections"]:
        print("\n  rejections:")
        for locale, ns, key, reason in totals["rejections"]:
            print(f"    {locale}/{ns}  {key}: {reason}")
    print(f"\n  files {totals['files']}   accepted {totals['accepted']}   "
          f"rejected {totals['rejected']}   unchanged {totals['unchanged']}"
          + ("   [DRY RUN - nothing written]" if dry_run else ""))
    if totals["accepted"] and not dry_run:
        print("\n  re-run node scripts/i18n/check.mjs (C5 compiles) and "
              "tests/js/i18nCoverage.test.mjs to see coverage move")


# ------------------------------------------------------------------ self-test
def self_test() -> int:
    import shutil
    import tempfile

    import export_master as em

    cases: list[tuple[str, bool, str]] = []

    def check(label, ok, detail=""):
        cases.append((label, ok, detail))

    tmp = Path(tempfile.mkdtemp(prefix="i18n-import-selftest-"))
    try:
        i18n = tmp / "i18n"
        loc = i18n / "locales"
        meta = i18n / "meta"
        (loc / "en").mkdir(parents=True)
        (loc / "es").mkdir(parents=True)
        (meta / "en").mkdir(parents=True)
        (meta / "es").mkdir(parents=True)
        (i18n / "glossary").mkdir(parents=True)

        (loc / "en" / "auth.json").write_text(json.dumps({
            "auth_login.log_in": "Log in",
            "auth_login.welcome": "Welcome back, {name}.",
            "auth_login.locked_term": "Residency",
            "auth_login.cite_only": "Art. II §2",
        }, ensure_ascii=False), encoding="utf-8")
        (loc / "es" / "auth.json").write_text(json.dumps({
            "auth_login.locked_term": "Residency",
        }, ensure_ascii=False), encoding="utf-8")
        (meta / "es" / "auth.json").write_text(json.dumps({
            "auth_login.locked_term": {"status": "locked"},
        }, ensure_ascii=False), encoding="utf-8")
        (i18n / "glossary" / "term-base.json").write_text(json.dumps({"_schema": {}},
                                                                     ensure_ascii=False), encoding="utf-8")
        (i18n / "locales.generated.js").write_text(
            'export const LOCALES = [\n'
            '    { code: "en", name: "English", endonym: "English", dir: "ltr", script: "Latn", enabled: true },\n'
            '    { code: "es", name: "Spanish", endonym: "Espa\\u00f1ol", dir: "ltr", script: "Latn", enabled: true },\n'
            '];\n', encoding="utf-8")

        # The Laravel PHP catalog, keyed by the English string. No es catalog yet.
        lang = tmp / "lang"
        lang.mkdir(parents=True)
        (lang / "en.json").write_text(json.dumps({
            "Log in": "Log in",
            "Art. II §2": "Art. II §2",
        }, ensure_ascii=False), encoding="utf-8")

        # 1) export the package tree, 2) translate some values in place, 3) import the tree.
        out = tmp / "out"
        em.export_locale("es", loc, meta, i18n / "glossary" / "term-base.json",
                         i18n / "locales.generated.js", tmp / "nojs", out, lang_dir=lang)
        ui = out / "es" / "ui" / "auth.json"
        php = out / "es" / "php" / "es.json"
        check("export produced the tree", ui.exists() and php.exists() and (out / "es" / "README.txt").exists())

        tree = json.loads(ui.read_text(encoding="utf-8"))
        tree["auth_login.log_in"] = "Iniciar sesión"
        tree["auth_login.welcome"] = "Bienvenido de nuevo, {name}."
        # locked_term and cite_only stay English: copied verbatim, never written.
        ui.write_text(json.dumps(tree, ensure_ascii=False), encoding="utf-8")
        ptree = json.loads(php.read_text(encoding="utf-8"))
        ptree["Log in"] = "Iniciar sesión"
        php.write_text(json.dumps(ptree, ensure_ascii=False), encoding="utf-8")

        totals = import_files(gather_files(out / "es"), loc, meta, i18n / "locales.generated.js", "es", False,
                              lang_dir=lang)
        check("two files imported", totals["files"] == 2, str(totals))
        check("three translations accepted", totals["accepted"] == 3, str(totals))
        check("English-left values counted unchanged, not rejected",
              totals["unchanged"] == 3 and totals["rejected"] == 0, str(totals))
        tgt = json.loads((loc / "es" / "auth.json").read_text(encoding="utf-8"))
        check("catalog carries the translation", tgt.get("auth_login.log_in") == "Iniciar sesión")
        check("catalog preserves English key order",
              list(tgt.keys())[:2] == ["auth_login.log_in", "auth_login.welcome"], str(list(tgt.keys())))
        check("untranslated key not written", "auth_login.cite_only" not in tgt)
        mtgt = json.loads((meta / "es" / "auth.json").read_text(encoding="utf-8"))
        check("meta marks the AI first pass",
              mtgt.get("auth_login.log_in", {}).get("provider") == "ai-first-pass", str(mtgt.get("auth_login.log_in")))
        check("meta records the source file name",
              mtgt.get("auth_login.log_in", {}).get("source") == "auth.json")
        check("meta leaves the locked string untouched",
              mtgt.get("auth_login.locked_term", {}).get("status") == "locked")
        ltgt = json.loads((lang / "es.json").read_text(encoding="utf-8"))
        check("php/es.json lands in lang/es.json", ltgt.get("Log in") == "Iniciar sesión", str(ltgt))
        check("no lang catalog leaked into locales/es", not (loc / "es" / "lang.json").exists())

        # idempotent: a second import of the same tree changes nothing.
        before = (loc / "es" / "auth.json").read_text(encoding="utf-8")
        import_files(gather_files(out / "es"), loc, meta, i18n / "locales.generated.js", "es", False, lang_dir=lang)
        after = (loc / "es" / "auth.json").read_text(encoding="utf-8")
        check("second import is idempotent", before == after)

        # a single ui/ file without --locale is refused, not guessed.
        t1 = import_files([ui], loc, meta, i18n / "locales.generated.js", None, True, lang_dir=lang)
        check("ui file without --locale is skipped", t1["files"] == 0, str(t1))
        # php/<code>.json carries its locale in the name.
        t1b = import_files([php], loc, meta, i18n / "locales.generated.js", None, True, lang_dir=lang)
        check("php file names its own locale", t1b["files"] == 1 and t1b["accepted"] == 1, str(t1b))
        # the English master is never imported.
        (out / "en" / "ui").mkdir(parents=True)
        (out / "en" / "ui" / "auth.json").write_text("{}", encoding="utf-8")
        t1c = import_files([out / "en" / "ui" / "auth.json"], loc, meta, i18n / "locales.generated.js", "en", True, lang_dir=lang)
        check("English is refused as an import target", t1c["files"] == 0, str(t1c))

        # rejected-placeholder case: a value that drops the {name} token (tree file).
        bad_dir = tmp / "bad" / "ui"
        bad_dir.mkdir(parents=True)
        (bad_dir / "auth.json").write_text(json.dumps({"auth_login.welcome": "Bienvenido de nuevo."}, ensure_ascii=False), encoding="utf-8")
        t2 = import_files([bad_dir / "auth.json"], loc, meta, i18n / "locales.generated.js", "es", True)
        check("dropped placeholder is rejected", t2["accepted"] == 0 and t2["rejected"] == 1, str(t2))
        check("rejection names the placeholder reason",
              any("placeholder" in r[3] for r in t2["rejections"]), str(t2["rejections"]))

        # locked-string case: a changed value aimed at a locked key is refused.
        lk_dir = tmp / "locked" / "ui"
        lk_dir.mkdir(parents=True)
        (lk_dir / "auth.json").write_text(json.dumps({"auth_login.locked_term": "Residencia"}, ensure_ascii=False), encoding="utf-8")
        t3 = import_files([lk_dir / "auth.json"], loc, meta, i18n / "locales.generated.js", "es", True)
        check("locked string is refused", t3["accepted"] == 0 and t3["rejected"] == 1, str(t3))
        check("rejection names the locked reason",
              any("locked" in r[3] for r in t3["rejections"]), str(t3["rejections"]))

        # unknown-key case.
        uk_dir = tmp / "unknown" / "ui"
        uk_dir.mkdir(parents=True)
        (uk_dir / "auth.json").write_text(json.dumps({"auth_login.nope": "Nada"}, ensure_ascii=False), encoding="utf-8")
        t4 = import_files([uk_dir / "auth.json"], loc, meta, i18n / "locales.generated.js", "es", True)
        check("unknown key is refused", t4["accepted"] == 0 and t4["rejected"] == 1, str(t4))

        # the retired chunk shape still imports.
        old = tmp / "old.json"
        old.write_text(json.dumps({
            "_meta": {"locale": "es", "namespace": "auth"},
            "strings": {"auth_login.log_in": "Iniciar sesión", "auth_login.cite_only": "Art. II §2"},
        }, ensure_ascii=False), encoding="utf-8")
        t5 = import_files([old], loc, meta, i18n / "locales.generated.js", None, True)
        check("retired chunk shape accepted", t5["accepted"] == 1 and t5["unchanged"] == 1 and t5["rejected"] == 0, str(t5))

        # a stray file is skipped with a reason, never guessed.
        stray = tmp / "stray.json"
        stray.write_text("{}", encoding="utf-8")
        t6 = import_files([stray], loc, meta, i18n / "locales.generated.js", "es", True)
        check("stray file skipped", t6["files"] == 0, str(t6))

        # compile guard: a stray brace is refused.
        check("stray brace does not compile", not compiles_vue_i18n("Hola {name"))
        check("escaped literal compiles", compiles_vue_i18n("Use {'{'} to open"))
        check("valid interpolation compiles", compiles_vue_i18n("Hola {name}, {count} nuevos"))
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
    ap = argparse.ArgumentParser(description="Import translated export files into the catalogs.")
    ap.add_argument("target", help="a language package tree, or one file from it")
    ap.add_argument("--locale", help="the language the files are translated into")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--i18n-dir")
    ap.add_argument("--lang-dir")
    args = ap.parse_args()

    i18n = Path(args.i18n_dir).resolve() if args.i18n_dir else DEFAULT_I18N
    locales_dir = i18n / "locales"
    meta_dir = i18n / "meta"
    registry_js = i18n / "locales.generated.js"
    lang_dir = Path(args.lang_dir).resolve() if args.lang_dir else tc.LANG_DIR

    target = Path(args.target).resolve()
    if not target.exists():
        print(f"no such file or directory: {target}")
        return 2
    paths = gather_files(target)
    if not paths:
        print(f"no .json files under {target}")
        return 2

    print(f"\nimport {len(paths)} file(s){'  [DRY RUN]' if args.dry_run else ''}")
    totals = import_files(paths, locales_dir, meta_dir, registry_js, args.locale, args.dry_run,
                          lang_dir)
    report(totals, args.dry_run)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""
CGA - scripts/i18n/import_translated.py
The return path for the master export. Reads translated export files and writes
the accepted strings into the locale catalogs, under the same QA the machine
pass uses.

WHAT IT ACCEPTS
  A file (or directory of files) in the export_master.py shape: a "_meta" block
  naming the locale and namespace, and a "strings" map of key -> translated
  value.

WHAT IT REFUSES, per string, and reports with a reason:
  - a key that does not exist in the English namespace
  - a locked or human-reviewed string (status lives in the meta tree)
  - a value identical to the English source (untranslated)
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
  python3 scripts/i18n/import_translated.py storage/app/i18n-export/es
  python3 scripts/i18n/import_translated.py translated_auth.json --dry-run
  python3 scripts/i18n/import_translated.py some_dir --locale es
  python3 scripts/i18n/import_translated.py --self-test

Options:
  --locale CODE     force the locale (default: read from each file's _meta)
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


def _validate(key: str, value: str, en: dict, meta: dict, script: str) -> str | None:
    """Return a rejection reason, or None when the string is admissible."""
    if key not in en:
        return "key not in the English namespace"
    if meta.get(key, {}).get("status") in tc.PROTECTED_STATUS:
        return f"locked or human-reviewed ({meta[key]['status']})"
    if not isinstance(value, str) or not value.strip():
        return "empty value"
    if _norm(value) == _norm(en[key]):
        return "identical to the English source"
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


def import_files(paths: list[Path], locales_dir: Path, meta_dir: Path, registry_js: Path,
                 force_locale: str | None, dry_run: bool) -> dict:
    reg = read_registry(registry_js)
    totals = {"accepted": 0, "rejected": 0, "files": 0, "rejections": []}

    # Group edits per (locale, namespace) so each catalog is read and written once.
    for path in paths:
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            print(f"  skip {path.name}: not readable JSON ({exc})")
            continue
        meta_block = payload.get("_meta", {})
        strings = payload.get("strings", {})
        if not isinstance(strings, dict):
            print(f"  skip {path.name}: no 'strings' object")
            continue
        locale = force_locale or meta_block.get("locale")
        ns = meta_block.get("namespace")
        if not locale or not ns:
            print(f"  skip {path.name}: _meta must carry locale and namespace")
            continue
        totals["files"] += 1

        script = reg.get(locale, {}).get("script") or "Latn"
        en = tc.load(locales_dir / "en" / f"{ns}.json")
        if not en:
            print(f"  skip {path.name}: no English namespace [{ns}]")
            continue
        tgt_path = locales_dir / locale / f"{ns}.json"
        meta_path = meta_dir / locale / f"{ns}.json"
        target = tc.load(tgt_path)
        meta = tc.load(meta_path)

        accepted = 0
        for key, value in strings.items():
            reason = _validate(key, value, en, meta, script)
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

        print(f"  {path.name}: {accepted} accepted, "
              f"{len(strings) - accepted} rejected  ({locale}/{ns})")
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
          f"rejected {totals['rejected']}"
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

        # 1) export the fixture, 2) stub-translate the values, 3) import.
        out = tmp / "out"
        em.export_locale("es", loc, meta, i18n / "glossary" / "term-base.json",
                         i18n / "locales.generated.js", tmp / "nojs", out, chunk=250)
        exp = out / "es" / "auth.json"
        check("export produced a file for import", exp.exists())

        payload = json.loads(exp.read_text(encoding="utf-8"))
        # A good translation of each exported string (keeps the placeholder).
        good = {
            "auth_login.log_in": "Iniciar sesión",
            "auth_login.welcome": "Bienvenido de nuevo, {name}.",
        }
        payload["strings"] = {k: good[k] for k in payload["strings"] if k in good}
        exp.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")

        totals = import_files([exp], loc, meta, i18n / "locales.generated.js", None, False)
        check("both good strings accepted", totals["accepted"] == 2, str(totals))
        tgt = json.loads((loc / "es" / "auth.json").read_text(encoding="utf-8"))
        check("catalog carries the translation", tgt.get("auth_login.log_in") == "Iniciar sesión")
        check("catalog preserves English key order",
              list(tgt.keys())[:2] == ["auth_login.log_in", "auth_login.welcome"], str(list(tgt.keys())))
        mtgt = json.loads((meta / "es" / "auth.json").read_text(encoding="utf-8"))
        check("meta marks the AI first pass",
              mtgt.get("auth_login.log_in", {}).get("provider") == "ai-first-pass", str(mtgt.get("auth_login.log_in")))
        check("meta records the source file name",
              mtgt.get("auth_login.log_in", {}).get("source") == exp.name)
        check("meta leaves the locked string untouched",
              mtgt.get("auth_login.locked_term", {}).get("status") == "locked")

        # idempotent: a second import of the same file changes nothing.
        before = (loc / "es" / "auth.json").read_text(encoding="utf-8")
        import_files([exp], loc, meta, i18n / "locales.generated.js", None, False)
        after = (loc / "es" / "auth.json").read_text(encoding="utf-8")
        check("second import is idempotent", before == after)

        # rejected-placeholder case: a value that drops the {name} token.
        bad = tmp / "bad.json"
        bad.write_text(json.dumps({
            "_meta": {"locale": "es", "namespace": "auth"},
            "strings": {"auth_login.welcome": "Bienvenido de nuevo."},
        }, ensure_ascii=False), encoding="utf-8")
        t2 = import_files([bad], loc, meta, i18n / "locales.generated.js", None, True)
        check("dropped placeholder is rejected", t2["accepted"] == 0 and t2["rejected"] == 1, str(t2))
        check("rejection names the placeholder reason",
              any("placeholder" in r[3] for r in t2["rejections"]), str(t2["rejections"]))

        # locked-string case: a value aimed at a locked key is refused.
        lk = tmp / "locked.json"
        lk.write_text(json.dumps({
            "_meta": {"locale": "es", "namespace": "auth"},
            "strings": {"auth_login.locked_term": "Residencia"},
        }, ensure_ascii=False), encoding="utf-8")
        t3 = import_files([lk], loc, meta, i18n / "locales.generated.js", None, True)
        check("locked string is refused", t3["accepted"] == 0 and t3["rejected"] == 1, str(t3))
        check("rejection names the locked reason",
              any("locked" in r[3] for r in t3["rejections"]), str(t3["rejections"]))

        # unknown-key case.
        uk = tmp / "unknown.json"
        uk.write_text(json.dumps({
            "_meta": {"locale": "es", "namespace": "auth"},
            "strings": {"auth_login.nope": "Nada"},
        }, ensure_ascii=False), encoding="utf-8")
        t4 = import_files([uk], loc, meta, i18n / "locales.generated.js", None, True)
        check("unknown key is refused", t4["accepted"] == 0 and t4["rejected"] == 1, str(t4))

        # identical-to-English case.
        idf = tmp / "ident.json"
        idf.write_text(json.dumps({
            "_meta": {"locale": "es", "namespace": "auth"},
            "strings": {"auth_login.log_in": "Log in"},
        }, ensure_ascii=False), encoding="utf-8")
        t5 = import_files([idf], loc, meta, i18n / "locales.generated.js", None, True)
        check("identical-to-English is refused",
              t5["accepted"] == 0 and any("identical" in r[3] for r in t5["rejections"]), str(t5))

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
    ap.add_argument("target", help="a translated export file, or a directory of them")
    ap.add_argument("--locale")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--i18n-dir")
    args = ap.parse_args()

    i18n = Path(args.i18n_dir).resolve() if args.i18n_dir else DEFAULT_I18N
    locales_dir = i18n / "locales"
    meta_dir = i18n / "meta"
    registry_js = i18n / "locales.generated.js"

    target = Path(args.target).resolve()
    if not target.exists():
        print(f"no such file or directory: {target}")
        return 2
    paths = gather_files(target)
    if not paths:
        print(f"no .json files under {target}")
        return 2

    print(f"\nimport {len(paths)} file(s){'  [DRY RUN]' if args.dry_run else ''}")
    totals = import_files(paths, locales_dir, meta_dir, registry_js, args.locale, args.dry_run)
    report(totals, args.dry_run)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

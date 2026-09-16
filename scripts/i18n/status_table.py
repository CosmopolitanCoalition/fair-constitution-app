#!/usr/bin/env python3
"""
CGA - scripts/i18n/status_table.py
Writes docs/i18n/TRANSLATION_STATUS.md: one row per language with what the
machine gate measured (coverage), what the reading review found (grade), and
whether a native reader has been through it. Re-run after a translation pass,
a fill-in, a review, or a registry change:

  python3 scripts/i18n/status_table.py

Inputs (all in the repo):
  resources/js/i18n/locales.generated.js   the registry (name, endonym, enabled)
  resources/js/i18n/coverage.json          the gate's coverage artifact (scripts/i18n/check.mjs)
  docs/audits/<date>/L10N_SPOTCHECK.md     the latest reading-review table (grade per language)
  docs/i18n/native_reviews.json            optional: {"code": "note"} for languages a native reader signed off
"""
from __future__ import annotations

import glob
import json
import re
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REG = ROOT / "resources" / "js" / "i18n" / "locales.generated.js"
COV = ROOT / "resources" / "js" / "i18n" / "coverage.json"
OUT = ROOT / "docs" / "i18n" / "TRANSLATION_STATUS.md"
NATIVE = ROOT / "docs" / "i18n" / "native_reviews.json"


def registry() -> list[dict]:
    src = REG.read_text(encoding="utf-8")
    rows = []
    for m in re.finditer(r"\{ code: \"([\w-]+)\",(.*?)\}", src):
        body = m.group(2)

        def f(k):
            mm = re.search(k + r': "([^"]*)"', body)
            return mm.group(1).encode("utf-8").decode("unicode_escape") if mm else ""

        rows.append({"code": m.group(1), "name": f("name"), "endonym": f("endonym"),
                     "enabled": "enabled: true" in body, "target": "target: true" in body})
    return rows


def latest_spotcheck() -> tuple[dict, str]:
    files = sorted(glob.glob(str(ROOT / "docs" / "audits" / "*" / "L10N_SPOTCHECK.md")))
    if not files:
        return {}, ""
    grades = {}
    for line in Path(files[-1]).read_text(encoding="utf-8").splitlines():
        m = re.match(r"\| .*? \((\S+)\) \| (\w+) \| (\d+) \| (\d+) \| (\d+) \| ([^|]+) \| (\d+) \| ([ABC]) \|", line)
        if m:
            code, tier, read, ok, pct, issues, wrong, grade = m.groups()
            grades[code] = {"tier": tier, "read": int(read), "ok": int(ok), "pct": int(pct), "wrong": int(wrong), "grade": grade}
    return grades, Path(files[-1]).relative_to(ROOT).as_posix()


def main() -> int:
    reg = registry()
    cov = {l["locale"]: l for l in json.loads(COV.read_text(encoding="utf-8")).get("locales", [])} if COV.exists() else {}
    grades, spot_path = latest_spotcheck()
    native = json.loads(NATIVE.read_text(encoding="utf-8")) if NATIVE.exists() else {}

    rows = [r for r in reg if r["code"] != "en" and r["target"]]
    order = {"A": 0, "B": 1, "C": 2}
    rows.sort(key=lambda r: (order.get(grades.get(r["code"], {}).get("grade", "C"), 3), -grades.get(r["code"], {}).get("pct", 0), r["name"]))

    lines = [
        "# Translation status",
        "",
        f"Generated {date.today().isoformat()} by `python3 scripts/i18n/status_table.py`. English is the source language; every other language below is a machine draft unless the last column says otherwise.",
        "",
        "How to read a row:",
        "",
        "- **Enabled** means the language is in the app's switcher today. All target languages are enabled (operator ruling 2026-09-16): the catalogues are code and ship on by default.",
        "- **Coverage** is what the machine gate measured (`scripts/i18n/check.mjs`): the share of English strings that have a value in this language. A missing string shows in English.",
        "- **Reading review** is a sample of the drafts read by a reviewer model with a second model checking its findings: sample size, share judged correct, strings that would mislead a user, and a grade (A = 90 percent clean and no misleading string, B = 70 percent and at most one, C = below). It measures quality; it does not review every string. Method: [docs/i18n/METHODS.md](METHODS.md).",
        "- **Native reader** is whether a fluent human has signed off. The queue for that is the app's translation board (`/system/translations`), open to readers of each language.",
        "",
        f"Latest reading review: `{spot_path}`." if spot_path else "No reading review on file yet.",
        "",
        "| Language | Code | Enabled | Coverage | Sample | Clean | Misleading | Grade | Native reader |",
        "|---|---|---|---|---|---|---|---|---|",
    ]
    counts = {"A": 0, "B": 0, "C": 0, "-": 0}
    for r in rows:
        c = cov.get(r["code"], {})
        g = grades.get(r["code"])
        grade = g["grade"] if g else "-"
        counts[grade] += 1
        lines.append("| {name} ({endonym}) | {code} | {en} | {cov} | {sample} | {clean} | {wrong} | {grade} | {native} |".format(
            name=r["name"], endonym=r["endonym"], code=r["code"], en="yes" if r["enabled"] else "no",
            cov=f"{c.get('pct', 0):.1f}%" if c else "not measured",
            sample=g["read"] if g else "-", clean=f"{g['pct']}%" if g else "-", wrong=g["wrong"] if g else "-",
            grade=grade, native=native.get(r["code"], "not yet")))
    lines += ["", f"Languages: {len(rows)} targets. Grades: A {counts['A']}, B {counts['B']}, C {counts['C']}" + (f", not yet reviewed {counts['-']}" if counts['-'] else "") + ".",
              "", "A C grade means the sample read badly, not that the language is absent: every string is present as a draft, and a native reader can correct it in the app. C-grade languages are the first to need one."]
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"wrote {OUT.relative_to(ROOT).as_posix()}: {len(rows)} languages, grades {counts}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

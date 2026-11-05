#!/usr/bin/env python3
"""Secret scan for the repository (W-0488; operator rule 2026-11-03: only code goes to GitHub).

Modes
  --staged    scan the staged diff (pre-commit hook)
  --tree      scan every tracked file at HEAD (CI)
  --history   scan every added line in all commits (audit; bounded, streaming)

The scan reports file, line and rule name. It never prints a matched value.
Known development defaults are listed in scripts/ops/secret_scan_baseline.txt as
"<rule>|<path>" lines with a reason after '#'. A baseline entry covers one rule in one file.

Exit code 0 = clean, 1 = findings, 2 = usage error.
"""
import argparse
import os
import re
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
BASELINE = os.path.join(ROOT, "scripts", "ops", "secret_scan_baseline.txt")

PLACEHOLDER = re.compile(
    r"(\$\{[^}]*\}|\{\{[^}]*\}\}|<[A-Za-z_ -]+>|CHANGE_?ME|REPLACE_?ME|__[A-Z0-9_]+__|"
    r"example|placeholder|your[-_ ]|xxxx|null|false|true|env\()",
    re.I,
)

RULES = [
    ("private-key-block", re.compile(r"-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----")),
    ("github-token", re.compile(r"\bgh[pousr]_[A-Za-z0-9]{30,}\b")),
    ("slack-token", re.compile(r"\bxox[abprs]-[A-Za-z0-9-]{10,}\b")),
    ("aws-access-key", re.compile(r"\bAKIA[0-9A-Z]{16}\b")),
    ("anthropic-key", re.compile(r"\bsk-ant-[A-Za-z0-9_-]{20,}\b")),
    (
        "assigned-secret",
        re.compile(
            r"""(?ix)
            \b[\w.-]*(?:secret|token|password|passwd|api[_-]?key|[a-z]_key|encryption|private[_-]?key)[\w.-]*
            ["']?\s*(?:=>|[:=])\s*["']?(?:base64:)?
            (?P<v>[A-Za-z0-9+/_\-=]{20,})["']?[\s,);]*$""",
        ),
    ),
    (
        "db-url-password",
        re.compile(r"[a-z][a-z0-9+.-]*://[^\s:/@]+:(?P<v>[^\s@/]{4,})@[^\s/]+"),
    ),
]

SKIP_SUFFIX = (
    ".png", ".jpg", ".jpeg", ".gif", ".webp", ".ico", ".pdf", ".woff", ".woff2", ".ttf",
    ".mp4", ".webm", ".zip", ".gz", ".drawio", ".lock", ".svg", ".mjs.map", ".map",
)
SKIP_PATH = ("composer.lock", "package-lock.json", "docs/", "tests/", "lang/", "resources/js/i18n/")


def load_baseline():
    out = set()
    if not os.path.exists(BASELINE):
        return out
    with open(BASELINE, encoding="utf-8") as fh:
        for line in fh:
            line = line.split("#", 1)[0].strip()
            if "|" in line:
                out.add(tuple(p.strip() for p in line.split("|", 1)))
    return out


def skip(path):
    p = path.replace("\\", "/")
    return p.endswith(SKIP_SUFFIX) or any(p == s or p.startswith(s) or s in p.split("/")[-1:] for s in SKIP_PATH)


def check_line(path, line):
    hits = []
    for name, rx in RULES:
        m = rx.search(line)
        if not m:
            continue
        val = m.groupdict().get("v") or ""
        if name in ("assigned-secret", "db-url-password"):
            if PLACEHOLDER.search(line) or PLACEHOLDER.search(val):
                continue
            if name == "assigned-secret" and not re.search(r"[0-9]", val):
                continue  # prose or identifiers without digits
            if name == "assigned-secret" and re.fullmatch(r"[A-Za-z]+(?:-[A-Za-z0-9]+)+", val):
                continue  # hyphenated slug, not a secret
        hits.append(name)
    return hits


def scan_text(path, text, baseline, findings, tag=""):
    for n, line in enumerate(text.splitlines(), 1):
        if len(line) > 4000:
            continue
        for rule in check_line(path, line):
            if (rule, path) in baseline:
                continue
            findings.append((tag, path, n, rule))


def git(*args):
    return subprocess.run(["git", *args], cwd=ROOT, capture_output=True, text=True, encoding="utf-8", errors="replace")


def scan_staged(baseline):
    findings = []
    names = git("diff", "--cached", "--name-only", "--diff-filter=ACM").stdout.split("\n")
    for path in filter(None, names):
        if skip(path):
            continue
        added = git("diff", "--cached", "-U0", "--", path).stdout
        text = "\n".join(l[1:] for l in added.splitlines() if l.startswith("+") and not l.startswith("+++"))
        scan_text(path, text, baseline, findings)
    return findings


def scan_tree(baseline):
    findings = []
    for path in filter(None, git("ls-files").stdout.split("\n")):
        if skip(path):
            continue
        full = os.path.join(ROOT, path)
        try:
            if os.path.getsize(full) > 2_000_000:
                continue
            with open(full, encoding="utf-8", errors="replace") as fh:
                scan_text(path, fh.read(), baseline, findings)
        except OSError:
            continue
    return findings


def scan_history(baseline):
    findings = set()
    proc = subprocess.Popen(
        ["git", "log", "--all", "-p", "-U0", "--no-color", "--format=C %h"],
        cwd=ROOT, stdout=subprocess.PIPE, text=True, encoding="utf-8", errors="replace",
    )
    commit, path = "", ""
    for line in proc.stdout:
        if line.startswith("C "):
            commit = line[2:].strip()
        elif line.startswith("+++ b/"):
            path = line[6:].strip()
        elif line.startswith("+") and not line.startswith("+++") and path and not skip(path) and len(line) < 4000:
            for rule in check_line(path, line[1:].rstrip("\n")):
                if (rule, path) not in baseline:
                    findings.add((commit, path, 0, rule))
    proc.wait()
    return sorted(findings)


def main():
    ap = argparse.ArgumentParser()
    g = ap.add_mutually_exclusive_group(required=True)
    g.add_argument("--staged", action="store_true")
    g.add_argument("--tree", action="store_true")
    g.add_argument("--history", action="store_true")
    a = ap.parse_args()
    baseline = load_baseline()
    findings = scan_staged(baseline) if a.staged else scan_tree(baseline) if a.tree else scan_history(baseline)
    for tag, path, n, rule in findings:
        where = f"{path}:{n}" if n else path
        print(f"{rule}\t{where}\t{tag}".rstrip())
    if findings:
        print(f"\n{len(findings)} finding(s). Values are not printed. Move the value to an untracked file, "
              "or add a reasoned line to scripts/ops/secret_scan_baseline.txt.", file=sys.stderr)
        return 1
    print("secret scan: clean")
    return 0


if __name__ == "__main__":
    sys.exit(main())

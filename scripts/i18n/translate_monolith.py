#!/usr/bin/env python3
"""
translate_monolith.py — machine-translate the MONOLITHIC chrome dict.

The per-namespace catalogs (locales/<code>/<ns>.json) are the general case and
scripts/i18n/translate_catalog.py owns them. But the shell chrome the DEFAULT
layout (AppShell v1) reads — nav.*, app.*, header.*, footer.*, demo.*, common.*
— does not live in a namespace: it is the monolithic base dict at
resources/js/i18n/<code>.json, imported and spread at the TOP level by
i18n/index.js. A locale absent from that base renders English chrome even when
its page catalogs are fully translated. es/ar/hi/zh-Hans shipped their base dict
at Phase F; fr/pt (enabled in the v3 Wave-4 pass) had none.

This translates en.json's leaves into <code>.json for a target locale, reusing
translate_catalog's provider, token masking, and QA rails VERBATIM (one rail,
both surfaces — a citation or placeholder refused in a namespace is refused
here). Nesting is preserved so the output matches the hand-shipped base dicts.

Rails honored: local provider default (NLLB, zero spend, weights never leave the
box); cloud stays a --provider choice gated by translate_catalog's own
--yes-spend, never reachable from here. A QA-refused string is LEFT UNWRITTEN
(English fallback) rather than shipped — refusal is the answer.

Usage (inside the NLLB container, repo mounted at /repo):
  python3 scripts/i18n/translate_monolith.py --locale fr
  python3 scripts/i18n/translate_monolith.py --locale pt --provider nllb --dry-run

  --locale CODE      target locale (required)
  --provider NAME    stub | nllb        (default: nllb — the settled local pass)
  --chunk N          strings per forward-pass batch   (default: 24)
  --dry-run          count what would translate; write nothing
  --force            re-translate keys already present in the target base
"""
from __future__ import annotations

import argparse
import json
import time
from pathlib import Path

# Reuse the machine-pass primitives — the SAME provider, masking, and QA rails
# the namespace pass uses, so the monolithic chrome cannot pass a rail the rest
# of the corpus fails (or vice-versa).
import translate_catalog as tc

ROOT = Path(__file__).resolve().parents[2]
I18N = ROOT / "resources" / "js" / "i18n"


def flatten(d: dict, prefix: str = "") -> dict[str, str]:
    """Nested chrome dict -> {'nav.home': 'Home', ...} (leaf strings only)."""
    out: dict[str, str] = {}
    for k, v in d.items():
        key = f"{prefix}{k}"
        if isinstance(v, dict):
            out.update(flatten(v, key + "."))
        elif isinstance(v, str):
            out[key] = v
    return out


def set_nested(root: dict, dotted: str, value: str) -> None:
    """Write value at a dotted path, creating intermediate dicts."""
    parts = dotted.split(".")
    node = root
    for p in parts[:-1]:
        node = node.setdefault(p, {})
    node[parts[-1]] = value


def load_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8")) if path.exists() else {}


def dump_json(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(data, ensure_ascii=False, indent=4) + "\n", encoding="utf-8"
    )


def main() -> int:
    ap = argparse.ArgumentParser(description="Translate the monolithic chrome dict.")
    ap.add_argument("--locale", required=True)
    ap.add_argument("--provider", default="nllb", choices=["stub", "nllb"])
    ap.add_argument("--chunk", type=int, default=24)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--force", action="store_true")
    args = ap.parse_args()

    reg = tc.registry()
    if args.locale not in reg:
        print(f"unknown locale {args.locale!r} — not in the generated registry")
        return 2
    script = reg[args.locale].get("script") or "Latn"

    en = load_json(I18N / "en.json")
    if not en:
        print("no resources/js/i18n/en.json — nothing to translate")
        return 2
    src = flatten(en)

    tgt_path = I18N / f"{args.locale}.json"
    target = load_json(tgt_path)
    carried = flatten(target)

    pending = [(k, v) for k, v in src.items() if args.force or k not in carried]

    print(f"\ntranslate-monolith  en -> {args.locale} ({reg[args.locale].get('endonym', args.locale)})")
    print(f"provider: {args.provider}{'  [DRY RUN]' if args.dry_run else ''}"
          f"   chrome leaves: {len(src)}   to translate: {len(pending)}"
          f"   already carried: {len(src) - len(pending)}")

    if not pending:
        print("  nothing to do — chrome base is complete for this locale")
        return 0
    if args.dry_run:
        print("\n  dry run — nothing written")
        return 0

    glossary = tc.glossary_terms(args.locale)
    provider = tc.make_provider(args.provider, glossary)

    wrote = skipped = 0
    started = time.time()
    for i in range(0, len(pending), args.chunk):
        batch = pending[i:i + args.chunk]
        texts = [t for _, t in batch]
        t0 = time.time()
        try:
            outs = provider.translate_batch(texts, args.locale)
        except Exception as exc:  # noqa: BLE001
            print(f"    chunk {i // args.chunk + 1}: provider error — {exc}")
            continue
        for (key, src_text), out in zip(batch, outs):
            reason = tc.qa(src_text, out, script)
            if reason:
                skipped += 1
                # refusal is the answer: leave the key unwritten -> English
                # fallback -> surfaced to a reader, never shipped as confident.
                print(f"      skip  {key}  ({reason})")
                continue
            set_nested(target, key, out.strip())
            wrote += 1
        dump_json(tgt_path, target)   # commit per chunk (THE ETL RULE)
        done = min(i + args.chunk, len(pending))
        print(f"    [{done:>4}/{len(pending)}] +{wrote} "
              f"({time.time() - t0:.1f}s)", flush=True)

    elapsed = time.time() - started
    print(f"\n  translated {wrote}   skipped-to-review {skipped}   in {elapsed:.1f}s")
    print("  (monolithic chrome carries no meta sidecar — same posture as the "
          "hand-shipped es/ar/hi/zh-Hans base dicts; a reader pass is future work)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

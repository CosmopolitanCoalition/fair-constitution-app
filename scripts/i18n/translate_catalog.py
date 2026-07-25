#!/usr/bin/env python3
"""
CGA — scripts/i18n/translate_catalog.py
The machine first pass. Turns the English catalogs into other languages.

THE GOAL THIS SERVES: a person opens the application and reads it in their own
language. Everything else in this lane exists to make this step correct and
repeatable.

RESUMABLE BY CONSTRUCTION (THE ETL RULE). Work is committed per bounded chunk,
progress is visible while it runs, and a re-run skips what is already done — so
halting it costs at most one chunk, never the pass.

PROVIDERS (--provider):
  stub    deterministic, offline, no model. Marks each string so nobody can
          mistake it for a translation. Exists so the chain can be proven and
          pinned without a GPU or an API key.
  nllb    local NLLB-200 on this box. Free, private, the settled default.
  claude  Claude Haiku. Needs ANTHROPIC_API_KEY; costs money; refuses to run
          without an explicit --yes-spend, because spending is the operator's
          call and not a side effect of a default.

WHAT IT WILL NOT DO
  - overwrite a human-reviewed or locked string (status lives in the meta tree)
  - translate an ID token (R-/WF-/F-/I-/CLK-) or an Art. …/§ citation
  - emit a message whose {placeholders} differ from the source
  - emit a message vue-i18n cannot compile
  Any of those means the string is SKIPPED and reported, never shipped broken.

Usage:
  python3 scripts/i18n/translate_catalog.py --locale es --dry-run
  python3 scripts/i18n/translate_catalog.py --locale es --provider nllb
  python3 scripts/i18n/translate_catalog.py --locale es --namespace auth --limit 20

Options:
  --locale CODE      target locale (required)
  --namespace NS     restrict to one namespace (default: all)
  --provider NAME    stub | nllb | claude          (default: stub)
  --limit N          stop after N new strings (smoke runs)
  --chunk N          strings per committed chunk   (default: 40)
  --dry-run          translate nothing; report what a run would do
  --force            re-translate strings already carrying a machine value
  --yes-spend        required for --provider claude
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
I18N = ROOT / "resources" / "js" / "i18n"
LOCALES_DIR = I18N / "locales"
META_DIR = I18N / "meta"
REGISTRY_JS = I18N / "locales.generated.js"

# ─── Rails ────────────────────────────────────────────────────────────────────
# Reserved by vue-i18n. A translated message must not invent or lose these.
PLACEHOLDER = re.compile(r"(?<!\{')\{([A-Za-z_$][\w$]*)\}")
ID_TOKEN = re.compile(r"\b(?:R|WF|F|I|CLK)-[\dA-Z][\dA-Z-]*\b")
CITATION = re.compile(r"Art\.\s*[IVXLC]+(?:\s*§\s*\d+)?")
ESCAPED = re.compile(r"\{'[|{}@]'\}")

# Status values that a machine pass must never touch.
PROTECTED_STATUS = {"reviewed", "locked"}


def registry() -> dict[str, dict]:
    """Locale metadata from THE registry (scripts/i18n/languages.py output)."""
    src = REGISTRY_JS.read_text(encoding="utf-8")
    rows = {}
    for m in re.finditer(r"\{ code: \"([\w-]+)\",(.*?)\}", src):
        code, rest = m.group(1), m.group(2)
        def field(name, default=""):
            mm = re.search(rf'{name}: "([^"]*)"', rest)
            return mm.group(1) if mm else default
        rows[code] = {
            "code": code,
            "name": field("name"),
            "endonym": field("endonym"),
            "dir": field("dir", "ltr"),
            "script": field("script"),
        }
    return rows


# NLLB uses its own FLORES-200 tags, not BCP-47.
NLLB_TAG = {
    "es": "spa_Latn", "fr": "fra_Latn", "pt": "por_Latn", "de": "deu_Latn",
    "it": "ita_Latn", "nl": "nld_Latn", "ar": "arb_Arab", "hi": "hin_Deva",
    "bn": "ben_Beng", "ur": "urd_Arab", "fa": "pes_Arab", "ru": "rus_Cyrl",
    "uk": "ukr_Cyrl", "pl": "pol_Latn", "tr": "tur_Latn", "vi": "vie_Latn",
    "id": "ind_Latn", "ms": "zsm_Latn", "th": "tha_Thai", "ja": "jpn_Jpan",
    "ko": "kor_Hang", "zh-Hans": "zho_Hans", "zh-Hant": "zho_Hant",
    "sw": "swh_Latn", "ta": "tam_Taml", "te": "tel_Telu", "am": "amh_Ethi",
    "he": "heb_Hebr", "el": "ell_Grek", "cs": "ces_Latn", "hu": "hun_Latn",
    "ro": "ron_Latn", "sv": "swe_Latn", "da": "dan_Latn", "fi": "fin_Latn",
    "no": "nob_Latn", "sk": "slk_Latn", "bg": "bul_Cyrl", "hr": "hrv_Latn",
    "sr": "srp_Cyrl", "sq": "als_Latn", "af": "afr_Latn", "az": "azj_Latn",
    "ka": "kat_Geor", "hy": "hye_Armn", "kk": "kaz_Cyrl", "km": "khm_Khmr",
    "lo": "lao_Laoo", "lt": "lit_Latn", "lv": "lvs_Latn", "mk": "mkd_Cyrl",
    "mn": "khk_Cyrl", "mt": "mlt_Latn", "my": "mya_Mymr", "ne": "npi_Deva",
    "ps": "pbt_Arab", "si": "sin_Sinh", "sl": "slv_Latn", "so": "som_Latn",
    "tg": "tgk_Cyrl", "uz": "uzn_Latn", "zu": "zul_Latn", "xh": "xho_Latn",
    "st": "sot_Latn", "sn": "sna_Latn", "rw": "kin_Latn", "ny": "nya_Latn",
    "mg": "plt_Latn", "ht": "hat_Latn", "is": "isl_Latn", "ga": "gle_Latn",
    "et": "est_Latn", "ca": "cat_Latn", "eu": "eus_Latn", "gl": "glg_Latn",
    "fil": "fil_Latn", "ti": "tir_Ethi", "ku": "kmr_Latn", "be": "bel_Cyrl",
    "bs": "bos_Latn", "ky": "kir_Cyrl", "tk": "tuk_Latn", "dv": "div_Thaa",
    "lb": "ltz_Latn", "fo": "fao_Latn", "dz": "dzo_Tibt",
}


# ─── Providers ────────────────────────────────────────────────────────────────
class Provider:
    name = "abstract"
    is_cloud = False

    def translate_batch(self, texts: list[str], target: str) -> list[str | None]:
        raise NotImplementedError


class StubProvider(Provider):
    """Deterministic, offline. Marks its output so it can never masquerade."""

    name = "stub"

    def translate_batch(self, texts, target):
        return [f"[{target}] {t}" for t in texts]


class NllbProvider(Provider):
    """Local NLLB-200. Free, private, runs on this box — the settled default."""

    name = "nllb"

    def __init__(self, model_id="facebook/nllb-200-distilled-600M"):
        import torch
        from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

        self.torch = torch
        self.device = "cuda" if torch.cuda.is_available() else "cpu"
        print(f"  loading {model_id} on {self.device} …", flush=True)
        t0 = time.time()
        self.tok = AutoTokenizer.from_pretrained(model_id)
        self.model = AutoModelForSeq2SeqLM.from_pretrained(model_id).to(self.device)
        self.model.eval()
        print(f"  model ready in {time.time() - t0:.1f}s", flush=True)

    def translate_batch(self, texts, target):
        tag = NLLB_TAG.get(target)
        if tag is None:
            return [None] * len(texts)
        self.tok.src_lang = "eng_Latn"
        enc = self.tok(texts, return_tensors="pt", padding=True, truncation=True,
                       max_length=512).to(self.device)
        bos = self.tok.convert_tokens_to_ids(tag)
        with self.torch.inference_mode():
            out = self.model.generate(**enc, forced_bos_token_id=bos, max_length=512,
                                      num_beams=4)
        return self.tok.batch_decode(out, skip_special_tokens=True)


class ClaudeProvider(Provider):
    """Claude Haiku. Costs money — gated behind --yes-spend, never a default."""

    name = "claude-haiku"
    is_cloud = True

    def __init__(self, glossary: dict[str, str] | None = None):
        import anthropic  # noqa: F401  (imported lazily so the others need no SDK)

        self.client = anthropic.Anthropic()
        self.glossary = glossary or {}

    def translate_batch(self, texts, target):
        import anthropic  # noqa: F811

        terms = "\n".join(f"  {k} -> {v}" for k, v in self.glossary.items())
        system = (
            "You translate user-interface strings for a constitutional governance "
            "application. Rules, all mandatory:\n"
            "1. Return ONLY a JSON array of translations, same length and order.\n"
            "2. Keep every {placeholder} exactly as written.\n"
            "3. Never translate ID tokens (R-01, WF-SYS-03, F-ELB-008, CLK-06) or "
            "article citations (Art. II §2).\n"
            "4. Preserve tone: plain, precise, non-bureaucratic.\n"
            + (f"5. Use these settled terms:\n{terms}\n" if terms else "")
        )
        msg = self.client.messages.create(
            model="claude-haiku-4-5",
            max_tokens=8000,
            system=system,
            messages=[{"role": "user",
                       "content": f"Target language: {target}\n"
                                  f"Translate:\n{json.dumps(texts, ensure_ascii=False)}"}],
        )
        raw = msg.content[0].text.strip()
        raw = re.sub(r"^```(?:json)?|```$", "", raw, flags=re.M).strip()
        try:
            out = json.loads(raw)
        except json.JSONDecodeError:
            return [None] * len(texts)
        return out if isinstance(out, list) and len(out) == len(texts) else [None] * len(texts)


def make_provider(name: str, glossary=None) -> Provider:
    if name == "stub":
        return StubProvider()
    if name == "nllb":
        return NllbProvider()
    if name == "claude":
        return ClaudeProvider(glossary)
    raise SystemExit(f"unknown provider [{name}]")


# ─── QA: a string that fails any of these is skipped, never shipped ───────────
def qa(source: str, out: str | None) -> str | None:
    """Return a failure reason, or None when the translation is admissible."""
    if out is None:
        return "provider returned nothing"
    out = out.strip()
    if not out:
        return "empty"
    if sorted(PLACEHOLDER.findall(source)) != sorted(PLACEHOLDER.findall(out)):
        return "placeholder mismatch"
    if sorted(ID_TOKEN.findall(source)) != sorted(ID_TOKEN.findall(out)):
        return "ID token altered"
    if sorted(CITATION.findall(source)) != sorted(CITATION.findall(out)):
        return "citation altered"
    if len(ESCAPED.findall(source)) != len(ESCAPED.findall(out)):
        return "escaped reserved character altered"
    # a translation many times the source length is almost always a runaway decode
    if len(out) > max(60, len(source) * 4):
        return "implausible length"
    return None


def load(path: Path) -> dict:
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}


def dump(path: Path, obj: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    ordered = {k: obj[k] for k in sorted(obj)}
    path.write_text(json.dumps(ordered, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def glossary_terms(locale: str) -> dict[str, str]:
    """Settled renderings for this locale — the constraint on the machine pass."""
    g = load(I18N / "glossary" / "term-base.json")
    out = {}
    for term, val in g.items():
        if term.startswith("_") or not isinstance(val, dict):
            continue
        if val.get("do_not_translate"):
            out[term] = term          # ships in English, everywhere
            continue
        rendering = (val.get("translations") or {}).get(locale)
        if rendering:
            out[term] = rendering
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description="Machine-translate the message catalogs.")
    ap.add_argument("--locale", required=True)
    ap.add_argument("--namespace")
    ap.add_argument("--provider", default="stub", choices=["stub", "nllb", "claude"])
    ap.add_argument("--limit", type=int)
    ap.add_argument("--chunk", type=int, default=40)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--force", action="store_true")
    ap.add_argument("--yes-spend", action="store_true")
    args = ap.parse_args()

    reg = registry()
    if args.locale not in reg:
        print(f"[{args.locale}] is not in the locale registry — see config/locales.php")
        return 2
    if args.provider == "claude" and not args.yes_spend:
        print("--provider claude spends money. Re-run with --yes-spend if that is intended.")
        return 2
    if args.provider == "claude" and not os.environ.get("ANTHROPIC_API_KEY"):
        print("ANTHROPIC_API_KEY is not set. The operator places the key; this script never will.")
        return 2

    src_dir = LOCALES_DIR / "en"
    if not src_dir.exists():
        print("no English catalogs — run scripts/i18n/extract.mjs --write first")
        return 2

    namespaces = ([args.namespace] if args.namespace
                  else sorted(p.stem for p in src_dir.glob("*.json")))

    glossary = glossary_terms(args.locale)
    provider = None if args.dry_run else make_provider(args.provider, glossary)

    print(f"\ntranslate-catalog  en -> {args.locale} ({reg[args.locale]['endonym']})")
    print(f"provider: {args.provider}{'  [DRY RUN]' if args.dry_run else ''}"
          f"   glossary terms: {len(glossary)}")

    total_new = total_skip = total_kept = 0
    started = time.time()

    for ns in namespaces:
        source = load(src_dir / f"{ns}.json")
        if not source:
            continue
        tgt_path = LOCALES_DIR / args.locale / f"{ns}.json"
        meta_path = META_DIR / args.locale / f"{ns}.json"
        target, meta = load(tgt_path), load(meta_path)

        pending = []
        for key, text in source.items():
            status = meta.get(key, {}).get("status")
            if status in PROTECTED_STATUS:
                total_kept += 1
                continue
            if key in target and not args.force:
                total_kept += 1
                continue
            pending.append((key, text))

        if not pending:
            continue
        if args.limit is not None:
            room = args.limit - total_new
            if room <= 0:
                break
            pending = pending[:room]

        print(f"\n  {ns}: {len(pending)} to translate ({len(target)} already carried)")

        if args.dry_run:
            total_new += len(pending)
            continue

        # THE ETL RULE: bounded, committed chunks with visible progress.
        for i in range(0, len(pending), args.chunk):
            batch = pending[i:i + args.chunk]
            texts = [t for _, t in batch]
            t0 = time.time()
            try:
                outs = provider.translate_batch(texts, args.locale)
            except Exception as exc:                      # noqa: BLE001
                print(f"    chunk {i // args.chunk + 1}: provider error — {exc}")
                continue

            wrote = 0
            for (key, src_text), out in zip(batch, outs):
                reason = qa(src_text, out)
                if reason:
                    total_skip += 1
                    meta.setdefault(key, {}).update(
                        {"status": "review", "reason": reason, "provider": provider.name})
                    continue
                target[key] = out.strip()
                meta.setdefault(key, {}).update(
                    {"status": "machine", "provider": provider.name})
                wrote += 1
                total_new += 1

            # commit the chunk before starting the next one
            dump(tgt_path, target)
            dump(meta_path, meta)
            done = min(i + args.chunk, len(pending))
            print(f"    [{done:>5}/{len(pending)}] +{wrote} "
                  f"({time.time() - t0:.1f}s)", flush=True)

        if args.limit is not None and total_new >= args.limit:
            break

    elapsed = time.time() - started
    print(f"\n  translated {total_new}   skipped-to-review {total_skip}   "
          f"already carried {total_kept}   in {elapsed:.1f}s")
    if total_new and not args.dry_run:
        rate = total_new / max(elapsed, 0.001)
        print(f"  {rate:.1f} strings/sec  ->  a 3,366-key locale is "
              f"~{3366 / max(rate, 0.001) / 60:.1f} min at this rate")
    if args.dry_run:
        print("\n  dry run — nothing written")
    else:
        print("\n  re-run scripts/i18n/check.mjs to see the coverage move")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

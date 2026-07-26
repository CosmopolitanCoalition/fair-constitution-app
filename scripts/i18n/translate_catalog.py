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

# Live run state. Same shape as the ETL supervisor's control directory: plain
# JSON written atomically, one file per worker, so the UI can watch a run
# WITHOUT the workers needing a database, a queue, or a lock server. The halt
# flag is a file the operator's page drops; workers check it at every chunk
# boundary — the pull-engine posture in the cheapest form that is still honest.
RUN_DIR = ROOT / "storage" / "app" / "i18n-run"
WORKER_DIR = RUN_DIR / "workers"
HALT_FILE = RUN_DIR / "halt.request"


def _write_atomic(path: Path, payload: dict) -> None:
    """Write-then-replace: a reader never sees a half-written file."""
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp = path.with_suffix(path.suffix + ".tmp")
        tmp.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
        tmp.replace(path)
    except Exception:  # noqa: BLE001
        pass  # progress is a cosmetic mirror; it must never fail the work


def halted() -> bool:
    return HALT_FILE.exists()

# ─── Rails ────────────────────────────────────────────────────────────────────
# Reserved by vue-i18n. A translated message must not invent or lose these.
PLACEHOLDER = re.compile(r"(?<!\{')\{([A-Za-z_$][\w$]*)\}")
ID_TOKEN = re.compile(r"\b(?:R|WF|F|I|CLK)-[\dA-Z][\dA-Z-]*\b")
# Citations carry section RANGES with an en-dash in this repo's copy
# ("Art. V §1–2 · CLK-05"), and a regex that stops at §1 leaves "–2" exposed to
# the model, which then "translates" it. Match the whole citation or none of it.
CITATION = re.compile(
    r"Art\.\s*[IVXLC]+"
    r"(?:\s*§+\s*\d+(?:\s*[–—-]\s*\d+)?(?:\s*,\s*\d+)*)?"
)
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


# ─── Token masking ────────────────────────────────────────────────────────────
# Anything that must survive a translation byte-for-byte is hidden from the model
# behind an opaque marker and restored afterwards. Asking a translation model to
# "leave Art. II §2 alone" and then rejecting its output when it doesn't is a
# worse design than never showing it the citation: it turns a preventable defect
# into human review queue volume.
_MASKABLE = [ESCAPED, ID_TOKEN, CITATION, PLACEHOLDER]


def mask(text: str) -> tuple[str, list[str]]:
    kept: list[str] = []

    def take(m: re.Match) -> str:
        kept.append(m.group(0))
        # Digits-in-brackets survives NLLB and Haiku intact and is not itself
        # translatable text.
        return f"[{len(kept) - 1}]"

    out = text
    for pattern in _MASKABLE:
        out = pattern.sub(take, out)
    return out, kept


def unmask(text: str, kept: list[str]) -> str:
    out = text
    for i, original in enumerate(kept):
        # models occasionally pad the marker with spaces or drop the brackets
        out = re.sub(rf"\[\s*{i}\s*\]", lambda _m, o=original: o, out, count=1)
    return out


# ─── Providers ────────────────────────────────────────────────────────────────
class Provider:
    name = "abstract"
    is_cloud = False

    def translate_batch(self, texts: list[str], target: str) -> list[str | None]:
        """Mask protected tokens, translate, restore. Subclasses implement _run."""
        masked, kepts = [], []
        for t in texts:
            m, k = mask(t)
            masked.append(m)
            kepts.append(k)
        outs = self._run(masked, target)
        return [None if o is None else unmask(o, k) for o, k in zip(outs, kepts)]

    def _run(self, texts: list[str], target: str) -> list[str | None]:
        raise NotImplementedError


class StubProvider(Provider):
    """Deterministic, offline. Marks its output so it can never masquerade."""

    name = "stub"

    def _run(self, texts, target):
        return [f"[{target}] {t}" for t in texts]


class NllbProvider(Provider):
    """Local NLLB-200. Free, private, runs on this box — the settled default."""

    name = "nllb"

    # Sentences per forward pass. Bounds memory independently of --chunk, which
    # counts strings; long-form content turns one string into dozens of pieces.
    MAX_PIECES = 48

    def __init__(self, model_id="facebook/nllb-200-distilled-600M", device=None):
        import torch
        from transformers import AutoModelForSeq2SeqLM, AutoTokenizer

        self.torch = torch
        # This box shares its GPU with the dub lane's whisper models, so CUDA
        # being *present* does not mean it is free. Half precision roughly halves
        # the footprint, and an OOM mid-run falls back to CPU rather than
        # failing the pass — slower is a cost, stopping is a defect.
        self.device = device or ("cuda" if torch.cuda.is_available() else "cpu")
        dtype = torch.float16 if self.device == "cuda" else torch.float32
        print(f"  loading {model_id} on {self.device} ({dtype}) …", flush=True)
        t0 = time.time()
        self.tok = AutoTokenizer.from_pretrained(model_id)
        self.model = AutoModelForSeq2SeqLM.from_pretrained(model_id, dtype=dtype)
        self.model.to(self.device)
        self.model.eval()
        print(f"  model ready in {time.time() - t0:.1f}s", flush=True)

    def _to_cpu(self):
        print("  GPU out of memory — falling back to CPU for the rest of this run",
              flush=True)
        self.model = self.model.to("cpu").float()
        self.device = "cpu"
        try:
            self.torch.cuda.empty_cache()
        except Exception:  # noqa: BLE001
            pass

    def _generate(self, texts, tag):
        self.tok.src_lang = "eng_Latn"
        enc = self.tok(texts, return_tensors="pt", padding=True, truncation=True,
                       max_length=512).to(self.device)
        bos = self.tok.convert_tokens_to_ids(tag)
        with self.torch.inference_mode():
            out = self.model.generate(**enc, forced_bos_token_id=bos, max_length=512,
                                      num_beams=4)
        return self.tok.batch_decode(out, skip_special_tokens=True)

    def _run(self, texts, target):
        """
        Translate SENTENCE BY SENTENCE, then rejoin.

        NLLB-200-distilled-600M is a sentence-level model. Handed two sentences
        it frequently returns one — fluent, well-formed, and missing half the
        meaning, which no placeholder or citation rail can detect. Feeding it
        one sentence at a time is the fix at the root rather than at the gate.
        """
        tag = NLLB_TAG.get(target)
        if tag is None:
            return [None] * len(texts)

        # flatten: remember which sentences belong to which source string
        pieces: list[str] = []
        spans: list[tuple[int, int]] = []
        for t in texts:
            parts = sentences(t) or [t]
            spans.append((len(pieces), len(pieces) + len(parts)))
            pieces.extend(parts)

        # Sentence-splitting means the PIECE count, not the string count, decides
        # how much goes through the model at once. That is fine for UI copy
        # (1–3 sentences each) and lethal for long-form content: lane 15's
        # curriculum is ~131,903 words, so a single education item can be ~50
        # sentences and a 16-string chunk becomes ~800 — a guaranteed OOM. Cap
        # the pieces per forward pass so the caller's --chunk stays a unit of
        # WORK rather than an accidental memory bet.
        done: list[str] = []
        for i in range(0, len(pieces), self.MAX_PIECES):
            window = pieces[i:i + self.MAX_PIECES]
            try:
                done.extend(self._generate(window, tag))
            except Exception as exc:  # noqa: BLE001
                if "out of memory" not in str(exc).lower() or self.device == "cpu":
                    raise
                self._to_cpu()
                done.extend(self._generate(window, tag))

        return [" ".join(done[a:b]).strip() for a, b in spans]


class ClaudeProvider(Provider):
    """Claude Haiku. Costs money — gated behind --yes-spend, never a default."""

    name = "claude-haiku"
    is_cloud = True

    def __init__(self, glossary: dict[str, str] | None = None):
        import anthropic  # noqa: F401  (imported lazily so the others need no SDK)

        self.client = anthropic.Anthropic()
        self.glossary = glossary or {}

    def _run(self, texts, target):
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
# ─── SCRIPT AUDIT OF THE GATE SET ─────────────────────────────────────────────
# Every rail below was written against English and then applied to the target.
# That is a bug generator, not a bug: it produces confident false rejections
# precisely for the languages furthest from English — the ones this pipeline
# exists to serve and the ones nobody is proofreading. Two instances were found
# the expensive way, one language at a time (Arabic 440, Chinese 1,110). This is
# the audit of all nine gates, done in one pass instead of a third time.
#
#   gate                       assumption                       verdict
#   1 provider returned None   none                             safe
#   2 empty                    whitespace is visible            FIXED: zero-width
#                                                               and bidi controls
#                                                               now stripped first
#   3 placeholder parity       tokens are ASCII                 safe (we mint them)
#   4 ID token parity          tokens are ASCII                 safe
#   5 citation parity          tokens are ASCII                 safe
#   6 escaped-char parity      tokens are ASCII                 safe
#   7 upper length bound       code points ~ perceived chars    FIXED: combining
#                                                               marks inflate the
#                                                               count, so measure
#                                                               NFC + mark-free
#   8 lower length floor       alphabetic density               FIXED: per script
#   9 sentence count           every script punctuates          FIXED: Thai and
#                                                               Lao mark no
#                                                               sentence end at
#                                                               all; rail skipped
#                                                               rather than
#                                                               guessed
#
# Cross-cutting, and the reason gates 2/7/8 needed work: text arrives in mixed
# normalisation forms. NFD Devanagari or Hangul counts far more code points than
# the identical NFC string, and RTL output carries invisible bidi marks. Both
# inflate every length measurement and break every equality test. Measurement is
# therefore always done on a normalised copy; the STORED translation keeps the
# provider's own bytes.
#
# Deliberately NOT used anywhere in this file: word counts. Chinese and Japanese
# have no spaces and Thai has no word breaks, so splitting on whitespace is
# meaningless for most of the target set.

# Zero-width and directional controls: invisible, and content to len().
_INVISIBLE = re.compile(r"[​-‏‪-‮⁦-⁩﻿­]")


def _measure(s: str) -> str:
    """The form lengths and comparisons are computed on. Never stored."""
    import unicodedata

    # NFC first (compose combining marks), then drop marks that remain, then
    # drop invisibles. A Devanagari or Hangul string in NFD otherwise measures
    # far longer than the identical NFC string.
    composed = unicodedata.normalize("NFC", s)
    stripped = "".join(c for c in composed if not unicodedata.combining(c))
    return _INVISIBLE.sub("", stripped)


# Scripts that mark no sentence boundary at all. Thai and Lao run sentences
# together and separate them with a space, so "how many sentences came back" is
# not a question their orthography can answer — the rail is skipped rather than
# guessed at.
_NO_SENTENCE_PUNCTUATION = {"Thai", "Laoo"}

# How much shorter than its English source a CORRECT translation runs, by target
# script. Character count is not comparable across writing systems: one Han
# character carries a whole morpheme, so faithful Chinese is routinely a quarter
# the length of its source. A single Latin-calibrated floor quarantined 1,110 of
# Chinese's 1,200 — the third time a rail tuned on English misfired on exactly
# the languages this pipeline exists to serve.
_SHORT_FLOOR = {
    "Hans": 0.12, "Hant": 0.12, "Jpan": 0.15, "Kore": 0.25,   # logographic / syllabic
    "Thai": 0.30, "Laoo": 0.30, "Khmr": 0.30, "Mymr": 0.30,   # abugida, unspaced
    "Arab": 0.40, "Hebr": 0.40, "Thaa": 0.40,                 # abjad, vowels dropped
}
_SHORT_FLOOR_DEFAULT = 0.45


def qa(source: str, out: str | None, script: str = "Latn") -> str | None:
    """Return a failure reason, or None when the translation is admissible."""
    if out is None:
        return "provider returned nothing"
    out = out.strip()
    m_out, m_src = _measure(out), _measure(source)
    if not m_out:
        return "empty"   # includes output that is only zero-width/bidi controls
    if sorted(PLACEHOLDER.findall(source)) != sorted(PLACEHOLDER.findall(out)):
        return "placeholder mismatch"
    if sorted(ID_TOKEN.findall(source)) != sorted(ID_TOKEN.findall(out)):
        return "ID token altered"
    if sorted(CITATION.findall(source)) != sorted(CITATION.findall(out)):
        return "citation altered"
    if len(ESCAPED.findall(source)) != len(ESCAPED.findall(out)):
        return "escaped reserved character altered"
    # a translation many times the source length is almost always a runaway decode
    if len(m_out) > max(60, len(m_src) * 4):
        return "implausible length"
    # ...and one far SHORTER than its source means content was dropped. Observed
    # for real: NLLB-600M silently returned only the second sentence of
    # "Sign in to your Individual record. Your rights ride with your residency…"
    # Placeholder and citation rails cannot see that — a fluent, well-formed,
    # half-missing sentence passes every one of them. No translation is far
    # better than a confidently truncated one.
    floor = _SHORT_FLOOR.get(script, _SHORT_FLOOR_DEFAULT)
    if len(m_src) >= 40 and len(m_out) < len(m_src) * floor:
        return (f"suspiciously short for {script} — source content likely dropped "
                f"({len(m_out)}/{len(m_src)} chars, floor {floor:g})")
    # Every sentence in should be a sentence out, counted by TERMINATOR so the
    # test works in any script. Fewer terminators ALONE is not proof: a model
    # legitimately drops a trailing period, which looks identical to losing the
    # last sentence. Real content loss shows both signals — fewer terminators
    # AND a much shorter result — so both are required before a string is
    # quarantined. Either alone produced a false positive we actually saw.
    if script in _NO_SENTENCE_PUNCTUATION:
        return None
    src_n, out_n = _terminator_count(source), _terminator_count(out)
    loss_ratio = max(_SHORT_FLOOR.get(script, _SHORT_FLOOR_DEFAULT) * 1.6, 0.35)
    if src_n >= 2 and out_n < src_n and len(m_out) < len(m_src) * loss_ratio:
        return f"sentence count fell {src_n} -> {out_n}"
    return None


_SENT_SPLIT = re.compile(r"(?<=[.!?])\s+(?=[A-ZÀ-ɏ])")

# A period does NOT end a sentence after these. Without this, "e.g. Saturday
# crew" is torn into two fragments, each translated blind to the other, and the
# sentence-count rail then reports the rejoined result as content loss —
# a rail firing on a defect the splitter itself introduced.
_ABBREV = (
    "e.g", "i.e", "etc", "vs", "cf", "approx", "no", "fig", "art", "sec",
    "mr", "mrs", "ms", "dr", "st", "jr", "sr", "vol", "ch", "para",
)


def sentences(s: str) -> list[str]:
    """Split into sentences, refusing to break after a known abbreviation."""
    parts = _SENT_SPLIT.split(s.strip())
    out: list[str] = []
    for part in parts:
        if out:
            tail = out[-1].rstrip()
            last = re.split(r"[\s(\[]", tail)[-1].rstrip(".").lower()
            if last in _ABBREV:
                out[-1] = f"{out[-1]} {part}"
                continue
        out.append(part)
    return [p for p in out if p.strip()]


def _sentence_count(s: str) -> int:
    """English-side sentence count. Only ever applied to source text."""
    return len(sentences(s))


# Sentence terminators across the scripts this app targets. The rail below
# compares SOURCE against TRANSLATION, so it cannot use the English splitter:
# that one keys on "period, then a CAPITAL letter", and Arabic, Hebrew, Devanagari,
# Thai, Chinese and Japanese have no capital letters at all. Applied to them it
# reports every correct multi-sentence translation as content loss — which is
# exactly what it did: 440 of Arabic's 472 quarantined strings were fine.
_TERMINATORS = re.compile(
    r"[.!?]"          # Latin / Cyrillic / Greek
    r"|[۔؟؛]"   # Arabic full stop, question mark, semicolon
    r"|[।॥]"          # Devanagari danda, double danda
    r"|[。！？]"    # CJK full stop, fullwidth ! and ?
    r"|[׃]"                # Hebrew sof pasuq
    r"|[።፧፨]"       # Ethiopic full stop / question / paragraph (am, ti)
    r"|[։]"                # Armenian full stop
    r"|[។៕]"          # Khmer khan / bariyoosan
    r"|[။၊]"          # Myanmar section / little section
    r"|[།༎]"          # Tibetan shad / nyis shad (dz)
    r"|[۔]"                # Urdu full stop
)


def _terminator_count(s: str) -> int:
    return len(_TERMINATORS.findall(s))


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


class Heartbeat:
    """
    One worker's live state, rewritten at every chunk boundary.

    Deliberately mirrors the autoscale worker-lease row the district mapper's
    strip already renders — id, what it is working on, how long it has been on
    it — so the translation page shows the same shape of thing the operator
    already knows how to read.
    """

    def __init__(self, locale: str, script: str, provider: str, total: int):
        self.id = f"{locale}-{os.getpid()}"
        self.path = WORKER_DIR / f"{self.id}.json"
        self.state = {
            "id": self.id,
            "pid": os.getpid(),
            "locale": locale,
            "script": script,
            "provider": provider,
            "device": "?",
            "state": "starting",
            "namespace": None,
            "done": 0,
            "review": 0,
            "total": total,
            "current": [],
            "chunk_secs": None,
            "rate": None,
            "started_at": time.time(),
            "last_seen": time.time(),
        }
        self.beat()

    def beat(self, **fields) -> None:
        self.state.update(fields)
        self.state["last_seen"] = time.time()
        elapsed = max(self.state["last_seen"] - self.state["started_at"], 0.001)
        self.state["rate"] = round(self.state["done"] / elapsed, 2)
        _write_atomic(self.path, self.state)

    def retire(self, state: str = "done") -> None:
        self.beat(state=state, current=[])


def self_test() -> int:
    """
    Pins the rails. Every case here is a defect that was observed for real
    against NLLB-200 on this repo's own strings — not a hypothetical.
    """
    cases: list[tuple[str, bool, str]] = []

    def check(label: str, ok: bool, detail: str = "") -> None:
        cases.append((label, ok, detail))

    # masking round-trips everything that must survive byte-for-byte
    src = "Sign in. Art. I; Art. V §1 applies to {name} under F-IND-003."
    m, kept = mask(src)
    check("mask/unmask round-trips", unmask(m, kept) == src)
    check("mask hides the citation from the model", "Art." not in m, m)
    check("mask hides the ID token", "F-IND-003" not in m, m)
    check("mask hides the placeholder", "{name}" not in m, m)

    # the rails
    check("placeholder loss is caught",
          qa("Hello {name}", "Hola") is not None)
    check("citation damage is caught",
          qa("See Art. II §2", "Ver Art. III §9") is not None)
    check("ID token damage is caught",
          qa("File F-IND-003", "Archivar F-IND-009") is not None)
    check("empty output is caught", qa("Anything at all", "") is not None)
    check("runaway decode is caught",
          qa("Hi", "x" * 500) is not None)

    # the two the placeholder/citation rails CANNOT see — content loss
    long_src = ("Sign in to your Individual record. Your rights ride with your "
                "residency, not with this session.")
    dropped = "Sus derechos van con su residencia, no con esta sesión."
    check("dropped sentence is caught", qa(long_src, dropped) is not None,
          str(qa(long_src, dropped)))
    check("a faithful translation passes",
          qa("Log in", "Iniciar sesión") is None,
          str(qa("Log in", "Iniciar sesión")))
    check("a faithful long translation passes",
          qa(long_src,
             "Inicie sesión en su registro individual. Sus derechos van con su "
             "residencia, no con esta sesión.") is None)

    # sentence splitting is what prevents the loss in the first place
    check("sentence splitter finds both sentences", _sentence_count(long_src) == 2)
    check("single sentence stays single", _sentence_count("Log in") == 1)

    # both observed in the first full Spanish pass, both bugs in THIS file
    check("abbreviation is not a sentence end",
          _sentence_count("e.g. Saturday crew") == 1,
          str(sentences("e.g. Saturday crew")))
    # the rail must not be Latin-centric: Arabic has no capital letters, and a
    # correct two-sentence Arabic translation must not read as content loss
    ar_src = "Sign in to your record. Your rights ride with your residency."
    ar_ok = "سجل الدخول إلى سجلك. حقوقك ترتبط بإقامتك."
    check("two-sentence Arabic is not flagged as loss", qa(ar_src, ar_ok) is None,
          str(qa(ar_src, ar_ok)))
    hi_ok = "अपने रिकॉर्ड में साइन इन करें। आपके अधिकार आपके निवास से जुड़े हैं।"
    check("Devanagari danda counts as a sentence end", qa(ar_src, hi_ok) is None,
          str(qa(ar_src, hi_ok)))
    # Chinese: a faithful translation is a fraction of its source LENGTH.
    zh_src = "Sign in to your Individual record to continue to the ballot page."
    zh_ok = "登录您的个人记录以继续前往投票页面。"
    check("faithful Chinese is not 'suspiciously short'",
          qa(zh_src, zh_ok, "Hans") is None, str(qa(zh_src, zh_ok, "Hans")))
    check("the same output WOULD be flagged for a Latin target",
          qa(zh_src, zh_ok, "Latn") is not None)
    check("genuinely truncated Chinese is still caught",
          qa(zh_src, "登录", "Hans") is not None)

    # --- the one-pass script audit, pinned so a third instance cannot appear ---
    # Thai marks no sentence end at all; the rail must abstain, not guess.
    th_src = "Log in to your record. Your rights follow your residency."
    th_ok = "เข้าสู่ระบบ สิทธิของคุณเป็นไปตามถิ่นที่อยู่"
    check("Thai abstains from the sentence rail", qa(th_src, th_ok, "Thai") is None,
          str(qa(th_src, th_ok, "Thai")))

    # NFD input must not measure longer than the identical NFC string.
    import unicodedata as _u
    nfc = "서울에서 기록을 확인하십시오."
    check("normalisation does not inflate length",
          len(_measure(_u.normalize("NFD", nfc))) == len(_measure(nfc)))

    # bidi and zero-width controls are invisible; they are not content
    check("invisible controls are not content",
          _measure("‏مرحبا‎") == "مرحبا")
    check("an all-invisible output is empty",
          qa("Some real source text here", "‏​‎") is not None)

    # terminators the first pass missed entirely
    for label, ch in (("Ethiopic", "።"), ("Armenian", "։"),
                      ("Khmer", "។"), ("Myanmar", "။"), ("Tibetan", "།")):
        check(f"{label} full stop counts as a sentence end",
              _terminator_count(f"a{ch} b{ch}") == 2)

    check("real loss is still caught",
          qa(ar_src, "حقوقك ترتبط بإقامتك.") is not None)

    cite = "Art. V §1–2 · CLK-05"
    m2, k2 = mask(cite)
    check("en-dash citation range masks whole",
          "§" not in m2 and unmask(m2, k2) == cite, m2)

    failed = [c for c in cases if not c[1]]
    for label, ok, detail in cases:
        print(f"  {'ok  ' if ok else 'FAIL'}  {label}" + (f"   [{detail}]" if not ok and detail else ""))
    print(f"\n  {len(cases) - len(failed)}/{len(cases)} passed")
    return 1 if failed else 0


def main() -> int:
    if "--self-test" in sys.argv:
        return self_test()
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

    script = reg[args.locale].get("script") or "Latn"
    glossary = glossary_terms(args.locale)
    provider = None if args.dry_run else make_provider(args.provider, glossary)

    # total outstanding across every namespace, so the bar has a real denominator
    outstanding = 0
    for _ns in namespaces:
        _src = load(src_dir / f"{_ns}.json")
        _tgt = load(LOCALES_DIR / args.locale / f"{_ns}.json")
        _meta = load(META_DIR / args.locale / f"{_ns}.json")
        for _k in _src:
            if _meta.get(_k, {}).get("status") in PROTECTED_STATUS:
                continue
            if _k in _tgt and not args.force:
                continue
            outstanding += 1
    beat = None if args.dry_run else Heartbeat(args.locale, script, args.provider, outstanding)
    if beat is not None:
        beat.beat(state="loading",
                  device=getattr(provider, "device", "n/a"))

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
        if beat is not None:
            beat.beat(state="translating", namespace=ns)

        for i in range(0, len(pending), args.chunk):
            # The halt flag is checked HERE, at a committed boundary — never
            # mid-chunk. Halting can therefore never cost more than one chunk,
            # and never leaves a half-written catalog.
            if halted():
                print("    halt requested — parking at a committed boundary")
                if beat is not None:
                    beat.retire("halted")
                return 0

            batch = pending[i:i + args.chunk]
            texts = [t for _, t in batch]
            if beat is not None:
                beat.beat(current=[t[:90] for t in texts[:6]])
            t0 = time.time()
            try:
                outs = provider.translate_batch(texts, args.locale)
            except Exception as exc:                      # noqa: BLE001
                print(f"    chunk {i // args.chunk + 1}: provider error — {exc}")
                continue

            wrote = 0
            for (key, src_text), out in zip(batch, outs):
                reason = qa(src_text, out, script)
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
            if beat is not None:
                beat.beat(done=total_new, review=total_skip, namespace=ns,
                          chunk_secs=round(time.time() - t0, 2),
                          device=getattr(provider, "device", "n/a"))
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

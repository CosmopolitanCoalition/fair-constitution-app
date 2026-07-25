#!/usr/bin/env python3
"""
CGA — scripts/i18n/languages.py
THE locale registry. One source of truth, two generated artifacts.

Phase N's charter names a single registry that generates both `config/locales.php`
and the JS registry, "which kills the PHP<->JS drift". This is that file.

WHAT IT REPLACES — five hand-maintained, already-disagreeing lists:
  1. resources/js/i18n/index.js            LOCALES  (the only one carrying `dir`)
  2. app/Http/Middleware/SetLocale.php     SUPPORTED
  3. app/Http/Controllers/Civic/MyRecordController.php  (list + Rule::in)
  4. resources/views/app.blade.php         an RTL set naming fa/he/ur, which
                                           exist in NO other list
  5. resources/js/Pages/Auth/Register.vue  a hardcoded LANGUAGES array

NOT TO BE CONFUSED WITH scripts/etl/languages.py — same filename, different
artifact. That one maps ISO3 country -> official language for the geodata
importer's `jurisdictions.official_languages` column. It is this file's INPUT
(it is where the charter's "115 registered locales" number comes from) and
nothing more.

THREE TIERS, and they are three different questions:
  registered  — the app knows the locale exists and can display its name
  translated  — we intend to carry a full message catalog for it
  published   — its catalog passed QA and renders to real users

The charter used "115 registered" and "77 languages" interchangeably. They are
not the same set, and this file computes each rather than asserting either.

Usage:
  python3 scripts/i18n/languages.py                 # print the derivation
  python3 scripts/i18n/languages.py --write         # generate both artifacts
  python3 scripts/i18n/languages.py --check         # exit 1 if artifacts stale
  python3 scripts/i18n/languages.py --json          # machine-readable

Options:
  --write   emit config/locales.php + resources/js/i18n/locales.generated.js
  --check   compare generated files against what WOULD be generated; exit 1 on drift
  --json    dump the resolved registry
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

# ─── Paths ────────────────────────────────────────────────────────────────────
ROOT = Path(__file__).resolve().parents[2]
ETL_REGISTRY = ROOT / "scripts" / "etl" / "languages.py"
OUT_PHP = ROOT / "config" / "locales.php"
OUT_JS = ROOT / "resources" / "js" / "i18n" / "locales.generated.js"

# ─── CLDR plural families ─────────────────────────────────────────────────────
# The categories each family selects between, in the order a `|`-separated
# vue-i18n message must supply them. `check.mjs` validates form counts against
# this; the generated JS implements the selector.
PLURAL_FAMILIES: dict[str, list[str]] = {
    "other":          ["other"],
    "one_other":      ["one", "other"],
    "zero_one_other": ["one", "other"],          # 0 counts as "one" (fr, pt, hi…)
    "one_two_other":  ["one", "two", "other"],
    "slavic":         ["one", "few", "many", "other"],
    "polish":         ["one", "few", "many", "other"],
    "czech":          ["one", "few", "other"],
    "lithuanian":     ["one", "few", "other"],
    "latvian":        ["zero", "one", "other"],
    "romanian":       ["one", "few", "other"],
    "maltese":        ["one", "few", "many", "other"],
    "irish":          ["one", "two", "few", "many", "other"],
    "arabic":         ["zero", "one", "two", "few", "many", "other"],
}

# ─── The registry ─────────────────────────────────────────────────────────────
# (english_name, endonym, script, dir, plural_family)
# endonym None  => not verified; the generator emits the English name and flags
# it, rather than inventing a native name nobody checked.
L: dict[str, tuple] = {
    # ── tier 1: the five the app ships today ──────────────────────────────────
    "en":      ("English",            "English",          "Latn", "ltr", "one_other"),
    "es":      ("Spanish",            "Español",          "Latn", "ltr", "one_other"),
    "ar":      ("Arabic",             "العربية",           "Arab", "rtl", "arabic"),
    "zh-Hans": ("Chinese (Simplified)", "中文（简体）",      "Hans", "ltr", "other"),
    "zh-Hant": ("Chinese (Traditional)", "中文（繁體）",     "Hant", "ltr", "other"),
    "hi":      ("Hindi",              "हिन्दी",              "Deva", "ltr", "zero_one_other"),

    # ── widely spoken ─────────────────────────────────────────────────────────
    "fr":      ("French",             "Français",         "Latn", "ltr", "zero_one_other"),
    "pt":      ("Portuguese",         "Português",        "Latn", "ltr", "zero_one_other"),
    "ru":      ("Russian",            "Русский",          "Cyrl", "ltr", "slavic"),
    "de":      ("German",             "Deutsch",          "Latn", "ltr", "one_other"),
    "ja":      ("Japanese",           "日本語",             "Jpan", "ltr", "other"),
    "ko":      ("Korean",             "한국어",             "Kore", "ltr", "other"),
    "it":      ("Italian",            "Italiano",         "Latn", "ltr", "one_other"),
    "bn":      ("Bengali",            "বাংলা",             "Beng", "ltr", "zero_one_other"),
    "ur":      ("Urdu",               "اردو",              "Arab", "rtl", "one_other"),
    "id":      ("Indonesian",         "Bahasa Indonesia", "Latn", "ltr", "other"),
    "ms":      ("Malay",              "Bahasa Melayu",    "Latn", "ltr", "other"),
    "tr":      ("Turkish",            "Türkçe",           "Latn", "ltr", "one_other"),
    "vi":      ("Vietnamese",         "Tiếng Việt",       "Latn", "ltr", "other"),
    "fa":      ("Persian",            "فارسی",             "Arab", "rtl", "zero_one_other"),
    "sw":      ("Swahili",            "Kiswahili",        "Latn", "ltr", "one_other"),
    "ta":      ("Tamil",              "தமிழ்",             "Taml", "ltr", "one_other"),
    "th":      ("Thai",               "ไทย",               "Thai", "ltr", "other"),
    "pl":      ("Polish",             "Polski",           "Latn", "ltr", "polish"),
    "uk":      ("Ukrainian",          "Українська",       "Cyrl", "ltr", "slavic"),
    "nl":      ("Dutch",              "Nederlands",       "Latn", "ltr", "one_other"),
    "ro":      ("Romanian",           "Română",           "Latn", "ltr", "romanian"),
    "el":      ("Greek",              "Ελληνικά",         "Grek", "ltr", "one_other"),
    "cs":      ("Czech",              "Čeština",          "Latn", "ltr", "czech"),
    "hu":      ("Hungarian",          "Magyar",           "Latn", "ltr", "one_other"),
    "sv":      ("Swedish",            "Svenska",          "Latn", "ltr", "one_other"),
    "he":      ("Hebrew",             "עברית",             "Hebr", "rtl", "one_two_other"),
    "da":      ("Danish",             "Dansk",            "Latn", "ltr", "one_other"),
    "fi":      ("Finnish",            "Suomi",            "Latn", "ltr", "one_other"),
    "no":      ("Norwegian",          "Norsk",            "Latn", "ltr", "one_other"),
    "sk":      ("Slovak",             "Slovenčina",       "Latn", "ltr", "czech"),
    "bg":      ("Bulgarian",          "Български",        "Cyrl", "ltr", "one_other"),
    "hr":      ("Croatian",           "Hrvatski",         "Latn", "ltr", "slavic"),
    "sr":      ("Serbian",            "Српски",           "Cyrl", "ltr", "slavic"),
    "sq":      ("Albanian",           "Shqip",            "Latn", "ltr", "one_other"),
    "af":      ("Afrikaans",          "Afrikaans",        "Latn", "ltr", "one_other"),
    "am":      ("Amharic",            "አማርኛ",             "Ethi", "ltr", "zero_one_other"),
    "az":      ("Azerbaijani",        "Azərbaycan",       "Latn", "ltr", "one_other"),
    "be":      ("Belarusian",         "Беларуская",       "Cyrl", "ltr", "slavic"),
    "bs":      ("Bosnian",            "Bosanski",         "Latn", "ltr", "slavic"),
    "ca":      ("Catalan",            "Català",           "Latn", "ltr", "one_other"),
    "et":      ("Estonian",           "Eesti",            "Latn", "ltr", "one_other"),
    "ga":      ("Irish",              "Gaeilge",          "Latn", "ltr", "irish"),
    "hy":      ("Armenian",           "Հայերեն",          "Armn", "ltr", "zero_one_other"),
    "ka":      ("Georgian",           "ქართული",          "Geor", "ltr", "one_other"),
    "kk":      ("Kazakh",             "Қазақша",          "Cyrl", "ltr", "one_other"),
    "km":      ("Khmer",              "ភាសាខ្មែរ",           "Khmr", "ltr", "other"),
    "ky":      ("Kyrgyz",             "Кыргызча",         "Cyrl", "ltr", "one_other"),
    "lo":      ("Lao",                "ລາວ",               "Laoo", "ltr", "other"),
    "lt":      ("Lithuanian",         "Lietuvių",         "Latn", "ltr", "lithuanian"),
    "lv":      ("Latvian",            "Latviešu",         "Latn", "ltr", "latvian"),
    "mk":      ("Macedonian",         "Македонски",       "Cyrl", "ltr", "one_other"),
    "mn":      ("Mongolian",          "Монгол",           "Cyrl", "ltr", "one_other"),
    "mt":      ("Maltese",            "Malti",            "Latn", "ltr", "maltese"),
    "my":      ("Burmese",            "မြန်မာ",             "Mymr", "ltr", "other"),
    "ne":      ("Nepali",             "नेपाली",             "Deva", "ltr", "one_other"),
    "ps":      ("Pashto",             "پښتو",              "Arab", "rtl", "one_other"),
    "si":      ("Sinhala",            "සිංහල",             "Sinh", "ltr", "one_other"),
    "sl":      ("Slovenian",          "Slovenščina",      "Latn", "ltr", "slavic"),
    "so":      ("Somali",             "Soomaali",         "Latn", "ltr", "one_other"),
    "tg":      ("Tajik",              "Тоҷикӣ",           "Cyrl", "ltr", "one_other"),
    "tk":      ("Turkmen",            "Türkmen",          "Latn", "ltr", "one_other"),
    "uz":      ("Uzbek",              "Oʻzbek",           "Latn", "ltr", "one_other"),
    "zu":      ("Zulu",               "isiZulu",          "Latn", "ltr", "zero_one_other"),
    "xh":      ("Xhosa",              "isiXhosa",         "Latn", "ltr", "one_other"),
    "st":      ("Southern Sotho",     "Sesotho",          "Latn", "ltr", "one_other"),
    "sn":      ("Shona",              "chiShona",         "Latn", "ltr", "one_other"),
    "rw":      ("Kinyarwanda",        "Ikinyarwanda",     "Latn", "ltr", "one_other"),
    "ny":      ("Chichewa",           "Chichewa",         "Latn", "ltr", "one_other"),
    "mg":      ("Malagasy",           "Malagasy",         "Latn", "ltr", "zero_one_other"),
    "ht":      ("Haitian Creole",     "Kreyòl ayisyen",   "Latn", "ltr", "one_other"),
    "is":      ("Icelandic",          "Íslenska",         "Latn", "ltr", "one_other"),
    "fo":      ("Faroese",            "Føroyskt",         "Latn", "ltr", "one_other"),
    "lb":      ("Luxembourgish",      "Lëtzebuergesch",   "Latn", "ltr", "one_other"),
    "dv":      ("Dhivehi",            "ދިވެހި",             "Thaa", "rtl", "one_other"),
    "dz":      ("Dzongkha",           "རྫོང་ཁ",              "Tibt", "ltr", "other"),
    "ti":      ("Tigrinya",           "ትግርኛ",              "Ethi", "ltr", "zero_one_other"),
    "ku":      ("Kurdish",            "Kurdî",            "Latn", "ltr", "one_other"),
    "fil":     ("Filipino",           "Filipino",         "Latn", "ltr", "one_other"),

    # ── smaller / regional; endonyms unverified are None ──────────────────────
    "ay":      ("Aymara",             None, "Latn", "ltr", "one_other"),
    "ber":     ("Berber",             None, "Latn", "ltr", "one_other"),
    "bi":      ("Bislama",            None, "Latn", "ltr", "one_other"),
    "ch":      ("Chamorro",           None, "Latn", "ltr", "one_other"),
    "cr":      ("Cree",               None, "Cans", "ltr", "one_other"),
    "fj":      ("Fijian",             None, "Latn", "ltr", "one_other"),
    "gn":      ("Guarani",            None, "Latn", "ltr", "one_other"),
    "gv":      ("Manx",               None, "Latn", "ltr", "one_other"),
    "ho":      ("Hiri Motu",          None, "Latn", "ltr", "one_other"),
    "kl":      ("Greenlandic",        None, "Latn", "ltr", "one_other"),
    "la":      ("Latin",              None, "Latn", "ltr", "one_other"),
    "mh":      ("Marshallese",        None, "Latn", "ltr", "one_other"),
    "mi":      ("Maori",              None, "Latn", "ltr", "one_other"),
    "na":      ("Nauruan",            None, "Latn", "ltr", "one_other"),
    "nb":      ("Norwegian Bokmal",   None, "Latn", "ltr", "one_other"),
    "nn":      ("Norwegian Nynorsk",  None, "Latn", "ltr", "one_other"),
    "nd":      ("North Ndebele",      None, "Latn", "ltr", "one_other"),
    "nr":      ("South Ndebele",      None, "Latn", "ltr", "one_other"),
    "pap":     ("Papiamento",         None, "Latn", "ltr", "one_other"),
    "pau":     ("Palauan",            None, "Latn", "ltr", "one_other"),
    "qu":      ("Quechua",            None, "Latn", "ltr", "one_other"),
    "rm":      ("Romansh",            None, "Latn", "ltr", "one_other"),
    "rn":      ("Kirundi",            None, "Latn", "ltr", "one_other"),
    "sg":      ("Sango",              None, "Latn", "ltr", "one_other"),
    "sm":      ("Samoan",             None, "Latn", "ltr", "one_other"),
    "ss":      ("Swati",              None, "Latn", "ltr", "one_other"),
    "tet":     ("Tetum",              None, "Latn", "ltr", "one_other"),
    "tn":      ("Tswana",             None, "Latn", "ltr", "one_other"),
    "to":      ("Tongan",             None, "Latn", "ltr", "one_other"),
    "tpi":     ("Tok Pisin",          None, "Latn", "ltr", "one_other"),
    "ts":      ("Tsonga",             None, "Latn", "ltr", "one_other"),
    "ve":      ("Venda",              None, "Latn", "ltr", "one_other"),
}

# ─── Ladder A: the ETL's 115 codes -> REGISTERED product locales ──────────────
# Each rule states what it does and why. The generator prints the arithmetic;
# no number in any document is asserted by hand.
NORWEGIAN_VARIANTS = ["nb", "nn"]          # collapse into `no`
NOT_PRODUCT = ["la", "cr"]                 # liturgical / not a UI target
ZH_SPLIT = ["zh-Hans", "zh-Hant"]          # `zh` is not a product tag

# ─── Ladder B: REGISTERED -> TRANSLATED (a capability question, not a
# registration one). A locale is translated when we can actually produce and
# maintain a catalog for it. Two gates, both honest about being judgement:
#   - the machine-translation tail must plausibly cover it
#   - or it is the sole official language of a jurisdiction, in which case a
#     speaker floor must never exclude it
# Anything else stays REGISTERED and DISPLAY-ONLY: its name renders, its
# catalog falls back to English, and nobody is told it is translated.
SOLE_OFFICIAL_EXEMPT = ["is", "dv", "dz", "mt", "lb", "fo", "kl", "ti", "si", "km", "lo", "my", "ka", "hy", "mn"]

# Long-tail codes with no usable MT pair today: registered, display-only.
NO_MT_PAIR = [
    "ay", "ber", "bi", "ch", "fj", "gn", "gv", "ho", "mh", "mi", "na", "nd", "nr",
    "pap", "pau", "qu", "rm", "rn", "sg", "sm", "ss", "tet", "tn", "to", "tpi",
    "ts", "ve", "st", "sn", "ny", "ku", "tk", "tg",
]

TIER_1 = ["en", "es", "ar", "zh-Hans", "hi"]   # what the app ships today


def etl_codes() -> list[str]:
    """The 115 codes in scripts/etl/languages.py — this file's only input."""
    sys.path.insert(0, str(ETL_REGISTRY.parent))
    import languages as etl  # type: ignore

    codes: set[str] = set()
    for v in etl.ISO3_LANGUAGES.values():
        codes.update(v)
    for v in etl.REGION_FALLBACKS.values():
        codes.update(v if isinstance(v, list) else [v])
    return sorted(codes)


def derive() -> dict:
    source = etl_codes()

    registered = set(source)
    steps = [("ETL source codes", len(source), len(registered))]

    registered -= set(NORWEGIAN_VARIANTS)
    steps.append((f"collapse Norwegian {NORWEGIAN_VARIANTS} into 'no'", -len(NORWEGIAN_VARIANTS), len(registered)))

    registered -= set(NOT_PRODUCT)
    steps.append((f"drop non-product {NOT_PRODUCT}", -len(NOT_PRODUCT), len(registered)))

    had_zh = "zh" in registered
    registered.discard("zh")
    registered.update(ZH_SPLIT)
    delta = (len(ZH_SPLIT) - 1) if had_zh else len(ZH_SPLIT)
    steps.append((f"split 'zh' into {ZH_SPLIT} (distinct scripts)", +delta, len(registered)))

    unknown = sorted(c for c in registered if c not in L)
    registered -= set(unknown)
    if unknown:
        steps.append((f"no registry row yet: {unknown}", -len(unknown), len(registered)))

    display_only = (set(NO_MT_PAIR) & registered) - set(SOLE_OFFICIAL_EXEMPT) - set(TIER_1)
    translated = registered - display_only

    rows = []
    for code in sorted(registered):
        english, endonym, script, direction, plural = L[code]
        rows.append({
            "code": code,
            "english_name": english,
            "endonym": endonym or english,
            "endonym_verified": endonym is not None,
            "script": script,
            "dir": direction,
            "plural_family": plural,
            "plural_categories": PLURAL_FAMILIES[plural],
            "tier": 1 if code in TIER_1 else (2 if code in translated else 3),
            "translated": code in translated,
            "enabled": code in TIER_1,   # enabled = a catalog exists TODAY
        })

    return {
        "steps": steps,
        "rows": rows,
        "counts": {
            "source": len(source),
            "registered": len(registered),
            "translated": len(translated),
            "display_only": len(display_only),
            "enabled": len(TIER_1),
            "endonyms_unverified": sum(1 for r in rows if not r["endonym_verified"]),
        },
    }


# ─── Generators ───────────────────────────────────────────────────────────────
BANNER_PHP = """<?php

/*
|--------------------------------------------------------------------------
| GENERATED FILE — DO NOT EDIT
|--------------------------------------------------------------------------
|
| Written by scripts/i18n/languages.py. Edit that file and re-run:
|
|     python3 scripts/i18n/languages.py --write
|
| This is the PHP half of THE locale registry. Before it existed the same
| list was hand-maintained in five places and they had already drifted.
| Anything that needs to know what locales exist reads it from here.
|
|   tier 1  shipping today (a catalog exists)
|   tier 2  intended for translation
|   tier 3  registered and display-only — the name renders, the copy falls
|           back to English, and nobody is told it is translated
*/

"""

BANNER_JS = """/* ============================================================================
   GENERATED FILE — DO NOT EDIT
   Written by scripts/i18n/languages.py. Edit that and re-run:
       python3 scripts/i18n/languages.py --write

   The JS half of THE locale registry, plus the CLDR plural selectors vue-i18n
   needs. vue-i18n's built-in selector resolves at most three forms — Arabic
   has six — so without these, an Arabic plural silently renders the wrong
   branch while a form-counting check reports it as correct.
   ============================================================================ */

"""

# vue-i18n pluralRules: (choice, choicesLength, orgRule) => index into the
# `|`-separated message. Implemented per CLDR family.
JS_RULES = """
const RULES = {
    other: () => 0,

    one_other: (n) => (n === 1 ? 0 : 1),

    /* 0 and 1 both take the singular (fr, pt, hi, bn, fa, am, …) */
    zero_one_other: (n) => (n === 0 || n === 1 ? 0 : 1),

    one_two_other: (n) => (n === 1 ? 0 : n === 2 ? 1 : 2),

    /* ru/uk/be/sr/hr/bs/sl */
    slavic: (n) => {
        const m10 = n % 10;
        const m100 = n % 100;
        if (m10 === 1 && m100 !== 11) return 0;
        if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 1;
        return 2;
    },

    polish: (n) => {
        const m10 = n % 10;
        const m100 = n % 100;
        if (n === 1) return 0;
        if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 1;
        return 2;
    },

    czech: (n) => (n === 1 ? 0 : n >= 2 && n <= 4 ? 1 : 2),

    lithuanian: (n) => {
        const m10 = n % 10;
        const m100 = n % 100;
        if (m10 === 1 && (m100 < 11 || m100 > 19)) return 0;
        if (m10 >= 2 && m10 <= 9 && (m100 < 11 || m100 > 19)) return 1;
        return 2;
    },

    latvian: (n) => (n % 10 === 0 || (n % 100 >= 11 && n % 100 <= 19) ? 0 : n % 10 === 1 && n % 100 !== 11 ? 1 : 2),

    romanian: (n) => (n === 1 ? 0 : n === 0 || (n % 100 >= 1 && n % 100 <= 19) ? 1 : 2),

    maltese: (n) => {
        const m100 = n % 100;
        if (n === 1) return 0;
        if (n === 0 || (m100 >= 2 && m100 <= 10)) return 1;
        if (m100 >= 11 && m100 <= 19) return 2;
        return 3;
    },

    irish: (n) => (n === 1 ? 0 : n === 2 ? 1 : n >= 3 && n <= 6 ? 2 : n >= 7 && n <= 10 ? 3 : 4),

    /* Arabic: zero | one | two | few | many | other — six, not three. */
    arabic: (n) => {
        const m100 = n % 100;
        if (n === 0) return 0;
        if (n === 1) return 1;
        if (n === 2) return 2;
        if (m100 >= 3 && m100 <= 10) return 3;
        if (m100 >= 11 && m100 <= 99) return 4;
        return 5;
    },
};

/**
 * vue-i18n pluralRules map. Clamped to the number of forms the message
 * actually supplies, so a message written with fewer branches than its locale
 * has categories degrades to the last one instead of rendering nothing.
 */
export const pluralRules = Object.fromEntries(
    LOCALES.map((l) => [
        l.code,
        (choice, choicesLength) => {
            const idx = RULES[l.pluralFamily](Math.abs(Number(choice)));
            return Math.min(idx, Math.max(choicesLength - 1, 0));
        },
    ]),
);

/** Codes with a catalog today. */
export const ENABLED = LOCALES.filter((l) => l.enabled).map((l) => l.code);

/** Codes we intend to carry a full catalog for. */
export const TRANSLATED = LOCALES.filter((l) => l.translated).map((l) => l.code);

/** RTL codes — the ONLY source for direction. */
export const RTL = LOCALES.filter((l) => l.dir === 'rtl').map((l) => l.code);
"""


def render_php(d: dict) -> str:
    out = [BANNER_PHP, "return [\n"]
    out.append("    'counts' => " + php_arr(d["counts"]) + ",\n\n")
    out.append("    'locales' => [\n")
    for r in d["rows"]:
        out.append(
            f"        '{r['code']}' => ["
            f"'name' => {php_str(r['english_name'])}, "
            f"'endonym' => {php_str(r['endonym'])}, "
            f"'dir' => '{r['dir']}', "
            f"'script' => '{r['script']}', "
            f"'plural' => '{r['plural_family']}', "
            f"'tier' => {r['tier']}, "
            f"'translated' => {'true' if r['translated'] else 'false'}, "
            f"'enabled' => {'true' if r['enabled'] else 'false'}],\n"
        )
    out.append("    ],\n];\n")
    return "".join(out)


def php_str(s: str) -> str:
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def php_arr(d: dict) -> str:
    inner = ", ".join(f"'{k}' => {v}" for k, v in d.items())
    return "[" + inner + "]"


def render_js(d: dict) -> str:
    rows = ",\n".join(
        "    { "
        f"code: {json.dumps(r['code'])}, "
        f"name: {json.dumps(r['english_name'])}, "
        f"endonym: {json.dumps(r['endonym'])}, "
        f"dir: {json.dumps(r['dir'])}, "
        f"script: {json.dumps(r['script'])}, "
        f"pluralFamily: {json.dumps(r['plural_family'])}, "
        f"tier: {r['tier']}, "
        f"translated: {str(r['translated']).lower()}, "
        f"enabled: {str(r['enabled']).lower()}"
        " }"
        for r in d["rows"]
    )
    return BANNER_JS + "export const LOCALES = [\n" + rows + ",\n];\n" + JS_RULES


# ─── Main ─────────────────────────────────────────────────────────────────────
def main() -> int:
    ap = argparse.ArgumentParser(description="THE locale registry — generates both artifacts.")
    ap.add_argument("--write", action="store_true", help="emit config/locales.php + the JS registry")
    ap.add_argument("--check", action="store_true", help="exit 1 if the generated files are stale")
    ap.add_argument("--json", action="store_true", help="dump the resolved registry")
    args = ap.parse_args()

    d = derive()
    php, js = render_php(d), render_js(d)

    if args.json:
        print(json.dumps(d, indent=2, ensure_ascii=False))
        return 0

    if args.check:
        stale = []
        for path, want in ((OUT_PHP, php), (OUT_JS, js)):
            have = path.read_text(encoding="utf-8") if path.exists() else None
            if have != want:
                stale.append(path.relative_to(ROOT).as_posix())
        if stale:
            print("STALE generated files (re-run with --write):", ", ".join(stale))
            return 1
        print("generated locale artifacts are current")
        return 0

    if args.write:
        OUT_PHP.parent.mkdir(parents=True, exist_ok=True)
        OUT_PHP.write_text(php, encoding="utf-8")
        OUT_JS.write_text(js, encoding="utf-8")

    c = d["counts"]
    print("\nCGA locale registry")
    print("===================")
    print("\nLadder A — the ETL's codes to REGISTERED product locales:")
    for label, delta, running in d["steps"]:
        sign = f"{delta:+d}" if isinstance(delta, int) and label != "ETL source codes" else str(delta)
        print(f"  {label:<48} {sign:>5}  -> {running}")
    print(f"\n  REGISTERED   {c['registered']}")
    print("\nLadder B — REGISTERED to TRANSLATED (a capability question):")
    print(f"  display-only (no MT pair, not sole-official)      -{c['display_only']}")
    print(f"\n  TRANSLATED   {c['translated']}")
    print(f"  ENABLED      {c['enabled']}  (a catalog exists today)")
    print(f"\n  endonyms not yet verified: {c['endonyms_unverified']}"
          " — these render their English name until a speaker confirms them")
    if args.write:
        print(f"\nwrote {OUT_PHP.relative_to(ROOT).as_posix()}")
        print(f"wrote {OUT_JS.relative_to(ROOT).as_posix()}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

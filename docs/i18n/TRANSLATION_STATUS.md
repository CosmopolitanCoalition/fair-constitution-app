# Translation status

Generated 2026-09-16 by `python3 scripts/i18n/status_table.py`. English is the source language; every other language below is a machine draft unless the last column says otherwise.

How to read a row:

- **Enabled** means the language is in the app's switcher today. All target languages are enabled (operator ruling 2026-09-16): the catalogues are code and ship on by default.
- **Coverage** is what the machine gate measured (`scripts/i18n/check.mjs`): the share of English strings that have a value in this language. A missing string shows in English.
- **Reading review** is a sample of the drafts read by a reviewer model with a second model checking its findings: sample size, share judged correct, strings that would mislead a user, and a grade (A = 90 percent clean and no misleading string, B = 70 percent and at most one, C = below). It measures quality; it does not review every string. Method: [docs/i18n/METHODS.md](METHODS.md).
- **Native reader** is whether a fluent human has signed off. The queue for that is the app's translation board (`/system/translations`), open to readers of each language.

Latest reading review: `docs/audits/2026-09-16/L10N_SPOTCHECK.md`.

| Language | Code | Enabled | Coverage | Sample | Clean | Misleading | Grade | Native reader |
|---|---|---|---|---|---|---|---|---|
| Bulgarian (Български) | bg | yes | 99.3% | 10 | 100% | 0 | A | not yet |
| Croatian (Hrvatski) | hr | yes | 99.3% | 10 | 100% | 0 | A | not yet |
| Danish (Dansk) | da | yes | 99.3% | 10 | 100% | 0 | A | not yet |
| Indonesian (Bahasa Indonesia) | id | yes | 99.3% | 30 | 100% | 0 | A | not yet |
| Javanese (Basa Jawa) | jv | yes | 99.3% | 10 | 100% | 0 | A | not yet |
| Korean (한국어) | ko | yes | 99.3% | 30 | 100% | 0 | A | not yet |
| Lao (ລາວ) | lo | yes | 99.3% | 10 | 100% | 0 | A | not yet |
| Macedonian (Македонски) | mk | yes | 99.3% | 10 | 100% | 0 | A | not yet |
| Malay (Bahasa Melayu) | ms | yes | 99.3% | 30 | 100% | 0 | A | not yet |
| Norwegian (Norsk) | no | yes | 99.3% | 10 | 100% | 0 | A | not yet |
| Swedish (Svenska) | sv | yes | 99.3% | 10 | 100% | 0 | A | not yet |
| French (Français) | fr | yes | 99.3% | 100 | 98% | 0 | A | not yet |
| Burmese (မြန်မာ) | my | yes | 99.3% | 30 | 97% | 0 | A | not yet |
| Japanese (日本語) | ja | yes | 99.3% | 30 | 97% | 0 | A | not yet |
| Thai (ไทย) | th | yes | 99.3% | 30 | 97% | 0 | A | not yet |
| Uzbek (Oʻzbek) | uz | yes | 99.3% | 30 | 97% | 0 | A | not yet |
| Polish (Polski) | pl | yes | 99.3% | 100 | 96% | 0 | A | not yet |
| Russian (Русский) | ru | yes | 99.3% | 100 | 96% | 0 | A | not yet |
| Arabic (العربية) | ar | yes | 100.0% | 100 | 94% | 0 | A | not yet |
| Spanish (Español) | es | yes | 100.0% | 100 | 94% | 0 | A | not yet |
| Urdu (اردو) | ur | yes | 99.3% | 30 | 93% | 0 | A | not yet |
| Vietnamese (Tiếng Việt) | vi | yes | 99.3% | 30 | 93% | 0 | A | not yet |
| Albanian (Shqip) | sq | yes | 99.3% | 10 | 90% | 0 | A | not yet |
| Armenian (Հայերեն) | hy | yes | 99.3% | 10 | 90% | 0 | A | not yet |
| Chinese (Simplified) (中文（简体）) | zh-Hans | yes | 100.0% | 60 | 90% | 0 | A | not yet |
| Czech (Čeština) | cs | yes | 99.3% | 10 | 90% | 0 | A | not yet |
| Filipino (Filipino) | fil | yes | 99.3% | 10 | 90% | 0 | A | not yet |
| Finnish (Suomi) | fi | yes | 99.1% | 10 | 90% | 0 | A | not yet |
| Hebrew (עברית) | he | yes | 99.3% | 10 | 90% | 0 | A | not yet |
| Kazakh (Қазақша) | kk | yes | 99.3% | 10 | 90% | 0 | A | not yet |
| Malayalam (മലയാളം) | ml | yes | 99.3% | 30 | 90% | 0 | A | not yet |
| Nepali (नेपाली) | ne | yes | 99.3% | 10 | 90% | 0 | A | not yet |
| Sinhala (සිංහල) | si | yes | 99.3% | 10 | 90% | 0 | A | not yet |
| Somali (Soomaali) | so | yes | 99.3% | 10 | 90% | 0 | A | not yet |
| Sundanese (Basa Sunda) | su | yes | 99.3% | 10 | 90% | 0 | A | not yet |
| Ukrainian (Українська) | uk | yes | 99.3% | 30 | 97% | 1 | B | not yet |
| Italian (Italiano) | it | yes | 99.3% | 100 | 95% | 1 | B | not yet |
| German (Deutsch) | de | yes | 99.2% | 30 | 93% | 1 | B | not yet |
| Persian (فارسی) | fa | yes | 99.3% | 30 | 93% | 1 | B | not yet |
| Kannada (ಕನ್ನಡ) | kn | yes | 99.3% | 30 | 90% | 1 | B | not yet |
| Tamil (தமிழ்) | ta | yes | 99.3% | 29 | 90% | 0 | B | not yet |
| Marathi (मराठी) | mr | yes | 99.3% | 30 | 87% | 0 | B | not yet |
| Gujarati (ગુજરાતી) | gu | yes | 99.3% | 30 | 83% | 0 | B | not yet |
| Swahili (Kiswahili) | sw | yes | 99.3% | 30 | 83% | 0 | B | not yet |
| Afrikaans (Afrikaans) | af | yes | 99.3% | 10 | 80% | 0 | B | not yet |
| Amharic (አማርኛ) | am | yes | 99.3% | 10 | 80% | 0 | B | not yet |
| Azerbaijani (Azərbaycan) | az | yes | 99.3% | 10 | 80% | 0 | B | not yet |
| Bosnian (Bosanski) | bs | yes | 99.3% | 10 | 80% | 0 | B | not yet |
| Dutch (Nederlands) | nl | yes | 99.3% | 10 | 80% | 0 | B | not yet |
| Georgian (ქართული) | ka | yes | 99.3% | 10 | 80% | 1 | B | not yet |
| Greek (Ελληνικά) | el | yes | 99.3% | 10 | 80% | 0 | B | not yet |
| Latvian (Latviešu) | lv | yes | 99.3% | 10 | 80% | 0 | B | not yet |
| Slovak (Slovenčina) | sk | yes | 99.3% | 10 | 80% | 1 | B | not yet |
| Telugu (తెలుగు) | te | yes | 99.3% | 30 | 80% | 1 | B | not yet |
| Zulu (isiZulu) | zu | yes | 99.2% | 10 | 80% | 0 | B | not yet |
| Romanian (Română) | ro | yes | 99.3% | 11 | 73% | 1 | B | not yet |
| Galician (Galego) | gl | yes | 99.3% | 10 | 70% | 0 | B | not yet |
| Hungarian (Magyar) | hu | yes | 99.3% | 10 | 70% | 1 | B | not yet |
| Khmer (ភាសាខ្មែរ) | km | yes | 99.3% | 10 | 70% | 0 | B | not yet |
| Serbian (Српски) | sr | yes | 99.3% | 10 | 70% | 1 | B | not yet |
| Bengali (বাংলা) | bn | yes | 99.3% | 30 | 93% | 2 | C | not yet |
| Turkish (Türkçe) | tr | yes | 99.3% | 100 | 92% | 2 | C | not yet |
| Hindi (हिन्दी) | hi | yes | 100.0% | 100 | 90% | 2 | C | not yet |
| Portuguese (Português) | pt | yes | 99.3% | 100 | 90% | 2 | C | not yet |
| Icelandic (Íslenska) | is | yes | 99.3% | 10 | 70% | 2 | C | not yet |
| Catalan (Català) | ca | yes | 99.3% | 10 | 60% | 0 | C | not yet |
| Pashto (پښتو) | ps | yes | 99.3% | 10 | 60% | 3 | C | not yet |
| Estonian (Eesti) | et | yes | 99.3% | 11 | 55% | 2 | C | not yet |
| Mongolian (Монгол) | mn | yes | 99.3% | 10 | 50% | 1 | C | not yet |
| Slovenian (Slovenščina) | sl | yes | 99.3% | 10 | 50% | 3 | C | not yet |
| Basque (Euskara) | eu | yes | 99.3% | 10 | 40% | 5 | C | not yet |
| Lithuanian (Lietuvių) | lt | yes | 99.3% | 10 | 40% | 1 | C | not yet |
| Maltese (Malti) | mt | yes | 99.3% | 10 | 40% | 4 | C | not yet |
| Irish (Gaeilge) | ga | yes | 99.3% | 10 | 30% | 5 | C | not yet |
| Welsh (Cymraeg) | cy | yes | 99.3% | 10 | 20% | 4 | C | not yet |

Languages: 75 targets. Grades: A 35, B 25, C 15.

A C grade means the sample read badly, not that the language is absent: every string is present as a draft, and a native reader can correct it in the app. C-grade languages are the first to need one.

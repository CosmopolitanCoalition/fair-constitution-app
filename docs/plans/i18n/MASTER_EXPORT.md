# Master string export and import (LG-0)

The master export turns the strings a locale still needs into self describing JSON
files. Drop the files into any competent AI. It returns equivalent translations.
Import validates them and writes them into the catalogs. This scales translation
up fast without a GPU or an API key.

Scripts:
- `scripts/i18n/export_master.py` writes the files.
- `scripts/i18n/import_translated.py` reads the translated files back in.
- `scripts/i18n/translate_catalog.py` also has an `ollama` provider for a local
  first pass without leaving the box.

The export directory `storage/app/i18n-export/` is gitignored. It is machine
state, not a repo artifact.

## What the export contains

One file per namespace, split into chunks of at most 250 strings. Each file
carries a context header so the AI has the same context this repo's own
providers get.

```
{
  "_meta": {
    "locale": "es",
    "language": "Spanish",
    "native_name": "Espanol",
    "direction": "ltr",
    "namespace": "auth",
    "surface": "Sign-in, registration, and operator-login screens.",
    "used_by": ["resources/js/Pages/Auth/Login.vue", ...],
    "glossary": {"Jurisdiction": "Jurisdiccion", ...},
    "instructions": "You are translating ... Return only a JSON object ...",
    "string_count": 12
  },
  "strings": {
    "auth_login.account_sign_in": "Account sign-in",
    ...
  }
}
```

A string is exported when the locale is missing it: the namespace file is absent,
the key is absent, or the value is still the English source. A locked or human
reviewed string is never exported. A pure citation, ID token, or number is not
exported. It needs no AI and the ordinary machine pass copies it through.

## The procedure

1. Export. Pick one locale or all six conference locales.

```bash
python3 scripts/i18n/export_master.py --locale es
python3 scripts/i18n/export_master.py --locale all
```

The files land under `storage/app/i18n-export/<locale>/`. A summary table prints
namespaces, strings, and files per locale.

2. Translate. Give one file at a time to the AI. The file's own
`_meta.instructions` is a complete prompt. The AI returns a JSON object of the
same shape with translated values. Save each returned file.

3. Import. Point the importer at a file or a whole locale directory.

```bash
python3 scripts/i18n/import_translated.py storage/app/i18n-export/es
python3 scripts/i18n/import_translated.py translated_auth.json --dry-run
```

Import validates every string with the same QA the machine pass uses:
placeholder parity, ID token and citation preservation, and a vue-i18n compile
check. It refuses a value identical to the English source, a locked or human
reviewed string, and a key that does not exist in the English namespace. It
prints accepted and rejected counts with a reason per rejected key. It is
idempotent. Accepted strings are marked in the meta tree as an AI first pass with
the source file name.

4. Verify. Run the gate and the coverage test.

```bash
node scripts/i18n/check.mjs
node --experimental-vm-modules --test tests/js/i18nCoverage.test.mjs
```

`check.mjs` C5 is the authoritative vue-i18n compile gate. The coverage test
prints the per locale, per namespace gap and asserts full parity.

## Local model recommendation

A local AI is accurate enough for a first pass and it is free and private. It is
the preferred first pass. Use Ollama, already installed on this box at
`http://127.0.0.1:11434`.

### Which model

The GPU is an RTX 2070 with 8 GB. Pick a model whose weights fit the card so it
runs on the GPU, not the CPU.

- `llama3.1:8b` (about 4.9 GB) is the default. It is the largest installed
  instruct model that fits 8 GB with room for the context. It is the recommended
  first pass.
- `qwen2.5:7b-instruct-q4_K_M` (about 4.7 GB) fits and is a strong alternative,
  especially for Chinese.
- `qwen3.5:4b` (about 3.4 GB) and `qwen2.5:3b` (about 1.9 GB) fit with more head
  room and run faster. Use them on a smaller card or when speed matters more than
  quality.
- `gemma4` (about 9.6 GB) does not fit 8 GB. It spills to the CPU and runs slow.
  Do not use it for a bulk pass on this box.

Set a different model with `--model` or the `OLLAMA_MODEL` environment variable.
Set a different server with `OLLAMA_HOST`. A bind address such as `0.0.0.0:11434`
is handled: the client dials `127.0.0.1` and adds the scheme.

### Why an instruct LLM over NLLB-200

NLLB-200-600M is a sentence level machine translation model. It translates prose
well but it does not read a context header, it does not honor a glossary, and it
has no notion of UI tone or reserved syntax. An instruct LLM reads the same
context the export carries: the surface description, the pages that use the
string, the glossary, and the rules about placeholders and citations. On short
UI strings, where context decides the right word, the instruct model with the
header is more accurate. Both run locally and free. The export masks every
reserved token before the model sees it and restores it after, and the QA gate
rejects any string that loses a placeholder, an ID token, or a citation, so a
weaker model degrades to more rejections, never to a broken catalog.

### How to compare two models on a 50 string sample

Run the smoke against each model. It translates real strings and prints the QA
verdict. It writes nothing.

```bash
python3 scripts/i18n/translate_catalog.py --locale es --provider ollama --model llama3.1:8b --smoke 50
python3 scripts/i18n/translate_catalog.py --locale es --provider ollama --model qwen2.5:7b-instruct-q4_K_M --smoke 50
```

Compare the pass counts and read the printed translations. Pick the model with
the higher pass count and the better reading. The first call to a cold model
loads it into VRAM and takes one to three minutes. Later calls are fast while the
model stays resident.

### Running a full local pass

A full local pass is the operator's GO. The full pass is gated.

```bash
# a bounded run needs no gate
python3 scripts/i18n/translate_catalog.py --locale es --provider ollama --limit 100

# a full pass needs the GO
python3 scripts/i18n/translate_catalog.py --locale es --provider ollama --yes-run

# several locales at once, watched
python3 scripts/i18n/translate_run.py --locales es,pt,fr --provider ollama --model llama3.1:8b
```

The machine pass writes into the catalogs and marks each string in the meta tree.
Then run `check.mjs` and the coverage test as above.

## Self-tests

Both new scripts carry a self-test.

```bash
python3 scripts/i18n/export_master.py --self-test
python3 scripts/i18n/import_translated.py --self-test
```

The export shape is pinned by a DB-free node test that runs the exporter into a
temp directory.

```bash
node --experimental-vm-modules --test tests/js/i18nExportShape.test.mjs
```

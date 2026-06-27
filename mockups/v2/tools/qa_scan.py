#!/usr/bin/env python3
"""CGA mockups QA scans (QA section 15 subset).

Run from anywhere: python mockups/tools/qa_scan.py
Checks, over every .html/.css/.js under mockups/ (excluding the verbatim
token file and brand assets):
  1. zero hex color literals outside assets/css/colors_and_type.css
  2. zero physical left/right CSS properties (logical properties only)
  3. zero emoji codepoints
  4. every internal href/src resolves to an existing file
Exits non-zero on any failure; prints a per-check summary.
"""
import os, re, sys, unicodedata

ROOT = os.path.normpath(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
# Skipped by BASENAME so the self-contained v2 copy (v2/assets/css/…) is covered
# too: these files DEFINE the design tokens + @font-face glyphs — hex palette and
# special glyphs are by design here, not violations.
SKIP_FILE_NAMES = {'colors_and_type.css', 'fonts.css'}

failures = []

def rel(p):
    return os.path.relpath(p, ROOT).replace('\\', '/')

def walk(exts):
    for dirpath, dirnames, filenames in os.walk(ROOT):
        # brand SVG/PNG sources may carry hex — skip any assets/img dir (v1 or v2 copy)
        if '/assets/img' in '/' + os.path.relpath(dirpath, ROOT).replace('\\', '/'):
            continue
        for fn in filenames:
            if os.path.splitext(fn)[1].lower() in exts:
                if fn in SKIP_FILE_NAMES:
                    continue
                yield os.path.join(dirpath, fn)

# 1. hex literals ------------------------------------------------------------
HEX = re.compile(r'(?<![&\w])#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{1,5})?\b')
for p in walk({'.css', '.html', '.js'}):
    text = open(p, encoding='utf-8').read()
    # ignore anchors (#step-1), ids in hrefs, and #i- sprite refs: require hex chars only
    for m in HEX.finditer(text):
        tok = m.group(0)
        if re.fullmatch(r'#[0-9a-fA-F]{3}|#[0-9a-fA-F]{4}|#[0-9a-fA-F]{6}|#[0-9a-fA-F]{8}', tok):
            failures.append(f'HEX  {rel(p)}: {tok}')

# 2. physical properties -----------------------------------------------------
PHYS = re.compile(r'\b(?:margin|padding|border|inset)-(?:left|right)\s*:'
                  r'|\btext-align\s*:\s*(?:left|right)\b'
                  r'|\bfloat\s*:\s*(?:left|right)\b'
                  r'|\b(?:left|right)\s*:\s*[^;}{]+[;}]')
for p in walk({'.css', '.html'}):
    for i, line in enumerate(open(p, encoding='utf-8'), 1):
        m = PHYS.search(line)
        if m:
            failures.append(f'PHYS {rel(p)}:{i}: {m.group(0).strip()}')

# 3. emoji -------------------------------------------------------------------
def is_emoji(ch):
    cp = ord(ch)
    return (0x1F000 <= cp <= 0x1FAFF) or (0x2600 <= cp <= 0x27BF) or cp in (0x2B50, 0x2B55) or (0xFE00 <= cp <= 0xFE0F)

for p in walk({'.css', '.html', '.js', '.json', '.md'}):
    text = open(p, encoding='utf-8').read()
    for ch in text:
        if is_emoji(ch):
            failures.append(f'EMOJI {rel(p)}: U+{ord(ch):04X} {unicodedata.name(ch, "?")}')
            break

# 4. internal links ----------------------------------------------------------
LINK = re.compile(r'(?:href|src)\s*=\s*"([^"]+)"')
SCRIPT_BLOCK = re.compile(r'(<script\b[^>]*>)(.*?)(</script>)', re.S | re.I)
for p in walk({'.html'}):
    base = os.path.dirname(p)
    text = open(p, encoding='utf-8').read()
    # JS builds links at runtime through CGA.state.link() (verified by the
    # coverage page's live dead-link scan) — only static markup is checked here.
    text = SCRIPT_BLOCK.sub(lambda m: m.group(1) + '\n' * m.group(2).count('\n') + m.group(3), text)
    for i, line in enumerate(text.splitlines(), 1):
        for target in LINK.findall(line):
            if re.match(r'^(https?:|mailto:|#|javascript:|data:)', target):
                continue
            t = target.split('?')[0].split('#')[0]
            if not t:
                continue
            if not os.path.exists(os.path.normpath(os.path.join(base, t))):
                failures.append(f'LINK {rel(p)}:{i}: {target}')

if failures:
    print(f'FAIL — {len(failures)} finding(s):')
    for f in failures:
        print(' ', f)
    sys.exit(1)
print('OK — hex / physical-properties / emoji / internal-link scans all clean.')

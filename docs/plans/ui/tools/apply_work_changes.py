#!/usr/bin/env python3
"""
CGA - docs/plans/ui/tools/apply_work_changes.py
Fold the operator's exported work changes into the one list.

The operator opens app_progress_rubric.html, sets a status and notes on rows of
the Work tab, clicks "Export changes" and pastes the block to the desk. The
desk saves the block to a file and runs:

    python3 docs/plans/ui/tools/apply_work_changes.py <block.txt>

The block format (written by the page):

    CGA WORK - operator changes

    [W-0283] Open every read surface to guests ...
      = moot
      notes: superseded by ...

Each entry is recorded in work_overrides.json (the durable record, keyed by
item id, so a re-run of migrate_to_work.py keeps it) and applied to work.json:
the status changes, a moot or done item leaves the order of operations (order
null, phase done), a re-opened item returns to its phase with the next free
order, and every change is written to the item's history with the date and
the operator's note. The page is then regenerated through gen_app_rubric.py
(--check must pass). Idempotent: applying the same block twice changes nothing
the second time.

Options:
  --date YYYY-MM-DD   history date (default: today)
  --dry-run           parse and report, write nothing
  --selftest          run the built-in checks
"""
import io
import json
import os
import re
import subprocess
import sys
import datetime

_HERE = os.path.dirname(os.path.abspath(__file__))
STATUSES = ['open', 'blocked', 'awaiting_go', 'deferred', 'done', 'moot']
ACTIONABLE = ['open', 'blocked', 'awaiting_go', 'deferred']
HEADER_RE = re.compile(r'^CGA WORK\s*[-—]\s*operator changes\s*$')
ITEM_RE = re.compile(r'^\[(W-\d{4})\]\s*(.*)$')


def parse_block(text):
    """Return [{id, title, status|None, notes}] from the pasted block."""
    lines = text.replace('\r\n', '\n').split('\n')
    out = []
    cur = None
    for raw in lines:
        line = raw.rstrip()
        if not line.strip():
            continue
        if HEADER_RE.match(line.strip()):
            continue
        m = ITEM_RE.match(line.strip())
        if m:
            cur = {'id': m.group(1), 'title': m.group(2).strip(), 'status': None, 'notes': ''}
            out.append(cur)
            continue
        if cur is None:
            continue
        s = line.strip()
        if s.startswith('= '):
            val = s[2:].strip()
            cur['status'] = None if val.startswith('(') else val
        elif s.startswith('notes:'):
            cur['notes'] = s[6:].strip()
        else:
            cur['notes'] = (cur['notes'] + ' ' + s).strip()
    return out


def load_overrides(path):
    if os.path.exists(path):
        return json.load(io.open(path, encoding='utf-8'))
    return {'items': {}}


def apply_overrides(work, overrides):
    """Apply work_overrides.json to a work dict in place. Returns the list of applied ids.

    Shared with migrate_to_work.py so a re-migration keeps the operator's changes.
    """
    by_id = {it['id']: it for it in work['items']}
    applied = []
    for wid, o in sorted(overrides.get('items', {}).items()):
        it = by_id.get(wid)
        if it is None:
            continue
        st = o.get('status')
        note = (o.get('note') or '').strip()
        date = o.get('date') or ''
        hist_note = 'Operator set %s.%s' % (st, (' ' + note) if note else '') if st else ('Operator note: ' + note)
        already = any(isinstance(h, dict) and h.get('note') == hist_note and h.get('date') == date for h in it.get('history', []))
        if st and st in STATUSES and it['status'] != st:
            it['status'] = st
            if st in ('done', 'moot'):
                it['order'] = None
                it['phase'] = 'done'
                if st == 'moot' and 'moot' not in (it.get('blocker') or ''):
                    pass
            else:
                if it.get('phase') == 'done' or it.get('order') is None:
                    live = [x['order'] for x in work['items'] if x.get('order') is not None]
                    it['order'] = (max(live) + 1) if live else 1
                    if it.get('phase') == 'done':
                        it['phase'] = o.get('phase') or 'P5'
        if not already and (st or note):
            it.setdefault('history', []).append({'date': date, 'note': hist_note})
        applied.append(wid)
    return applied


def main(argv):
    if '--selftest' in argv:
        return selftest()
    args = [a for a in argv if not a.startswith('--')]
    date = datetime.date.today().isoformat()
    if '--date' in argv:
        date = argv[argv.index('--date') + 1]
        args = [a for a in args if a != date]
    dry = '--dry-run' in argv
    if not args:
        print(__doc__)
        return 2
    text = io.open(args[0], encoding='utf-8').read()
    entries = parse_block(text)
    if not entries:
        print('no entries found in', args[0])
        return 1
    tools = _HERE
    work_path = os.path.join(tools, 'work.json')
    ovr_path = os.path.join(tools, 'work_overrides.json')
    work = json.load(io.open(work_path, encoding='utf-8'))
    by_id = {it['id']: it for it in work['items']}
    overrides = load_overrides(ovr_path)
    bad = []
    for e in entries:
        if e['id'] not in by_id:
            bad.append((e['id'], 'unknown id'))
        elif e['status'] and e['status'] not in STATUSES:
            bad.append((e['id'], 'unknown status ' + e['status']))
    if bad:
        for b in bad:
            print('REFUSED', b[0], b[1])
        return 1
    for e in entries:
        rec = overrides['items'].get(e['id'], {})
        if e['status']:
            rec['status'] = e['status']
        if e['notes']:
            rec['note'] = e['notes']
        rec['date'] = date
        rec['title'] = e['title']
        overrides['items'][e['id']] = rec
        print('%s %-9s %s | %s' % (e['id'], e['status'] or '(note)', e['title'][:70], e['notes'][:80]))
    if dry:
        print('DRY RUN: nothing written (%d entries)' % len(entries))
        return 0
    io.open(ovr_path, 'w', encoding='utf-8', newline='\n').write(json.dumps(overrides, ensure_ascii=False, indent=1) + '\n')
    applied = apply_overrides(work, overrides)
    io.open(work_path, 'w', encoding='utf-8', newline='\n').write(json.dumps(work, ensure_ascii=False, indent=2) + '\n')
    print('applied', len(applied), 'overrides; wrote', ovr_path, 'and', work_path)
    gen = os.path.join(tools, 'gen_app_rubric.py')
    r = subprocess.run([sys.executable, gen, '--check'], capture_output=True, text=True)
    print(r.stdout.strip() or r.stderr.strip())
    if r.returncode != 0:
        return r.returncode
    r = subprocess.run([sys.executable, gen], capture_output=True, text=True)
    print(r.stdout.strip() or r.stderr.strip())
    return r.returncode


def selftest():
    block = ('CGA WORK — operator changes\n\n[W-0001] First item\n  = moot\n  notes: superseded by ruling X\n\n'
             '[W-0002] Second item\n  = (no status change)\n  notes: keep, but later\n\n[W-0003] Third\n  = open\n')
    e = parse_block(block)
    assert [x['id'] for x in e] == ['W-0001', 'W-0002', 'W-0003'], e
    assert e[0]['status'] == 'moot' and e[0]['notes'] == 'superseded by ruling X'
    assert e[1]['status'] is None and e[1]['notes'] == 'keep, but later'
    assert e[2]['status'] == 'open'
    work = {'items': [
        {'id': 'W-0001', 'title': 'a', 'phase': 'P1', 'order': 1, 'status': 'open', 'history': []},
        {'id': 'W-0002', 'title': 'b', 'phase': 'P1', 'order': 2, 'status': 'open', 'history': []},
        {'id': 'W-0003', 'title': 'c', 'phase': 'done', 'order': None, 'status': 'done', 'history': []}]}
    ovr = {'items': {'W-0001': {'status': 'moot', 'note': 'superseded', 'date': 'd'},
                     'W-0002': {'note': 'keep', 'date': 'd'},
                     'W-0003': {'status': 'open', 'date': 'd'}}}
    apply_overrides(work, ovr)
    a, b, c = work['items']
    assert a['status'] == 'moot' and a['order'] is None and a['phase'] == 'done' and a['history'][-1]['note'] == 'Operator set moot. superseded'
    assert b['status'] == 'open' and b['order'] == 2 and b['history'][-1]['note'] == 'Operator note: keep'
    assert c['status'] == 'open' and c['order'] == 3 and c['phase'] == 'P5'
    apply_overrides(work, ovr)  # idempotent: no second history line
    assert len(a['history']) == 1 and len(b['history']) == 1 and len(c['history']) == 1
    print('apply_work_changes selftest OK')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))

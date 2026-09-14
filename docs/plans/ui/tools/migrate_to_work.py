# -*- coding: utf-8 -*-
"""Build work.json, the one consolidated work file, from the legacy inputs.

Inputs (all read-only):
  badged.json              screens, caps, debt rows
  wave4_data.py FLEET      waves and lane items
  DEMO_ACTION_PLAN.md      the open punch rows
  DEMO_REVIEW_REGISTER.md  the open review rows
  DEMO_COMPLETED_WORK.md   the closed punch and review rows, with evidence
  dispositions.json        the desk's ruling on each open row (optional)

Rule set (operator order: one list of work, one list of questions):
  Every closed row of every list becomes a done item, so the archive is
  complete. Open rows become work items per the dispositions. A moot or done
  disposition sets that status and records the reason in history. A duplicate
  disposition merges its sources into the carrier item. Each item keeps every
  original note as history. Ids are deterministic from the emission order over
  fixed inputs, so a re-run is idempotent.

  When dispositions.json is absent a STUB is generated in memory: every open row
  becomes a work item in phase P5 Backlog, ordered by list then index. The run
  prints a clear line that the stub was used.

Run: python3 docs/plans/ui/tools/migrate_to_work.py
Self-test: python3 docs/plans/ui/tools/migrate_to_work.py --selftest
"""
import json, os, re, sys, io, tempfile, shutil

_HERE = os.path.dirname(os.path.abspath(__file__))

ASOF_DEFAULT = '2026-09-14'
HEAD_DEFAULT = '4fc4538d'

# Source list order. Ids and orders follow this order, then row order within.
SRC_ORDER = ['screens', 'caps', 'debt', 'fleet', 'punch', 'review']
ACTIONABLE = ('open', 'blocked', 'awaiting_go', 'deferred')
DONE_PHASE = {'id': 'done', 'name': 'Done and moot', 'goal': 'Closed work, kept for the archive.'}
STUB_PHASE = {'id': 'P5', 'name': 'Backlog', 'goal': 'Open rows migrated as-is. The desk sequences them into phases.'}

# blocker -> status for a work disposition
BLOCKER_STATUS = {
    'none': 'open',
    'operator_go': 'awaiting_go',
    'ruling': 'awaiting_go',
    'translator': 'blocked',
    'linux_host': 'blocked',
    'cloud_box': 'blocked',
    'other': 'blocked',
}
LINK_RE = re.compile(r'\[([^\]]+)\]\(([^)]+)\)')


# ---------------------------------------------------------------------------
# markdown table parsing
# ---------------------------------------------------------------------------
def _is_sep(cells):
    return all(re.match(r'^:?-{2,}:?$', c) for c in cells if c != '')


def parse_tables(text):
    """Return contiguous table blocks: [{'header':[...], 'rows':[[...],...]}]."""
    blocks = []
    cur = None
    for line in text.splitlines():
        s = line.strip()
        if s.startswith('|'):
            cells = [c.strip() for c in s.strip('|').split('|')]
            if cur is None:
                cur = {'header': cells, 'rows': []}
            elif _is_sep(cells):
                continue
            else:
                cur['rows'].append(cells)
        else:
            if cur is not None:
                blocks.append(cur)
                cur = None
    if cur is not None:
        blocks.append(cur)
    return blocks


def _links(cell):
    return [{'label': m.group(1), 'href': m.group(2)} for m in LINK_RE.finditer(cell)]


def _plain(cell):
    """Strip markdown links to their label text for readable prose."""
    return LINK_RE.sub(lambda m: m.group(1), cell)


# ---------------------------------------------------------------------------
# loaders
# ---------------------------------------------------------------------------
def load_badged(path):
    d = json.load(open(path, encoding='utf-8'))
    return d['screens'], d['caps'], d['debt']


def parse_action_plan(path):
    """Open punch rows: header starts with 'ID', 3 columns (ID, build, done-when)."""
    text = open(path, encoding='utf-8').read()
    rows = []
    for blk in parse_tables(text):
        if blk['header'] and blk['header'][0] == 'ID':
            for r in blk['rows']:
                if len(r) >= 3 and r[0]:
                    rows.append({'id': r[0], 'detail': _plain(r[1]), 'done_when': _plain(r[2])})
    return rows


def parse_review_register(path):
    """Open review rows: header 'Review | Workflow | Dependency | Pass criterion'."""
    text = open(path, encoding='utf-8').read()
    rows = []
    for blk in parse_tables(text):
        if blk['header'] and blk['header'][0] == 'Review':
            for r in blk['rows']:
                if len(r) >= 4 and r[0]:
                    rows.append({'id': r[0], 'workflow': _plain(r[1]),
                                 'dependency': _plain(r[2]), 'criterion': _plain(r[3])})
    return rows


REVIEW_ID_RE = re.compile(r'^(R\d|S1|S3|L1|L2|D1|Setup|Mesh|Host|Scale|M1/M2|Q1|R2|R1|R3)\b')


def _completed_list_tag(item_id):
    """Classify a completed row as a closed review or a closed punch row."""
    return 'review' if REVIEW_ID_RE.match(item_id.strip()) else 'punch'


def parse_completed(path):
    """Closed punch and review rows with evidence. Two tables plus one prose block."""
    text = open(path, encoding='utf-8').read()
    rows = []
    for blk in parse_tables(text):
        h = blk['header']
        if h and h[0] == 'Former item':
            for r in blk['rows']:
                if len(r) >= 3 and r[0]:
                    rows.append({'id': r[0], 'detail': _plain(r[1]),
                                 'evidence': _links(r[2]), 'ev_text': _plain(r[2])})
        elif h and h[0] == 'Scope':
            for r in blk['rows']:
                if len(r) >= 2 and r[0]:
                    rows.append({'id': r[0], 'detail': _plain(r[1]),
                                 'evidence': _links(r[1]), 'ev_text': ''})
    return rows


# ---------------------------------------------------------------------------
# raw-row assembly: one normalized record per source row
# ---------------------------------------------------------------------------
def _hist(asof, note):
    return {'date': asof, 'note': note}


def _clip(s, n=600):
    s = (s or '').strip()
    return s if len(s) <= n else s[:n - 1] + '…'


def gather_raw(screens, caps, debt, fleet, punch_open, review_open, completed, asof):
    """Return a list of raw rows in fixed emission order.

    Each raw row: {src, i, ref, title, wave, closed, kind, status_src,
                   detail, done_when, sources_extra, evidence, history}.
    'closed' rows are archived done items. Open rows carry status_src for the
    stub and are subject to the dispositions.
    """
    raw = []

    # screens ----------------------------------------------------------------
    for idx, r in enumerate(screens):
        closed = r.get('bucket') == 'built'
        miss = []
        if r.get('propsMissing'):
            miss.append('props: ' + '; '.join(r['propsMissing']))
        if r.get('backendMissing'):
            miss.append('backend: ' + '; '.join(r['backendMissing']))
        if r.get('specHas'):
            miss.append('spec has, app lacks: ' + '; '.join(r['specHas']))
        done_when = ('Screen conforms. ' + ' '.join(miss)) if miss else 'Page exists and matches the spec.'
        hist = [_hist(asof, 'Migrated from screens row %s (%s).' % (idx, r.get('file', '')))]
        if r.get('notes'):
            hist.append(_hist(asof, 'Note: ' + r['notes']))
        if r.get('appAhead'):
            hist.append(_hist(asof, 'App ahead of spec (reconcile, do not strip): ' + '; '.join(r['appAhead'])))
        raw.append({'src': 'screens', 'i': str(idx), 'ref': r.get('file', ''),
                    'title': r.get('title', '') or r.get('file', ''), 'wave': r.get('wave', ''),
                    'closed': closed, 'kind': 'build', 'status_src': r.get('bucket'),
                    'detail': _clip(('%s. %s' % (r.get('page', ''), r.get('notes', ''))).strip('. ')),
                    'done_when': _clip(done_when), 'evidence': [], 'history': hist})

    # caps -------------------------------------------------------------------
    for idx, r in enumerate(caps):
        closed = r.get('maturity') == 'working'
        kind = 'ops' if r.get('ops') else 'build'
        parts = []
        if r.get('blocker'):
            parts.append('blocker: ' + r['blocker'])
        if r.get('scaleNote'):
            parts.append(r['scaleNote'])
        done_when = ('Capability reaches working. ' + ' '.join(parts)) if parts else 'Capability works end to end.'
        hist = [_hist(asof, 'Migrated from caps row %s (%s).' % (idx, r.get('area', '')))]
        if r.get('scaleNote'):
            hist.append(_hist(asof, 'At scale: ' + r['scaleNote']))
        if r.get('blocker'):
            hist.append(_hist(asof, 'Blocker: ' + r['blocker']))
        raw.append({'src': 'caps', 'i': str(idx), 'ref': _clip(r.get('capability', ''), 90),
                    'title': _clip(r.get('capability', ''), 140), 'wave': r.get('wave', ''),
                    'closed': closed, 'kind': kind, 'status_src': r.get('maturity'),
                    'detail': _clip(' '.join(parts) or r.get('capability', '')),
                    'done_when': _clip(done_when), 'evidence': [], 'history': hist})

    # debt -------------------------------------------------------------------
    for idx, r in enumerate(debt):
        state = r.get('state', 'open')
        closed = state == 'resolved'
        done_when = _clip(r.get('status') or ('Debt resolved: ' + r.get('title', '')))
        hist = [_hist(asof, 'Migrated from debt row %s.' % idx)]
        if r.get('status'):
            hist.append(_hist(asof, 'Status: ' + r['status']))
        if r.get('note'):
            hist.append(_hist(asof, 'Note: ' + r['note']))
        if r.get('owner'):
            hist.append(_hist(asof, 'Owner: ' + r['owner']))
        raw.append({'src': 'debt', 'i': str(idx), 'ref': _clip(r.get('title', ''), 90),
                    'title': _clip(r.get('title', ''), 160), 'wave': r.get('wave', ''),
                    'closed': closed, 'kind': 'build', 'status_src': state,
                    'detail': _clip('%s (%s). %s' % (r.get('location', ''), r.get('severity', ''), r.get('note', ''))),
                    'done_when': done_when, 'evidence': [], 'history': hist})

    # fleet ------------------------------------------------------------------
    for lane in fleet['lanes']:
        for idx, it in enumerate(lane['items']):
            st = it.get('status')
            closed = st == 'done'
            label = it.get('label', '')
            kind = 'build'
            low = (label + ' ' + (it.get('note') or '')).lower()
            if any(w in low for w in ('deploy', 'cloud build', 'dns', 'tls', 'mirror', 'host ')):
                kind = 'ops'
            done_when = _clip(it.get('note') or ('Item complete: ' + label))
            hist = [_hist(asof, 'Migrated from fleet lane %s item %s.' % (lane['id'], idx))]
            if it.get('note'):
                hist.append(_hist(asof, 'Note: ' + it['note']))
            raw.append({'src': 'fleet', 'i': '%s#%s' % (lane['id'], idx), 'ref': _clip(label, 90),
                        'title': _clip(label, 160), 'wave': it.get('wave', lane['id']),
                        'closed': closed, 'kind': kind, 'status_src': st,
                        'detail': _clip(it.get('note') or label),
                        'done_when': done_when, 'evidence': [], 'history': hist})

    # punch (open) -----------------------------------------------------------
    for r in punch_open:
        raw.append({'src': 'punch', 'i': r['id'], 'ref': r['id'], 'title': r['id'] + ' punch item',
                    'wave': '', 'closed': False, 'kind': ('content' if r['id'].startswith('LG-1') or r['id'].startswith('LG-2') else 'build'),
                    'status_src': 'open',
                    'detail': _clip(r['detail']), 'done_when': _clip(r['done_when']),
                    'evidence': [], 'history': [_hist(asof, 'Open punch row %s: %s' % (r['id'], r['detail']))]})

    # review (open) ----------------------------------------------------------
    for r in review_open:
        raw.append({'src': 'review', 'i': r['id'], 'ref': r['id'], 'title': r['id'],
                    'wave': '', 'closed': False, 'kind': 'verify', 'status_src': 'open',
                    'detail': _clip('%s Dependency: %s' % (r['workflow'], r['dependency'])),
                    'done_when': _clip(r['criterion']),
                    'evidence': [], 'history': [_hist(asof, 'Open review %s. Workflow: %s' % (r['id'], r['workflow'])),
                                                _hist(asof, 'Dependency: ' + r['dependency'])]})

    # completed punch and review (closed) ------------------------------------
    for cidx, r in enumerate(completed):
        tag = _completed_list_tag(r['id'])
        hist = [_hist(asof, 'Closed %s row: %s' % (tag, r['id']))]
        if r.get('ev_text'):
            hist.append(_hist(asof, 'Evidence: ' + r['ev_text']))
        raw.append({'src': tag, 'i': 'done#%s' % cidx, 'ref': _clip(r['id'], 90),
                    'title': _clip(r['id'], 160), 'wave': '', 'closed': True,
                    'kind': ('verify' if tag == 'review' else 'build'), 'status_src': 'done',
                    'detail': _clip(r['detail']), 'done_when': '',
                    'evidence': r.get('evidence', []), 'history': hist})

    return raw


# ---------------------------------------------------------------------------
# dispositions
# ---------------------------------------------------------------------------
def make_stub(open_rows):
    """Every open row = work, phase P5 Backlog, order by list then index.

    The source row's own status is carried through so the consolidated list
    badges its real state. A 'held' row waits on the operator, so it lands
    awaiting_go under the operator_go blocker. A 'deferred' row is chosen for
    later, so it lands deferred with no blocker. A 'blocked' row lands blocked
    under the other blocker, with the reason kept in the detail and history.
    Every other open row (partial, absent, open, next) is plain open work. No
    operations order is invented here. That is the desk's job in
    dispositions.json.
    """
    ss_map = {
        'deferred': {'status': 'deferred', 'blocker': 'none'},
        'held': {'status': 'awaiting_go', 'blocker': 'operator_go'},
        'blocked': {'status': 'blocked', 'blocker': 'other'},
    }
    items = []
    for r in open_rows:
        it = {'src': r['src'], 'i': r['i'], 'final': 'work', 'blocker': 'none'}
        it.update(ss_map.get(r.get('status_src'), {}))
        items.append(it)
    seq_items = []
    o = 1
    for r in open_rows:
        seq_items.append({'key': '%s:%s' % (r['src'], r['i']), 'order': o, 'phase': 'P5',
                          'rationale': 'stub: list then index'})
        o += 1
    return {'head': HEAD_DEFAULT,
            'items': items,
            'sequence': {'phases': [STUB_PHASE], 'items': seq_items}}


def load_dispositions(path, open_rows):
    if path and os.path.exists(path):
        return json.load(open(path, encoding='utf-8')), False
    return make_stub(open_rows), True


# ---------------------------------------------------------------------------
# build work.json
# ---------------------------------------------------------------------------
def build_work(raw, dispositions, asof=ASOF_DEFAULT):
    head = dispositions.get('head', HEAD_DEFAULT)
    disp_by_key = {}
    for d in dispositions.get('items', []):
        disp_by_key['%s:%s' % (d['src'], d['i'])] = d
    seq = dispositions.get('sequence', {}) or {}
    seq_by_key = {}
    for s in seq.get('items', []):
        seq_by_key[s['key']] = s
    phases = list(seq.get('phases', [STUB_PHASE]))
    if not any(p['id'] == DONE_PHASE['id'] for p in phases):
        phases = phases + [DONE_PHASE]
    phase_ids = {p['id'] for p in phases}

    # deterministic W-NNN id per source key, in emission order (duplicates skip)
    def key_of(r):
        return '%s:%s' % (r['src'], r['i'])

    id_by_key = {}
    n = 0
    for r in raw:
        k = key_of(r)
        disp = disp_by_key.get(k)
        if disp and disp.get('final') == 'duplicate':
            continue  # merged into carrier, no id of its own
        n += 1
        id_by_key[k] = 'W-%04d' % n

    items = []
    item_by_key = {}

    def src_entry(r):
        return {'list': r['src'], 'ref': r['ref'], 'title': r['title'], 'wave': r.get('wave', '')}

    for r in raw:
        k = key_of(r)
        if r['closed']:
            status, blocker, phase = 'done', 'none', DONE_PHASE['id']
            reason = None
        else:
            disp = disp_by_key.get(k, {'final': 'work', 'blocker': 'none'})
            final = disp.get('final', 'work')
            if final == 'duplicate':
                continue  # handled in the merge pass
            blocker = disp.get('blocker', 'none') or 'none'
            reason = disp.get('reason')
            if final == 'moot':
                status, phase = 'moot', DONE_PHASE['id']
            elif final == 'done':
                status, phase = 'done', DONE_PHASE['id']
            else:  # work
                explicit = disp.get('status')
                if explicit in ACTIONABLE:
                    status = explicit  # source state carried through, e.g. deferred
                else:
                    status = BLOCKER_STATUS.get(blocker, 'blocked')
                s = seq_by_key.get(k)
                phase = (s or {}).get('phase') or 'P5'
        it = {
            'id': id_by_key[k],
            'title': ((disp_by_key.get(k, {}).get('work_title') if not r['closed'] else None) or r['title']),
            'phase': phase if phase in phase_ids else 'P5',
            'order': None,
            'kind': (disp_by_key.get(k, {}).get('kind') if not r['closed'] else None) or r['kind'],
            'status': status,
            'blocker': blocker,
            'detail': r['detail'],
            'done_when': (disp_by_key.get(k, {}).get('done_when') if not r['closed'] else '') or r['done_when'],
            'depends_on': [],
            'sources': [src_entry(r)],
            'evidence': list(r['evidence']),
            'history': list(r['history']),
        }
        if not r['closed'] and disp_by_key.get(k, {}).get('evidence'):
            it['evidence'].append({'label': 'disposition', 'href': disp_by_key[k]['evidence']})
        if reason:
            it['history'].append(_hist(asof, 'Disposition %s: %s' % (status, reason)))
        items.append(it)
        item_by_key[k] = it

    # merge duplicates into their carrier
    for r in raw:
        if r['closed']:
            continue
        k = key_of(r)
        disp = disp_by_key.get(k)
        if not disp or disp.get('final') != 'duplicate':
            continue
        carrier_key = disp.get('duplicate_of')
        carrier = item_by_key.get(carrier_key)
        if carrier is None:
            # carrier not found: keep the row as its own work item (fail-soft)
            carrier = {
                'id': 'W-DUP-%s' % re.sub(r'[^A-Za-z0-9]', '', k),
                'title': r['title'], 'phase': 'P5', 'order': None, 'kind': r['kind'],
                'status': 'open', 'blocker': 'none', 'detail': r['detail'],
                'done_when': r['done_when'], 'depends_on': [], 'sources': [src_entry(r)],
                'evidence': list(r['evidence']),
                'history': list(r['history']) + [_hist(asof, 'Duplicate carrier %s not found; kept standalone.' % carrier_key)],
            }
            items.append(carrier)
            item_by_key[k] = carrier
            continue
        carrier['sources'].append(src_entry(r))
        carrier['history'].append(_hist(asof, 'Merged duplicate %s (%s).' % (k, r['ref'])))
        carrier['history'].extend(r['history'])

    # resolve depends_on (src:i -> W-id)
    for r in raw:
        k = key_of(r)
        it = item_by_key.get(k)
        if it is None:
            continue
        disp = disp_by_key.get(k)
        if disp and disp.get('depends_on'):
            deps = []
            for dep_key in disp['depends_on']:
                dep_id = id_by_key.get(dep_key)
                if dep_id:
                    deps.append(dep_id)
                else:
                    it['history'].append(_hist(asof, 'depends_on %s unresolved; dropped.' % dep_key))
            it['depends_on'] = deps

    # assign order to actionable items
    actionable = [it for it in items if it['status'] in ACTIONABLE]
    have_seq = bool(seq_by_key)
    if have_seq:
        # order from the sequence; items without a sequence entry go to the tail
        def seq_order(it):
            # find the source key that produced this item
            for r in raw:
                if item_by_key.get('%s:%s' % (r['src'], r['i'])) is it:
                    s = seq_by_key.get('%s:%s' % (r['src'], r['i']))
                    if s:
                        return (0, s['order'])
            return (1, 0)
        ordered = sorted(actionable, key=lambda it: (seq_order(it), it['id']))
    else:
        ordered = actionable
    for n2, it in enumerate(ordered, start=1):
        it['order'] = n2

    return {'asOf': asof, 'head': head, 'phases': phases, 'items': items}


# ---------------------------------------------------------------------------
# driver
# ---------------------------------------------------------------------------
def load_inputs(tools_dir, audit_dir, fleet):
    screens, caps, debt = load_badged(os.path.join(tools_dir, 'badged.json'))
    punch = parse_action_plan(os.path.join(audit_dir, 'DEMO_ACTION_PLAN.md'))
    review = parse_review_register(os.path.join(audit_dir, 'DEMO_REVIEW_REGISTER.md'))
    completed = parse_completed(os.path.join(audit_dir, 'DEMO_COMPLETED_WORK.md'))
    return screens, caps, debt, fleet, punch, review, completed


def run(tools_dir, audit_dir, fleet, out_path, disp_path, asof=ASOF_DEFAULT):
    screens, caps, debt, fleet, punch, review, completed = load_inputs(tools_dir, audit_dir, fleet)
    raw = gather_raw(screens, caps, debt, fleet, punch, review, completed, asof)
    open_rows = [r for r in raw if not r['closed']]
    disp, used_stub = load_dispositions(disp_path, open_rows)
    work = build_work(raw, disp, asof=asof)
    with io.open(out_path, 'w', encoding='utf-8') as f:
        json.dump(work, f, ensure_ascii=False, indent=2, sort_keys=False)
        f.write('\n')
    return work, used_stub


def _counts(work):
    from collections import Counter
    return dict(Counter(it['status'] for it in work['items']))


def main():
    tools_dir = _HERE
    audit_dir = os.path.join(_HERE, '..', '..', '..', 'audits', '2026-09-12')
    audit_dir = os.path.normpath(audit_dir)
    sys.path.insert(0, tools_dir)
    from wave4_data import FLEET
    out_path = os.path.join(tools_dir, 'work.json')
    disp_path = os.path.join(tools_dir, 'dispositions.json')
    work, used_stub = run(tools_dir, audit_dir, FLEET, out_path, disp_path)
    c = _counts(work)
    print('wrote', out_path)
    print('items', len(work['items']), '| by status', c)
    print('open/actionable', sum(c.get(s, 0) for s in ACTIONABLE),
          '| done', c.get('done', 0), '| moot', c.get('moot', 0))
    print('phases', [p['id'] for p in work['phases']])
    if used_stub:
        print('STUB DISPOSITIONS USED: dispositions.json absent. Every open row is a work item in phase P5 Backlog, ordered by list then index.')
    else:
        print('dispositions.json applied from', disp_path)


# ---------------------------------------------------------------------------
# self-test
# ---------------------------------------------------------------------------
def selftest():
    d = tempfile.mkdtemp(prefix='worktest_')
    try:
        tools = os.path.join(d, 'tools'); audit = os.path.join(d, 'audit')
        os.makedirs(tools); os.makedirs(audit)
        badged = {
            'screens': [
                {'file': 'a.html', 'title': 'A built', 'bucket': 'built', 'wave': 'W2',
                 'page': 'A.vue', 'notes': '', 'propsMissing': [], 'backendMissing': [],
                 'specHas': [], 'appAhead': []},
                {'file': 'b.html', 'title': 'B partial', 'bucket': 'partial', 'wave': 'W6',
                 'page': 'B.vue', 'notes': 'needs work', 'propsMissing': ['x'],
                 'backendMissing': [], 'specHas': [], 'appAhead': []},
            ],
            'caps': [
                {'area': 'Id', 'capability': 'Auth', 'maturity': 'working', 'wave': 'P',
                 'scaleNote': 'ok', 'blocker': ''},
                {'area': 'Sim', 'capability': 'Run sim', 'maturity': 'partial', 'wave': 'W7',
                 'scaleNote': 'slow', 'blocker': 'needs resume'},
                {'area': 'Exec', 'capability': 'Blocked cap', 'maturity': 'blocked', 'wave': 'W8',
                 'scaleNote': 'gap', 'blocker': 'setup lock'},
            ],
            'debt': [
                {'title': 'Old debt', 'state': 'resolved', 'wave': 'W4', 'severity': 'low',
                 'status': 'fixed', 'note': '', 'owner': 'x', 'location': 'f.php'},
                {'title': 'Live debt', 'state': 'open', 'wave': 'W6', 'severity': 'high',
                 'status': 'do the thing', 'note': 'n', 'owner': 'y', 'location': 'g.php'},
            ],
        }
        json.dump(badged, open(os.path.join(tools, 'badged.json'), 'w', encoding='utf-8'))
        fleet = {'waves': [{'id': 'W6', 'name': 'x', 'status': 'next'}],
                 'lanes': [{'id': 'W6', 'name': 'L', 'status': 'next', 'items': [
                     {'wave': 'W6', 'label': 'done item', 'status': 'done', 'note': 'd'},
                     {'wave': 'W6', 'label': 'next item', 'status': 'next', 'note': 'n'},
                     {'wave': 'W6', 'label': 'held item', 'status': 'held', 'note': 'held for operator'},
                     {'wave': 'W6', 'label': 'deferred item', 'status': 'deferred', 'note': 'later'},
                 ]}]}
        open(os.path.join(audit, 'DEMO_ACTION_PLAN.md'), 'w', encoding='utf-8').write(
            '# x\n\n| ID | Confirmed build / repair | Done when |\n|---|---|---|\n'
            '| LG-1 | build a thing | thing exists |\n')
        open(os.path.join(audit, 'DEMO_REVIEW_REGISTER.md'), 'w', encoding='utf-8').write(
            '# x\n\n| Review | Workflow | Dependency | Pass criterion |\n|---|---|---|---|\n'
            '| R2 | rooms | a host | media both ways |\n')
        open(os.path.join(audit, 'DEMO_COMPLETED_WORK.md'), 'w', encoding='utf-8').write(
            '# x\n\n| Former item | Finished | Evidence |\n|---|---|---|\n'
            '| B6 | did it | [proof](x.md) |\n'
            '| S1 | reviewed it | [rev](y.md) |\n')

        out = os.path.join(d, 'work.json')
        # stub path
        work, used_stub = run(tools, audit, fleet, out, os.path.join(d, 'nope.json'))
        assert used_stub, 'stub should be used when dispositions absent'
        st = _counts(work)
        # open rows: screens.b, caps.partial, debt.open, fleet.next, punch LG-1, review R2 = 6
        # carried-through fleet state: held -> awaiting_go = 1, deferred -> deferred = 1
        # done rows: screens.a, caps.working, debt.resolved, fleet.done, completed B6, completed S1 = 6
        assert st.get('open', 0) == 6, ('open should be 6', st)
        assert st.get('awaiting_go', 0) == 1, ('fleet held -> awaiting_go', st)
        assert st.get('deferred', 0) == 1, ('fleet deferred -> deferred', st)
        assert st.get('blocked', 0) == 1, ('caps blocked -> blocked', st)
        assert st.get('done', 0) == 6, ('done should be 6 incl completed', st)
        by_title_stub = {it['title']: it for it in work['items']}
        held_it = by_title_stub['held item']
        assert held_it['status'] == 'awaiting_go' and held_it['blocker'] == 'operator_go', \
            ('held carried', held_it['status'], held_it['blocker'])
        def_it = by_title_stub['deferred item']
        assert def_it['status'] == 'deferred' and def_it['blocker'] == 'none', \
            ('deferred carried', def_it['status'], def_it['blocker'])
        blk_it = by_title_stub['Blocked cap']
        assert blk_it['status'] == 'blocked' and blk_it['blocker'] == 'other', \
            ('blocked carried', blk_it['status'], blk_it['blocker'])
        for it in work['items']:
            if it['status'] in ACTIONABLE:
                assert it['done_when'], ('open item missing done_when', it['id'])
        orders = [it['order'] for it in work['items'] if it['status'] in ACTIONABLE]
        assert len(orders) == len(set(orders)) == 9, ('unique orders', orders)

        # idempotent second run
        b1 = open(out, encoding='utf-8').read()
        run(tools, audit, fleet, out, os.path.join(d, 'nope.json'))
        b2 = open(out, encoding='utf-8').read()
        assert b1 == b2, 'second stub run not byte-identical'

        # real dispositions: mark the debt-open row moot, punch LG-1 depends on review R2,
        # give the fleet-next row a real phase and blocker
        disp = {
            'head': 'testhead',
            'items': [
                {'src': 'screens', 'i': '1', 'final': 'work', 'blocker': 'none'},
                {'src': 'caps', 'i': '1', 'final': 'work', 'blocker': 'operator_go'},
                {'src': 'debt', 'i': '1', 'final': 'moot', 'reason': 'obsolete'},
                {'src': 'fleet', 'i': 'W6#1', 'final': 'work', 'blocker': 'linux_host'},
                {'src': 'punch', 'i': 'LG-1', 'final': 'work', 'blocker': 'none',
                 'depends_on': ['review:R2']},
                {'src': 'review', 'i': 'R2', 'final': 'work', 'blocker': 'none'},
            ],
            'sequence': {
                'phases': [{'id': 'P1', 'name': 'First', 'goal': 'g'},
                           {'id': 'P2', 'name': 'Second', 'goal': 'g'}],
                'items': [
                    {'key': 'review:R2', 'order': 1, 'phase': 'P1'},
                    {'key': 'punch:LG-1', 'order': 2, 'phase': 'P1'},
                    {'key': 'screens:1', 'order': 3, 'phase': 'P2'},
                    {'key': 'caps:1', 'order': 4, 'phase': 'P2'},
                    {'key': 'fleet:W6#1', 'order': 5, 'phase': 'P2'},
                ],
            },
        }
        dp = os.path.join(d, 'dispositions.json')
        json.dump(disp, open(dp, 'w', encoding='utf-8'))
        work2, used_stub2 = run(tools, audit, fleet, out, dp)
        assert not used_stub2, 'real dispositions should not use stub'
        assert work2['head'] == 'testhead'
        by_title = {it['title']: it for it in work2['items']}
        assert by_title['Live debt']['status'] == 'moot', 'debt should be moot'
        assert any('obsolete' in h['note'] for h in by_title['Live debt']['history']), 'moot reason in history'
        cap_open = by_title['Run sim']
        assert cap_open['status'] == 'awaiting_go', ('operator_go -> awaiting_go', cap_open['status'])
        lg = by_title['LG-1 punch item']
        assert lg['depends_on'] == [by_title['R2']['id']], ('depends_on resolved', lg['depends_on'])
        # phases from the sequence plus the done phase
        pids = [p['id'] for p in work2['phases']]
        assert pids == ['P1', 'P2', 'done'], ('phases', pids)

        # idempotent second run with real dispositions
        r1 = open(out, encoding='utf-8').read()
        run(tools, audit, fleet, out, dp)
        r2 = open(out, encoding='utf-8').read()
        assert r1 == r2, 'second real-dispositions run not byte-identical'
        print('migrate_to_work selftest OK')
    finally:
        shutil.rmtree(d, ignore_errors=True)


if __name__ == '__main__':
    if '--selftest' in sys.argv:
        selftest()
    else:
        main()

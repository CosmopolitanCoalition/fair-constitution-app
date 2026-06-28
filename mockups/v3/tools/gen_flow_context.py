#!/usr/bin/env python3
"""Invert the 80 flow walkthroughs into per-screen workflow context.

  python mockups/v3/tools/gen_flow_context.py

Reads the canonical flow data straight out of the generated flow pages
(mockups/v3/flows/WF-*.html embed `var flowData = {...}`) plus the three
hand-authored flows from fixtures.flowSamples, and inverts the step→screen map.

Writes mockups/v3/assets/js/fixtures-flows.js exposing

  CGA.fixtures.flows = {
    byScreen:   { '<page id>': [ {wf, wfName, family, stepN, total, action, form, prev, next, branches[]}, ... ] },
    byWorkflow: { 'WF-XXX': {name, family, trigger, terminal, basis, entity, steps:[{n, action, actor, form, screen, branches}]} }
  }

so each screen's Learn drawer can show "you are here in this process" — which
workflow(s) the screen takes part in, the player's step, what came before and
after, and the full process on demand. After this lands, the 80 flow pages are
removed; this data is where their content lives.
"""
import json, os, re, io, sys

HERE = os.path.dirname(os.path.abspath(__file__))
V3 = os.path.dirname(HERE)
FLOWS = os.path.join(V3, 'flows')
OUT = os.path.join(V3, 'assets', 'js', 'fixtures-flows.js')

FAMILY_LABEL = {
    'CIV': 'Civic life', 'ELE': 'Elections', 'LEG': 'The legislature',
    'EXE': 'The executive', 'JUD': 'The judiciary', 'ORG': 'Organizations',
    'JUR': 'Jurisdictions & federation', 'SYS': 'System',
}

EMBED = re.compile(r'var flowData = (\{.*?\n\});', re.S)


def load_flow_samples():
    """Pull the three hand-authored flows out of fixtures.js flowSamples.
    The object is plain-enough JS; coax the three entries into JSON."""
    src = io.open(os.path.join(V3, 'assets', 'js', 'fixtures.js'), encoding='utf-8').read()
    i = src.find('var flowSamples = {')
    j = src.find('window.CGA.fixtures =', i)
    blob = src[i:j]
    out = {}
    for wf in ('WF-CIV-02', 'WF-ELE-03', 'WF-JUD-05'):
        k = blob.find("'" + wf + "': {")
        if k < 0:
            continue
        # balance braces from the first '{' after the key
        b = blob.find('{', k)
        depth, p = 0, b
        while p < len(blob):
            ch = blob[p]
            if ch == '{':
                depth += 1
            elif ch == '}':
                depth -= 1
                if depth == 0:
                    break
            p += 1
        obj = blob[b:p + 1]
        # JS -> JSON: quote keys, single->double quotes, drop trailing commas
        out[wf] = _js_obj_to_json(obj)
    return out


def _js_obj_to_json(js):
    s = js
    # single-quoted strings -> double-quoted (no escaped single quotes appear here)
    s = re.sub(r"'((?:[^'\\]|\\.)*)'", lambda m: json.dumps(m.group(1)), s)
    # bare identifier keys -> quoted
    s = re.sub(r'([{,]\s*)([A-Za-z_]\w*)\s*:', lambda m: m.group(1) + '"' + m.group(2) + '":', s)
    # trailing commas
    s = re.sub(r',\s*([}\]])', r'\1', s)
    return json.loads(s)


def all_flows():
    flows = {}
    for fn in sorted(os.listdir(FLOWS)):
        if not fn.endswith('.html'):
            continue
        wf = fn[:-5]
        html = io.open(os.path.join(FLOWS, fn), encoding='utf-8').read()
        m = EMBED.search(html)
        if m:
            try:
                flows[wf] = json.loads(m.group(1))
            except Exception as e:
                print('  parse fail', wf, e)
    for wf, data in load_flow_samples().items():
        flows[wf] = data
    return flows


def screen_id(href):
    return re.sub(r'\.html$', '', href or '')


def main():
    flows = all_flows()
    by_screen, by_workflow = {}, {}

    # screen -> wf -> grouped entry (a screen can render several steps of one wf)
    screen_groups = {}
    for wf in sorted(flows):
        f = flows[wf]
        fam = wf.split('-')[1]
        steps = f.get('steps', [])
        total = len(steps)
        wsteps = []
        for idx, s in enumerate(steps):
            scr = (s.get('screen') or {}).get('href')
            sid = screen_id(scr)
            prev_action = steps[idx - 1]['action'] if idx > 0 else None
            next_action = steps[idx + 1]['action'] if idx + 1 < total else None
            branches = [b.get('label') for b in s.get('branches', []) if b.get('label')]
            wsteps.append({'n': s.get('n', idx + 1), 'action': s.get('action', ''),
                           'actor': s.get('actor', ''), 'form': s.get('form'),
                           'screen': sid, 'branches': branches})
            if sid:
                g = screen_groups.setdefault(sid, {}).setdefault(wf, {
                    'wf': wf, 'wfName': f.get('name', wf), 'family': fam,
                    'familyLabel': FAMILY_LABEL.get(fam, fam),
                    'total': total, 'trigger': f.get('trigger', ''),
                    'terminal': f.get('terminal', ''), 'steps': []})
                g['steps'].append({'n': s.get('n', idx + 1), 'action': s.get('action', ''),
                                   'prev': prev_action, 'next': next_action, 'branches': branches})
        by_workflow[wf] = {'name': f.get('name', wf), 'family': fam,
                           'familyLabel': FAMILY_LABEL.get(fam, fam),
                           'trigger': f.get('trigger', ''), 'terminal': f.get('terminal', ''),
                           'basis': f.get('basis', ''), 'entity': f.get('entity'),
                           'steps': wsteps}

    # one row per (screen, workflow); a screen that anchors a workflow's FIRST
    # step is "primary" for it (sorts first — that's where the process begins).
    for sid, groups in screen_groups.items():
        rows = list(groups.values())
        for r in rows:
            r['minStep'] = min(st['n'] for st in r['steps'])
        rows.sort(key=lambda r: (r['minStep'], r['wf']))
        by_screen[sid] = rows

    payload = {'byScreen': by_screen, 'byWorkflow': by_workflow}
    js = ('/* fixtures-flows.js — GENERATED by tools/gen_flow_context.py. Do not edit by hand.\n'
          '   The 80 workflow walkthroughs, inverted into per-screen context: each screen\n'
          '   knows which process(es) it takes part in and where the player sits in them.\n'
          '   This is where the (now-removed) flows/WF-*.html content lives. */\n'
          '(function () {\n'
          "  'use strict';\n"
          '  window.CGA = window.CGA || {};\n'
          '  CGA.fixtures = CGA.fixtures || {};\n'
          '  CGA.fixtures.flows = ' + json.dumps(payload, ensure_ascii=False, separators=(',', ':')) + ';\n'
          '}());\n')
    io.open(OUT, 'w', encoding='utf-8', newline='\n').write(js)
    print('wrote', os.path.relpath(OUT, V3))
    print('  workflows:', len(by_workflow), '| screens with context:', len(by_screen))
    biggest = sorted(by_screen.items(), key=lambda kv: -len(kv[1]))[:6]
    for sid, lst in biggest:
        print('   ', sid, '->', len(lst), 'participations')


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""Generate the 80 flow walkthrough pages (mockups/flows/WF-*.html).

  python mockups/tools/gen_flows.py

Each page renders a flowData object transcribed from CGA_Workflows_Catalog.xlsx
Sheet 2 through CGA.shell.renderFlowStepper (the frozen Stage-0 contract):
header card, interactive stepper, BRANCH buttons (clickable alternatives incl.
failure/terminal paths), sub-workflow handoffs, entity state strip, and
"Open in app" deep links resolved from manifest.json (first screen that renders
each step's form).

ID normalization: the catalog's drifted form IDs are mapped to the canonical
roles-chart IDs (build instructions §2 + verified finds) — see ALIAS maps.
Three workflows (WF-CIV-02, WF-ELE-03, WF-JUD-05) use the hand-authored
fixtures.flowSamples data instead of generated steps.

Also writes mockups/tools/flow_records.json (manifest records for the merge).
"""
import json, os, re, sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from gen_fixtures import dump_xlsx, CATALOG_XLSX, REPO, g, stage_of  # noqa: E402

MOCKUPS = os.path.join(REPO, 'mockups')
FLOWS = os.path.join(MOCKUPS, 'flows')

# ---- canonical <- catalog drift (global swaps; §2 items 2-5 + finds) --------
GLOBAL_ALIAS = {
    'F-COM-001': 'F-CHR-001', 'F-COM-002': 'F-CHR-002', 'F-COM-003': 'F-CHR-003', 'F-COM-004': 'F-CHR-004',
    'F-GOV-001': 'F-BOG-001', 'F-GOV-002': 'F-BOG-002',
    # identity/ping swap (canonical: 004 identity, 005 ping; catalog reversed)
    'F-IND-004': 'F-IND-005', 'F-IND-005': 'F-IND-004',
    # late-F-LEG renumbering (catalog id -> canonical id, matched by name)
    'F-LEG-022': 'F-LEG-023',  # referendum delegation
    'F-LEG-023': 'F-LEG-024',  # emergency declaration
    'F-LEG-024': 'F-LEG-025',  # emergency renewal
    'F-LEG-030': 'F-LEG-036',  # vacancy declaration
    'F-LEG-034': 'F-LEG-022',  # removal/impeachment vote
}
# context-sensitive: catalog cites F-IND-013 for challenge filing in WF-JUD-05/09
WF_ALIAS = {
    'WF-JUD-05': {'F-IND-013': 'F-IND-016'},
    'WF-JUD-09': {'F-IND-013': 'F-IND-016'},
}

HAND_AUTHORED = {'WF-CIV-02', 'WF-ELE-03', 'WF-JUD-05'}  # fixtures.flowSamples

ENTITY = {
    'WF-CIV-01': 'Individual', 'WF-CIV-02': 'Residency Claim', 'WF-CIV-03': 'Residency Claim',
    'WF-CIV-04': 'Ballot (Ranked)', 'WF-CIV-05': 'Candidacy', 'WF-CIV-06': 'Petition',
    'WF-CIV-07': 'Individual', 'WF-CIV-08': 'Approval Standing',
    'WF-ELE-01': 'Election', 'WF-ELE-02': 'Election', 'WF-ELE-03': 'Vacancy', 'WF-ELE-04': 'Election',
    'WF-ELE-05': 'Election', 'WF-ELE-06': 'Jurisdiction', 'WF-ELE-07': 'Referendum Question',
    'WF-ELE-08': 'Election', 'WF-ELE-09': 'Election', 'WF-ELE-10': 'Election',
    'WF-LEG-01': None, 'WF-LEG-02': None, 'WF-LEG-03': 'Committee Seat', 'WF-LEG-04': 'Committee Seat',
    'WF-LEG-05': 'Motion', 'WF-LEG-06': 'Bill', 'WF-LEG-07': 'Bill', 'WF-LEG-08': 'Bill',
    'WF-LEG-09': 'Motion', 'WF-LEG-10': 'Referendum Question', 'WF-LEG-11': 'Emergency Powers',
    'WF-LEG-12': 'Vacancy', 'WF-LEG-13': 'Committee Seat', 'WF-LEG-14': None, 'WF-LEG-15': None,
    'WF-LEG-16': None, 'WF-LEG-17': None, 'WF-LEG-18': 'Election', 'WF-LEG-19': 'Referendum Question',
    'WF-LEG-20': 'Motion',
    'WF-EXE-01': 'Executive Office', 'WF-EXE-02': 'Executive Office', 'WF-EXE-03': 'Executive Office',
    'WF-EXE-04': 'Department / Board', 'WF-EXE-05': 'Department / Board', 'WF-EXE-06': 'Department / Board',
    'WF-EXE-07': None, 'WF-EXE-08': None, 'WF-EXE-09': 'Department / Board',
    'WF-JUD-01': None, 'WF-JUD-02': None, 'WF-JUD-03': 'Case', 'WF-JUD-04': 'Case',
    'WF-JUD-05': 'Constitutional Challenge', 'WF-JUD-06': 'Emergency Powers', 'WF-JUD-07': 'Vacancy',
    'WF-JUD-08': 'Case', 'WF-JUD-09': 'Petition',
    'WF-ORG-01': 'Organization', 'WF-ORG-02': 'Organization', 'WF-ORG-03': 'Organization',
    'WF-ORG-04': 'Organization', 'WF-ORG-05': 'Organization', 'WF-ORG-06': 'Organization',
    'WF-ORG-07': 'Organization', 'WF-ORG-08': 'Organization', 'WF-ORG-09': 'Organization',
    'WF-ORG-10': 'Organization',
    'WF-JUR-01': 'Jurisdiction', 'WF-JUR-02': 'Jurisdiction', 'WF-JUR-03': 'Jurisdiction',
    'WF-JUR-04': 'Jurisdiction', 'WF-JUR-05': 'Federation Peer', 'WF-JUR-06': 'Federation Peer',
    'WF-JUR-07': 'Jurisdiction', 'WF-JUR-08': 'Federation Peer', 'WF-JUR-09': 'Jurisdiction',
    'WF-SYS-01': 'Election', 'WF-SYS-02': None, 'WF-SYS-03': None, 'WF-SYS-04': None, 'WF-SYS-05': None,
}

FAMILY_SCREEN = {
    'CIV': 'civic/civic-home.html', 'ELE': 'electoral/election-detail.html',
    'LEG': 'legislature/session-console.html', 'EXE': 'executive/executive-home.html',
    'JUD': 'judiciary/case-docket.html', 'ORG': 'organizations/org-registry.html',
    'JUR': 'jurisdictions/jurisdiction-browser.html', 'SYS': 'system/audit-chain.html',
}
WF_SCENARIO = {
    'WF-JUD-05': {'challenge': True}, 'WF-JUD-06': {'emergency': True},
    'WF-LEG-11': {'emergency': True}, 'WF-LEG-07': {'bicameral': True},
    'WF-ELE-04': {'countbackFailed': True}, 'WF-JUR-07': {'restoration': True},
    'WF-JUR-02': {'unionDrill': True}, 'WF-JUR-03': {'unionDrill': True},
    'WF-LEG-20': {'quorumFails': True},
}

WFID = re.compile(r'WF-[A-Z]{3}-\d{2}')
FID = re.compile(r'F-[A-Z]{3}-\d{3}')
RID = re.compile(r'R-\d{2}')
IID = re.compile(r'I-[A-Z]{3}')
CLKID = re.compile(r'CLK-\d{2}')
STEPREF = re.compile(r'step\s+(\d+)', re.I)


def canon(fid, wf):
    fid = WF_ALIAS.get(wf, {}).get(fid, fid)
    return GLOBAL_ALIAS.get(fid, fid)


def parse_branches(outcome, wf, self_steps):
    """Split an outcome cell into (lead text, branches[])."""
    if 'BRANCH' not in outcome:
        # standalone sub-workflow handoff?
        handoffs = [w for w in WFID.findall(outcome) if w != wf]
        branches = [{'label': 'Continues in ' + w, 'goto': {'wf': w, 'step': 1}} for w in dict.fromkeys(handoffs)]
        return outcome, branches
    head, _, tail = outcome.partition('BRANCH:')
    head = head.strip(' ;·—-')
    alts = re.split(r'\s*\|\s*', tail.strip())
    # some rows separate alternatives with ';' instead of '|'
    if len(alts) == 1 and ';' in tail:
        alts = re.split(r'\s*;\s*', tail.strip())
    branches = []
    for alt in alts:
        alt = alt.strip().rstrip('.')
        if not alt:
            continue
        m = STEPREF.search(alt)
        wfs = [w for w in WFID.findall(alt) if w != wf]
        if m and int(m.group(1)) in self_steps:
            branches.append({'label': alt, 'goto': int(m.group(1))})
        elif wfs:
            branches.append({'label': alt, 'goto': {'wf': wfs[0], 'step': 1}})
        else:
            branches.append({'label': alt, 'goto': 'terminal:' + alt})
    return head, branches


def build_form_screen_map():
    manifest = json.load(open(os.path.join(MOCKUPS, 'manifest.json'), encoding='utf-8'))
    m = {}
    for rec in manifest:
        if rec['file'].startswith('flows/'):
            continue
        for f in rec.get('forms', []):
            m.setdefault(f, rec['file'])
    return m


PAGE = '''<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{title} — CGA mockups</title>
  <link rel="icon" href="../assets/img/social-square-purple.png" />
  <link rel="stylesheet" href="../assets/css/colors_and_type.css" />
  <link rel="stylesheet" href="../assets/css/mockup.css" />
  <script src="../assets/js/demo-state.js"></script>
</head>
<body>
  <main id="main">
    <div class="stack">
      <header>
        <span class="eyebrow">Flow walkthrough · {family}</span>
        <h1>{h1} <span class="citation">{wf}</span></h1>
        <p class="page-intro">Every step, actor, form, and branch below is transcribed from the workflows catalog
          (Sheet 2{alias_note}). Branch buttons take the alternate path — including failure and terminal paths.
          "Open in app" jumps to the screen where a step happens, with the demo state preset.</p>
        <noscript><p>This walkthrough is rendered by JavaScript from its flowData object.</p></noscript>
      </header>
      <div id="flow-root"></div>
      <p><a href="../index.html#wf-h">All 80 workflows
        <svg class="icon icon--sm icon--directional" aria-hidden="true"><use href="#i-arrow-right"></use></svg></a></p>
    </div>
  </main>

  <script>
    window.CGA_PAGE = {page_cfg};
  </script>
  <script src="../assets/js/fixtures.js"></script>
  <script src="../manifest.js"></script>
  <script src="../assets/js/icons.js"></script>
  <script src="../assets/js/i18n.js"></script>
  <script src="../assets/js/shell.js"></script>
  <script>
    (function () {{
      'use strict';
      var flowData = {flow_data};
      window.CGA.shell.renderFlowStepper(flowData, document.getElementById('flow-root'));
      document.addEventListener('cga:statechange', function () {{ window.CGA.shell.refresh(); }});
    }})();
  </script>
</body>
</html>
'''

PAGE_SAMPLE = PAGE.replace('var flowData = {flow_data};',
                           "var flowData = window.CGA.fixtures.flowSamples['{wf}'];")


def main():
    cat = dump_xlsx(CATALOG_XLSX)
    form_screen = build_form_screen_map()

    inv = {}
    for r in cat['1. Workflow Inventory']:
        c = r['cells']
        if re.match(r'^WF-', g(c, 0)):
            inv[g(c, 0)] = {'name': g(c, 1), 'timeScale': g(c, 2), 'trigger': g(c, 3),
                            'actors': g(c, 4), 'institutions': g(c, 5), 'forms': g(c, 6),
                            'terminal': g(c, 7), 'basis': g(c, 8)}

    steps_by_wf = {}
    for r in cat['2. Workflow Steps']:
        c = r['cells']
        if re.match(r'^WF-', g(c, 0)) and re.match(r'^\d+$', g(c, 1)):
            steps_by_wf.setdefault(g(c, 0), []).append(
                {'n': int(g(c, 1)), 'actor': g(c, 2), 'action': g(c, 3), 'form': g(c, 4), 'outcome': g(c, 5)})

    os.makedirs(FLOWS, exist_ok=True)
    records = []
    drift_applied = []

    for wf, head in sorted(inv.items()):
        fam = wf.split('-')[1]
        rows = sorted(steps_by_wf.get(wf, []), key=lambda s: s['n'])
        self_steps = {s['n'] for s in rows}
        actors = list(dict.fromkeys(RID.findall(head['actors'])))
        insts = list(dict.fromkeys(IID.findall(head['institutions'])))
        clocks = sorted(set(CLKID.findall(json.dumps(rows) + head['forms'] + head['trigger'])))
        entity = ENTITY.get(wf)
        scenario = WF_SCENARIO.get(wf)

        steps = []
        for s in rows:
            fids_raw = FID.findall(s['form'])
            fids = [canon(f, wf) for f in fids_raw]
            for raw, c2 in zip(fids_raw, fids):
                if raw != c2:
                    drift_applied.append(f'{wf} step {s["n"]}: {raw} -> {c2}')
            lead, branches = parse_branches(s['outcome'], wf, self_steps)
            step = {'n': s['n'], 'actor': s['actor'] or 'System', 'action': s['action'], 'outcome': lead or s['outcome']}
            if fids:
                step['form'] = fids[0]
                if len(fids) > 1:
                    step['outcome'] += '  [also: ' + ', '.join(fids[1:]) + ']'
            elif s['form']:
                step['engine'] = s['form']
            href = form_screen.get(step.get('form')) or FAMILY_SCREEN[fam]
            params = {}
            arids = RID.findall(s['actor'])
            if arids:
                params['role'] = arids[0]
            if scenario:
                params['sc'] = scenario
            step['screen'] = {'href': href, 'params': params}
            if branches:
                step['branches'] = branches
            steps.append(step)

        flow = {'id': wf, 'name': head['name'], 'timeScale': head['timeScale'], 'trigger': head['trigger'],
                'actors': actors or ['System'], 'institutions': insts, 'terminal': head['terminal'],
                'basis': head['basis'], 'entity': entity, 'steps': steps}

        cfg = {'id': 'flows/' + wf, 'title': head['name'], 'module': 'flows', 'nav': None,
               'roles': actors, 'workflows': [wf], 'forms': [],
               'citation': head['basis'] + ' · ' + wf, 'flow': wf, 'register': 'governance'}

        alias_note = '; §2 ID drift normalized to canonical form IDs' if any(d.startswith(wf) for d in drift_applied) else ''
        tmpl_args = dict(title=head['name'], family=fam, h1=head['name'], wf=wf,
                         alias_note=alias_note, page_cfg=json.dumps(cfg, ensure_ascii=False),
                         flow_data=json.dumps(flow, ensure_ascii=False, indent=1))
        html = (PAGE_SAMPLE.format(**{**tmpl_args, 'flow_data': ''}) if wf in HAND_AUTHORED
                else PAGE.format(**tmpl_args))
        open(os.path.join(FLOWS, wf + '.html'), 'w', encoding='utf-8').write(html)

        records.append({'file': 'flows/' + wf + '.html', 'title': 'Flow — ' + head['name'],
                        'module': 'flows', 'roles': actors, 'workflows': [wf], 'forms': [],
                        'entities': [entity] if entity else [], 'clocks': clocks,
                        'suggestedVuePage': None,
                        'notes': 'Generated from catalog Sheet 2 by tools/gen_flows.py'
                                 + (' (uses hand-authored fixtures.flowSamples data)' if wf in HAND_AUTHORED else ''),
                        'stage': stage_of(wf)})

    json.dump(records, open(os.path.join(HERE, 'flow_records.json'), 'w', encoding='utf-8'),
              ensure_ascii=False, indent=1)
    print(f'wrote {len(records)} flow pages; {len(drift_applied)} drifted form IDs normalized')
    for d in drift_applied:
        print('  drift:', d)
    missing = [wf for wf in inv if wf not in steps_by_wf]
    if missing:
        print('WARNING — workflows with NO step rows in Sheet 2:', ', '.join(missing))


if __name__ == '__main__':
    main()

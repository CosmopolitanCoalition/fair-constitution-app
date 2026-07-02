#!/usr/bin/env python3
"""Regenerate mockups/assets/js/fixtures.js from the source workbooks.

  python mockups/tools/gen_fixtures.py

Reads (stdlib only — no openpyxl needed):
  App Docs/CGA_Constitutional_Roles_Forms_Chart.xlsx   (canonical IDs)
  App Docs/CGA_Workflows_Catalog.xlsx                  (workflows, clocks, entities)
  mockups/tools/world_block.js                         (hand-authored demo world + flow samples)

Applies the build-instructions section-2 ID-drift resolutions (catalog aliases
recorded per canonical form). Edit THIS file or world_block.js, never the
registry block of fixtures.js directly.
"""
import json, os, re, sys, zipfile
import xml.etree.ElementTree as ET

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.normpath(os.path.join(HERE, '..', '..'))
ROLES_XLSX = os.path.join(REPO, 'App Docs', 'CGA_Constitutional_Roles_Forms_Chart.xlsx')
CATALOG_XLSX = os.path.join(REPO, 'App Docs', 'CGA_Workflows_Catalog.xlsx')
OUT_JS = os.path.join(REPO, 'mockups', 'assets', 'js', 'fixtures.js')

NS = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
RID = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id'
M = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'


def dump_xlsx(path):
    z = zipfile.ZipFile(path)
    rels = {rel.get('Id'): rel.get('Target') for rel in ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))}
    wb = ET.fromstring(z.read('xl/workbook.xml'))
    sheets = [(s.get('name'), rels[s.get(RID)]) for s in wb.find('m:sheets', NS)]
    sst = []
    if 'xl/sharedStrings.xml' in z.namelist():
        for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si', NS):
            sst.append(''.join(t.text or '' for t in si.iter(M + 't')))
    out = {}
    for name, target in sheets:
        tgt = target.lstrip('/')
        if not tgt.startswith('xl/'):
            tgt = 'xl/' + tgt
        ws = ET.fromstring(z.read(tgt))
        rows = {}
        for c in ws.iter(M + 'c'):
            v = c.find('m:v', NS)
            isv = c.find('m:is', NS)
            if v is not None:
                val = sst[int(v.text)] if c.get('t') == 's' else v.text
            elif isv is not None:
                val = ''.join(t.text or '' for t in isv.iter(M + 't'))
            else:
                continue
            mm = re.match(r'([A-Z]+)(\d+)', c.get('r'))
            col = 0
            for ch in mm.group(1):
                col = col * 26 + ord(ch) - 64
            rows.setdefault(int(mm.group(2)), {})[col - 1] = val
        out[name] = [{'row': rn, 'cells': rows[rn]} for rn in sorted(rows)]
    return out


def g(cells, i):
    return (cells.get(i) or '').strip()


def rids(s):
    return re.findall(r'R-\d{2}', s)


# ---- role augmentation: shortName, defaultPersona, entryScreen
AUG = {
 'R-01': ('registered individual', 'amara-okafor', 'civic/onboarding.html'),
 'R-02': ('verified resident', 'amara-okafor', 'civic/residency.html'),
 'R-03': ('jurisdictionally associated', 'amara-okafor', 'civic/today.html'),
 'R-04': ('voter', 'amara-okafor', 'civic/today.html'),
 'R-05': ('petitioner', 'amara-okafor', 'civic/petitions.html'),
 'R-06': ('registered candidate', 'diego-ramos', 'social/profile.html?who=diego-ramos&tab=candidacy'),
 'R-07': ('endorsed candidate', 'diego-ramos', 'social/profile.html?who=diego-ramos&tab=candidacy'),
 'R-08': ('election board member', 'fatima-al-rashid', 'electoral/election-board-console.html'),
 'R-09': ('seated representative', 'marcus-chen', 'legislature/legislature-home.html'),
 'R-10': ('speaker', 'yuki-tanaka', 'legislature/speaker-tools.html'),
 'R-11': ('committee member', 'marcus-chen', 'legislature/committees.html'),
 'R-12': ('committee chair', 'marcus-chen', 'legislature/committee-detail.html'),
 'R-13': ('alternate committee chair', 'asha-okonkwo', 'legislature/committees.html'),
 'R-14': ('executive committee member', 'kwame-mensah', 'executive/executive-home.html'),
 'R-15': ('elected executive (committee)', 'mei-lin-zhou', 'executive/executive-home.html'),
 'R-16': ('elected executive (individual)', 'ingrid-solberg', 'executive/executive-home.html'),
 'R-17': ('executive advisor', 'noor-haddad', 'executive/executive-home.html'),
 'R-18': ('board of governors member', 'samuel-adeyemi', 'executive/department-reporting.html'),
 'R-19': ('appointed judge', 'lena-novak', 'judiciary/judiciary-home.html'),
 'R-20': ('elected judge', 'rosa-delgado', 'judiciary/judiciary-home.html'),
 'R-21': ('registered advocate', 'sofia-petrova', 'judiciary/advocate-console.html'),
 'R-22': ('juror', 'omar-farouk', 'judiciary/juror-view.html'),
 'R-23': ('organization agent', 'priya-sharma', 'organizations/org-registry.html'),
 'R-24': ('member / shareholder', 'priya-sharma', 'social/org-profile.html'),
 'R-25': ('organization worker', 'tomas-ferreira', 'organizations/co-determination.html'),
 'R-26': ('owner-elected board member', 'helena-brandt', 'organizations/board-elections.html'),
 'R-27': ('worker-elected board member', 'tomas-ferreira', 'organizations/board-elections.html'),
 'R-28': ('organization board chair', 'cyrus-tehrani', 'organizations/board-elections.html'),
 'R-29': ('administrative officer', 'halima-diallo', 'legislature/oversight.html'),
 'R-30': ('civil officer', 'grace-mwangi', 'executive/departments.html'),
}

# ---- canonical <- workflows-catalog drift (build instructions section 2 + verified new finds)
ALIASES = {
 'F-IND-004': ['F-IND-005 · workflows catalog (swapped)'],
 'F-IND-005': ['F-IND-004 · workflows catalog (swapped)'],
 'F-IND-016': ['F-IND-013 · workflows catalog (WF-JUD-05)'],
 'F-CHR-001': ['F-COM-001 · workflows catalog'],
 'F-CHR-002': ['F-COM-002 · workflows catalog'],
 'F-CHR-003': ['F-COM-003 · workflows catalog'],
 'F-CHR-004': ['F-COM-004 · workflows catalog'],
 'F-BOG-001': ['F-GOV-001 · workflows catalog'],
 'F-BOG-002': ['F-GOV-002 · workflows catalog'],
 'F-LEG-022': ['F-LEG-034 · workflows catalog'],
 'F-LEG-023': ['F-LEG-022 · workflows catalog'],
 'F-LEG-024': ['F-LEG-023 · workflows catalog'],
 'F-LEG-025': ['F-LEG-024 · workflows catalog'],
 'F-LEG-036': ['F-LEG-030 · workflows catalog'],
}

# ---- entity display-state rewords (plain language). Keys are the exact split
# tokens; the two Organization tokens repair a bracket pair the split malforms.
# Machine ids stay untouched; statesRaw keeps the source string.
STATE_REWORDS = {
 '[Transfer-Pending': 'Transfer pending',
 'Transferred]': 'Transferred',
 'Panel-Assigned (≥3, odd, severity-scaled)': 'Panel chosen (3 or more judges, always an odd number)',
 'Boundary-Loaded (dormant)': 'On the map (dormant)',
 'Trust-Established': 'Trust established',
}


def stage_of(wid):
    fam = wid.split('-')[1]
    n = int(wid.split('-')[2])
    if fam == 'CIV':
        return 2 if n in (4, 5, 8) else 1
    return {'ELE': 2, 'LEG': 3, 'EXE': 4, 'ORG': 4, 'JUD': 5, 'JUR': 6, 'SYS': 6}[fam]


def build_registry():
    roles_wb = dump_xlsx(ROLES_XLSX)
    cat_wb = dump_xlsx(CATALOG_XLSX)

    cur = None
    roles = []
    for r in roles_wb['1. Roles']:
        c = r['cells']
        if g(c, 0) == 'CAT':
            cur = g(c, 1).replace(' TIER', '')
            continue
        if re.match(r'^R-\d', g(c, 0)):
            sn, dp, es = AUG[g(c, 0)]
            roles.append({'id': g(c, 0), 'name': g(c, 1), 'tier': cur, 'desc': g(c, 2),
                          'basis': g(c, 4), 'prereq': g(c, 5), 'acquisition': g(c, 6),
                          'shortName': sn, 'defaultPersona': dp, 'entryScreen': es})

    institutions = []
    for r in roles_wb['2. Institutions']:
        c = r['cells']
        if re.match(r'^I-', g(c, 0)):
            institutions.append({'id': g(c, 0), 'name': g(c, 1), 'desc': g(c, 2),
                                 'createdBy': g(c, 3), 'method': g(c, 4)})

    forms = []
    for r in roles_wb['3. Forms Catalog']:
        c = r['cells']
        if re.match(r'^F-', g(c, 0)):
            forms.append({'id': g(c, 0), 'name': g(c, 1), 'desc': g(c, 2),
                          'availableTo': rids(g(c, 3)), 'availableToRaw': g(c, 3),
                          'prereq': g(c, 4), 'creates': g(c, 5), 'basis': g(c, 6),
                          'aliases': ALIASES.get(g(c, 0), [])})

    workflows = []
    for r in cat_wb['1. Workflow Inventory']:
        c = r['cells']
        if re.match(r'^WF-', g(c, 0)):
            wid = g(c, 0)
            workflows.append({'id': wid, 'family': wid.split('-')[1], 'name': g(c, 1),
                              'timeScale': g(c, 2), 'trigger': g(c, 3), 'actors': g(c, 4),
                              'institutions': g(c, 5), 'forms': g(c, 6), 'terminal': g(c, 7),
                              'basis': g(c, 8), 'stage': stage_of(wid)})

    clocks = []
    for r in cat_wb['4. Clocks & Triggers']:
        c = r['cells']
        if re.match(r'^CLK-', g(c, 0)):
            clocks.append({'id': g(c, 0), 'name': g(c, 1), 'type': g(c, 2), 'default': g(c, 3),
                           'amendable': g(c, 4), 'fires': g(c, 5), 'basis': g(c, 6)})
    clocks.sort(key=lambda x: x['id'])

    entities = []
    for r in cat_wb['3. Entity State Machines']:
        c = r['cells']
        if r['row'] > 1 and g(c, 0):
            # Owner wording: outcomes display as Elected / Not elected (never "Defeated").
            raw = g(c, 1).replace('Defeated', 'Not elected')
            states = [s.strip() for s in re.split(r'→|->', raw) if s.strip()]
            states = [STATE_REWORDS.get(s, s) for s in states]
            entities.append({'id': g(c, 0), 'statesRaw': raw, 'states': states, 'notes': g(c, 2)})

    cur = None
    voteTypes = []
    for r in roles_wb['7. Special Vote Types']:
        c = r['cells']
        if g(c, 0) == 'CAT':
            cur = g(c, 1)
            continue
        if r['row'] > 1 and g(c, 0):
            voteTypes.append({'category': cur, 'action': g(c, 0), 'threshold': g(c, 1),
                              'who': g(c, 2), 'trigger': g(c, 3), 'basis': g(c, 4)})

    cur = None
    bootstrap = []
    for r in roles_wb['6. Bootstrap Sequence']:
        c = r['cells']
        if g(c, 0) == 'CAT':
            cur = g(c, 1)
            continue
        if re.match(r'^\d+$', g(c, 0)):
            bootstrap.append({'step': int(g(c, 0)), 'stage': cur, 'action': g(c, 1),
                              'details': g(c, 2), 'forms': g(c, 3)})

    return {'roles': roles, 'institutions': institutions, 'forms': forms, 'workflows': workflows,
            'clocks': clocks, 'entities': entities, 'voteTypes': voteTypes, 'bootstrap': bootstrap}


HEADER = '''/* ============================================================================
   CGA MOCKUPS — fixtures.js
   The seed world. `registry` = the constitutional static layer, generated
   verbatim from CGA_Constitutional_Roles_Forms_Chart.xlsx and
   CGA_Workflows_Catalog.xlsx with the build-instructions §2 ID-drift
   resolutions applied once, here (canonical IDs; catalog aliases recorded
   per form). `world` = the fictional demo data over real geography (§7).
   Counts: 30 roles / 17 institutions / 103 forms / 80 workflows / 21 clocks /
   20 entity state machines. People, parties, and companies are FICTIONAL;
   geography is real (the product preloads it).
   GENERATED by mockups/tools/gen_fixtures.py from the workbook dumps — edit
   the generator or tools/world_block.js, not the registry block here.
   ============================================================================ */
(function () {
  'use strict';
  window.CGA = window.CGA || {};
'''


def main():
    registry = build_registry()
    expected = {'roles': 30, 'institutions': 17, 'forms': 103, 'workflows': 80,
                'clocks': 21, 'entities': 20, 'voteTypes': 33, 'bootstrap': 30}
    counts = {k: len(v) for k, v in registry.items()}
    if counts != expected:
        print('COUNT DRIFT vs Stage-0 baseline (update OPEN_QUESTIONS/MANIFEST if intentional):')
        print('  expected', expected)
        print('  actual  ', counts)

    world = open(os.path.join(HERE, 'world_block.js'), encoding='utf-8').read()
    js = json.dumps(registry, ensure_ascii=False, indent=1)
    body = (HEADER + '\n  /* ----------------------------------------------------------- REGISTRY */\n  var registry = '
            + js.replace('\n', '\n  ') + ';\n' + world)
    open(OUT_JS, 'w', encoding='utf-8').write(body)
    print('written', OUT_JS, len(body), 'bytes —', counts)


if __name__ == '__main__':
    main()

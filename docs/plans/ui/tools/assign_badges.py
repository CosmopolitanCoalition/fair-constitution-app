# -*- coding: utf-8 -*-
"""Assign a Lane+Wave badge (e.g. L1W3) to every UI Screen, Capability, and Tech-Debt item.
Rules: owner field + verified W3 transitions + the Wave-4 standing orders. Flag anything unassigned."""
import json, re, io

base = json.load(open(r'E:\fair-constitution-app\docs\plans\ui\v3_gap_data.json', encoding='utf-8'))
res = json.load(open(r'C:\Users\JOSEPH~1\AppData\Local\Temp\claude\E--fair-constitution-app\355910cb-829c-4f85-8b3a-3a74acb84871\scratchpad\rubric_data.json', encoding='utf-8'))

trans = {}
for sc in res['screens']:
    for ch in sc.get('changes', []):
        trans[ch['file']] = ch['nowBucket']

# --- the 9 screens Wave 3 pushed to BUILT (exchange went absent->partial, still W4) ---
W3_BUILT = {'learn/learn-home.html','learn/lesson.html','learn/guides.html','index.html','tour.html',
            'shared/live-room.html','shared/coverage.html','shared/coverage-ops.html','executive/department-detail.html'}

def prim_lane(owner):
    nums = re.findall(r'\d+', owner or '')
    return nums[0] if nums else None

# Wave-4 owning lane per area (from the standing orders) for PENDING screens
W4_AREA_LANE = {
    'Economy':'13','Organizations':'13','Civic':'6','Social + groups':'6',
    'Electoral + executive':'3','Jurisdictions + system':'2','Operator plane':'2',
}
# specific pending-screen overrides (by file)
W4_FILE_LANE = {
    'shared/atlas.html':'4','shared/bill.html':'6',
    'learn-tr-support/video-player.html':'5','shared/video-player.html':'5',
    'translation/translation-home.html':'5',
}

flags = []
screens = []
for r in base['rows']:
    f = r['file']; bucket = trans.get(f, r['bucket'])
    if bucket == 'built':
        wave = 'W3' if f in W3_BUILT else 'W2'   # W3 transitions, else built-by-Wave-2
        lane = prim_lane(r.get('owner',''))
    else:  # partial or absent -> Wave 4
        wave = 'W4'
        lane = W4_FILE_LANE.get(f) or W4_AREA_LANE.get(r['areaLabel']) or prim_lane(r.get('owner',''))
    if not lane:
        flags.append(('SCREEN', f, r['title'], 'no lane'))
        lane = '?'
    badge = 'L%sW%s' % (lane, wave[1:]) if wave.startswith('W') else 'L%s·%s' % (lane, wave)
    screens.append({**{k:r.get(k) for k in ('file','title','page','route','props','backend','owner','propsMissing','backendMissing','specHas','appAhead','notes')},
                    'area':r['areaLabel'],'bucket':bucket,'effort':r.get('effort','none'),'badge':badge,'lane':lane,'wave':wave})

# --- capabilities: lane by area, wave by maturity/origin ---
AREA_LANE = {'Identity':'1','Elections':'1','Legislature':'3','Executive':'3','Judiciary':'3',
             'Economy':'13','Social/Orgs':'3','Education':'15','Federation':'2','Simulation':'4'}
# capability-specific lane overrides (pending items reassigned by Wave-4 orders)
def cap_lane(c):
    cap = c['capability'].lower()
    if 'edit your own social profile' in cap or 'direct message' in cap: return '15'
    if 'type b' in cap: return '1'          # seating owner (joint w/ 3)
    if 'rankedballot' in cap or 'live provisional' in cap: return '3'
    if 'mobile app' in cap or 'capacitor' in cap: return '2'
    if 'internationaliz' in cap or 'i18n' in cap or 'locales' in cap: return '5'
    if 'demo-mesh coordinated time' in cap: return '2'
    return AREA_LANE.get(c['area'], None)
# working caps built in the v3 waves (not the Phase-0-5 engine)
def cap_wave(c):
    m = c['maturity']; cap = c['capability'].lower(); area = c['area']
    if m in ('partial','blocked','absent'):
        if 'mobile app' in cap or 'capacitor' in cap: return 'P6'
        return 'W4'
    # working:
    if area == 'Education': return 'W3'
    if area == 'Economy' and 'treasury' not in cap: return 'W3'
    if 'live civic rooms' in cap or 'sealed testimony' in cap: return 'W3'
    if 'demo-mesh' in cap or 'coordinated time' in cap: return 'W3'
    if area == 'Simulation': return 'W3'
    if area == 'Federation': return 'W2'
    return 'P'   # Phases 0-5 engine

caps = []
for c in res['capabilities']['capabilities']:
    lane = cap_lane(c); wave = cap_wave(c)
    if not lane:
        flags.append(('CAP', c['area'], c['capability'], 'no lane')); lane='?'
    badge = 'L%sW%s' % (lane, wave[1:]) if wave[0]=='W' else 'L%s·%s' % (lane, wave)
    caps.append({'area':c['area'],'capability':c['capability'],'maturity':c['maturity'],
                 'scaleNote':c.get('scaleNote',''),'blocker':c.get('blocker',''),'badge':badge,'lane':lane,'wave':wave})

# --- debt: lane from owner, wave = W4 (paydown) ---
debt = []
for d in res['techDebt']['debt']:
    lane = prim_lane(d.get('owner',''))
    if not lane:
        # some owners are 'fleet' / 'unowned' / 'desk' -> map
        o = (d.get('owner') or '').lower()
        if 'desk' in o or 'lane 7' in o: lane='7'
        elif 'fleet' in o: lane='6'   # pixel debt -> lane 6 owns the walk/pixels
        elif 'unowned' in o: lane='?'
    wave = 'W4'
    if not lane:
        flags.append(('DEBT', d['title'][:40], '', 'no lane')); lane='?'
    badge = 'L%sW%s' % (lane, wave[1:])
    debt.append({'title':d['title'],'severity':d['severity'],'category':d.get('category',''),
                 'owner':d.get('owner',''),'location':d.get('location',''),'status':d.get('status',''),
                 'note':d.get('note',''),'badge':badge,'lane':lane,'wave':wave})

# save enriched
enriched = {'screens':screens,'caps':caps,'debt':debt}
json.dump(enriched, io.open(r'C:\Users\JOSEPH~1\AppData\Local\Temp\claude\E--fair-constitution-app\355910cb-829c-4f85-8b3a-3a74acb84871\scratchpad\badged.json','w',encoding='utf-8'), ensure_ascii=False)

# report
from collections import Counter
print("=== BADGE COVERAGE ===")
print("screens:", len(screens), "| badge dist:", dict(Counter(s['badge'] for s in screens)))
print("caps:", len(caps), "| wave dist:", dict(Counter(c['wave'] for c in caps)))
print("debt:", len(debt), "| lane dist:", dict(Counter(d['lane'] for d in debt)))
print()
print("=== FLAGS (unassigned = PROBLEM) ===", len(flags))
for f in flags: print("  ", f)
print()
print("=== any '?' lane items ===")
for s in screens:
    if s['lane']=='?': print("  SCREEN", s['badge'], s['file'], s['title'])
for c in caps:
    if c['lane']=='?': print("  CAP", c['badge'], c['area'], c['capability'])
for d in debt:
    if d['lane']=='?': print("  DEBT", d['badge'], d['title'][:50], '| owner:', d['owner'])

#!/usr/bin/env python3
"""Good Maps scorer — compare a candidate map's stats CSV against a standard's.

Usage: python3 score.py <standard.csv> <candidate.csv> <chamber_budget>

Scores in the operator's priority order (see ../README.md):
  1. LEGALITY    chamber total == budget; zero unsanctioned band violations
                 ((seats<5 AND NOT floor_override) OR seats>9)
  2. CONTIGUITY  candidate's non-contiguous clusters vs the standard's
                 (matched by scope + member set; new clusters = defects
                 unless water-separated by nature — listed for review)
  3. COMPACTNESS per-scope avg CHR at-or-above the standard's
  4. DEVIATION   per-scope fit gap (sum |frac-seats|) at-or-below the standard's

Both CSVs come from export_map_stats.sh / the committed stats/ files.
"""
import csv
import sys
from collections import defaultdict

# Jurisdiction names carry the full Unicode range (Gujarāt, Rājasthān …);
# a cp1252 Windows console must not kill the report mid-print.
for _s in (sys.stdout, sys.stderr):
    if hasattr(_s, 'reconfigure'):
        _s.reconfigure(encoding='utf-8', errors='replace')


def load(path):
    scopes = defaultdict(list)
    with open(path, encoding='utf-8') as f:
        for r in csv.DictReader(f):
            r['seats'] = int(r['seats'])
            r['fractional_seats'] = float(r['fractional_seats'])
            r['floor_override'] = r['floor_override'] == 't'
            r['is_contiguous'] = r['is_contiguous'] == 't'
            r['convex_hull_ratio'] = float(r['convex_hull_ratio']) if r['convex_hull_ratio'] else None
            scopes[r['scope']].append(r)
    return scopes


def rollup(scopes):
    out = {}
    for s, rows in scopes.items():
        chrs = [r['convex_hull_ratio'] for r in rows if r['convex_hull_ratio'] is not None]
        out[s] = {
            'districts': len(rows),
            'seats': sum(r['seats'] for r in rows),
            # Ceiling-exception bonus seats (2026-08-28) are LAW, not a
            # quality defect — fit measures the lawful landing.
            'fit': sum(abs(r['fractional_seats'] - (r['seats'] - int(r.get('bonus_seats') or 0))) for r in rows),
            'bonus': sum(int(r.get('bonus_seats') or 0) for r in rows),
            'noncontig': sum(1 for r in rows if not r['is_contiguous']),
            'avg_chr': sum(chrs) / len(chrs) if chrs else None,
            'band': sum(1 for r in rows if (r['seats'] < 5 and not r['floor_override']) or r['seats'] > 9),
        }
    return out


def clusters(scopes):
    """Non-contiguous districts as (scope, frozenset(members))."""
    out = set()
    for s, rows in scopes.items():
        for r in rows:
            if not r['is_contiguous']:
                members = frozenset(m.strip() for m in (r['members'] or '').split(';') if m.strip())
                out.add((s, members))
    return out


def fmt(x, nd=3):
    return f'{x:.{nd}f}' if isinstance(x, float) else ('—' if x is None else str(x))


def main():
    std_path, cand_path, budget = sys.argv[1], sys.argv[2], int(sys.argv[3])
    std, cand = load(std_path), load(cand_path)
    rs, rc = rollup(std), rollup(cand)

    total_std = sum(v['seats'] for v in rs.values())
    total_cand = sum(v['seats'] for v in rc.values())
    band_cand = sum(v['band'] for v in rc.values())
    bonus_cand = sum(v['bonus'] for v in rc.values())
    lawful_cand = total_cand - bonus_cand

    print(f'=== 1 LEGALITY ===')
    bonus_note = f' (+{bonus_cand} ceiling-exception bonus = {total_cand} seated)' if bonus_cand else ''
    print(f'chamber: candidate {lawful_cand} vs budget {budget} vs standard {total_std}{bonus_note}'
          f'  -> {"PASS" if lawful_cand == budget else "FAIL (drift " + str(lawful_cand - budget) + ")"}')
    print(f'band violations: {band_cand}  -> {"PASS" if band_cand == 0 else "FAIL"}')

    cs, cc = clusters(std), clusters(cand)
    new = cc - cs
    fixed_vs_std = cs - cc
    print(f'\n=== 2 CONTIGUITY ===')
    print(f'standard clusters {len(cs)} | candidate {len(cc)} | shared {len(cc & cs)} | '
          f'candidate-new {len(new)} | standard-only {len(fixed_vs_std)}')
    for s, m in sorted(new):
        print(f'  NEW: {s}: {", ".join(sorted(m))[:140]}')
    for s, m in sorted(fixed_vs_std):
        print(f'  std-only (candidate contiguous here): {s}: {", ".join(sorted(m))[:100]}')

    print(f'\n=== 3+4 PER-SCOPE (candidate vs standard) ===')
    print(f'{"scope":32s} {"dist":>9s} {"seats":>11s} {"ncontig":>7s} {"avgCHR":>15s} {"fit":>13s}')
    wins_chr = ties_chr = loss_chr = wins_fit = ties_fit = loss_fit = 0
    for s in sorted(set(rs) | set(rc)):
        a, b = rs.get(s), rc.get(s)
        if a is None or b is None:
            print(f'{s:32s}  {"MISSING IN " + ("CANDIDATE" if b is None else "STANDARD")}')
            continue
        dchr = (b['avg_chr'] - a['avg_chr']) if (a['avg_chr'] is not None and b['avg_chr'] is not None) else None
        dfit = b['fit'] - a['fit']
        if dchr is not None:
            if dchr > 0.005: wins_chr += 1
            elif dchr < -0.005: loss_chr += 1
            else: ties_chr += 1
        if dfit < -0.005: wins_fit += 1
        elif dfit > 0.005: loss_fit += 1
        else: ties_fit += 1
        flag = ''
        if b['seats'] != a['seats']: flag += ' SEATS!'
        if b['band']: flag += ' BAND!'
        marker = '=' if (rs.get(s) and dchr is not None and abs(dchr) < 0.005 and abs(dfit) < 0.005 and b['districts'] == a['districts'] and b['noncontig'] == a['noncontig']) else ' '
        print(f'{s:32s}{marker}{b["districts"]:>4d}/{a["districts"]:<4d} {b["seats"]:>5d}/{a["seats"]:<5d} '
              f'{b["noncontig"]:>3d}/{a["noncontig"]:<3d} {fmt(b["avg_chr"]):>7s}/{fmt(a["avg_chr"]):<7s} '
              f'{fmt(b["fit"], 2):>6s}/{fmt(a["fit"], 2):<6s}{flag}')

    n_std = sum(v['noncontig'] for v in rs.values())
    n_cand = sum(v['noncontig'] for v in rc.values())
    chr_all_s = [r['convex_hull_ratio'] for rows in std.values() for r in rows if r['convex_hull_ratio'] is not None]
    chr_all_c = [r['convex_hull_ratio'] for rows in cand.values() for r in rows if r['convex_hull_ratio'] is not None]
    fit_s = sum(v['fit'] for v in rs.values())
    fit_c = sum(v['fit'] for v in rc.values())
    print(f'\n=== VERDICT (candidate vs standard) ===')
    print(f'districts {sum(v["districts"] for v in rc.values())} vs {sum(v["districts"] for v in rs.values())} | '
          f'noncontig {n_cand} vs {n_std} | '
          f'avg CHR {sum(chr_all_c)/len(chr_all_c):.4f} vs {sum(chr_all_s)/len(chr_all_s):.4f} | '
          f'fit {fit_c:.2f} vs {fit_s:.2f}')
    print(f'per-scope CHR: candidate better {wins_chr}, tied {ties_chr}, worse {loss_chr} | '
          f'fit: better {wins_fit}, tied {ties_fit}, worse {loss_fit}')


if __name__ == '__main__':
    main()

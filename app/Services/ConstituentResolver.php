<?php

namespace App\Services;

use App\Models\Legislature;
use Illuminate\Support\Facades\DB;

/**
 * Constituent resolution (PHASE_E_DESIGN_judiciary §B.1 — the surgical
 * extraction out of ExecutiveFormationService). "Constituent" = a DIRECT
 * child jurisdiction holding a non-dissolved legislature (a body that can
 * vote) — the WF-JUR-04 precedent inherited from the executive design;
 * flagged q-ledger candidate.
 *
 * Both formation services (executive F-LEG-015 conversion, judiciary
 * F-LEG-018 conversion) consume this ONE resolver — zero behavioral change
 * (ExecConversionDualSupermajorityTest stays green).
 */
class ConstituentResolver
{
    /**
     * Constituents = DIRECT child jurisdictions holding a non-dissolved
     * legislature.
     *
     * @return list<string>
     */
    public static function ids(Legislature $legislature): array
    {
        return DB::table('jurisdictions as j')
            ->join('legislatures as l', 'l.jurisdiction_id', '=', 'j.id')
            ->where('j.parent_id', $legislature->jurisdiction_id)
            ->whereNull('j.deleted_at')
            ->whereNull('l.deleted_at')
            ->where('l.status', '!=', 'dissolved')
            ->pluck('j.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Direct children with NO legislature (record note — they cannot
     * consent to a constituent process).
     *
     * @return list<string>
     */
    public static function childlessNames(Legislature $legislature): array
    {
        return DB::table('jurisdictions as j')
            ->leftJoin('legislatures as l', function ($join) {
                $join->on('l.jurisdiction_id', '=', 'j.id')->whereNull('l.deleted_at');
            })
            ->where('j.parent_id', $legislature->jurisdiction_id)
            ->whereNull('j.deleted_at')
            ->whereNull('l.id')
            ->pluck('j.name')
            ->map(fn ($name) => (string) $name)
            ->all();
    }
}

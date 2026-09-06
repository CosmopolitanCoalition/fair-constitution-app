<?php

namespace App\Services\Demo\Stages;

use App\Models\Bill;
use App\Models\BillVersion;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Organization;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The CIVICS stage — CENSUS-FLAVORED organizations + bills (operator ruling
 * 2026-08-08, rubric `sim-org-bill-rates` = B: "For the Purposes of Demo
 * simulation and development purposes We want census flavored").
 *
 * RATES ARE REAL, ROWS ARE SAMPLED. The per-capita anchors (config
 * cga.sim_civics, each cited there) give every jurisdiction its TRUE census
 * count; a sample dial mints every Nth entity as a real row so planet-scale
 * realism stays inside an 8 GB box (the SIM_SCALING_PLAN's own first-mistake
 * lesson: materialise at leaf grain, never multiply the tree). The true
 * counts ride the item's metrics as the aggregate truth for any surface that
 * wants census numbers.
 *
 * WHY BULK, NOT FORMS: organizations and bill introductions are INDIVIDUAL
 * acts (a resident registers an org; a member files a bill) — mechanically
 * derivable substrate in the plan's taxonomy (like identities, candidacies,
 * ballot envelopes), not acts of self-government. Chunked bulk inserts with
 * ONE audit append per item (the InstitutionStubService pattern) keep the
 * hash chain off the per-row path. Committees/departments/judiciaries stay
 * with their vote-driven stages — nothing here touches them.
 *
 * Deterministic by construction (no RNG): name vocabularies + index math,
 * so a re-run is idempotent (counts top up, never duplicate) and any two
 * boxes generate the same world. Worker counts cycle a small-business
 * pattern with periodic 150s and 2,500s, so the Art. III §6 worker-rep
 * thresholds (100 first seat / 2,000 parity) have live exemplars.
 */
final class CivicsStage
{
    private const PARTY_NAMES = [
        'Unity', 'Progress', 'Heritage', 'Horizon', 'Commons',
        'Frontier', 'Stewardship', 'Concord',
    ];

    private const NONPROFIT_SUFFIXES = [
        'Foundation', 'Mutual Aid Society', 'Community Trust', 'Relief Fund',
        'Cultural Circle', 'Learning Cooperative', 'Health Alliance', 'Heritage Society',
    ];

    private const BUSINESS_SUFFIXES = [
        'Trading Co.', 'Works', 'Farms', 'Logistics', 'Crafts',
        'Provisions', 'Builders', 'Services', 'Foundry', 'Mercantile',
    ];

    /** Deterministic worker-count cycle; every 20th = 150 (past the first-seat
     *  threshold), every 50th = 2,500 (past parity) — Art. III §6 exemplars. */
    private const WORKER_CYCLE = [3, 7, 12, 25, 60];

    private const BILL_TOPICS = [
        'Water Infrastructure', 'Road Maintenance', 'Public Libraries',
        'Market Regulation', 'Land Registry', 'Emergency Preparedness',
        'Sanitation Works', 'Energy Provision', 'Civic Education',
        'Public Health', 'Housing Standards', 'Transit Services',
    ];

    private const CHUNK = 500;

    private function __construct() {}

    /**
     * @return array{
     *     parties: array{true:int, minted:int},
     *     nonprofits: array{true:int, minted:int},
     *     businesses: array{true:int, minted:int},
     *     bills: array{true:int, minted:int},
     *     skipped: ?string
     * }
     */
    public static function run(string $jurisdictionId, ?string $runId, int $version, ?\Closure $beat = null): array
    {
        $j = DB::table('jurisdictions')
            ->where('id', $jurisdictionId)->whereNull('deleted_at')
            ->select('id', 'name', 'population')
            ->first();

        if ($j === null) {
            return self::result(skipped: 'jurisdiction gone');
        }

        $cfg = (array) config('cga.sim_civics', []);
        $pop = max(0, (int) $j->population);
        $isLeaf = ! DB::table('jurisdictions')
            ->where('parent_id', $jurisdictionId)->whereNull('deleted_at')->exists();

        $legislature = Legislature::query()
            ->where('jurisdiction_id', $jurisdictionId)->whereNull('deleted_at')->first();
        $seated = $legislature === null ? collect() : LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereNull('deleted_at')->where('status', 'elected')
            ->whereNull('vacated_at')->whereNotNull('user_id')
            ->orderBy('id')->get();

        $out = self::result();

        // ── Parties: chamber-level, the 2–8 effective-parties band. ─────────
        if ($legislature !== null && $seated->isNotEmpty()) {
            $seats = max(1, (int) $legislature->total_seats);
            $target = (int) min(
                (int) ($cfg['parties_max'] ?? 8),
                max((int) ($cfg['parties_min'] ?? 2), (int) round(2 + log($seats) / 1.5)),
            );
            $out['parties'] = self::mintOrgs(
                $j, Organization::TYPE_POLITICAL_PARTY, $target, $target,
                fn (int $i) => self::PARTY_NAMES[$i % count(self::PARTY_NAMES)].' Party',
                agentUserId: (string) $seated->first()->user_id,
                beat: $beat,
            );
        }

        // ── Nonprofits + businesses: LEAF grain only (people live at leaves;
        //    the tree carries the aggregate — the 33-billion-people lesson). ──
        if ($isLeaf && $pop > 0) {
            $sample = max(1, (int) ($cfg['org_sample'] ?? 1000));

            $trueNp = intdiv($pop, max(1, (int) ($cfg['nonprofit_per'] ?? 180)));
            $out['nonprofits'] = self::mintOrgs(
                $j, Organization::TYPE_NONPROFIT, $trueNp,
                $trueNp > 0 ? max(1, (int) ceil($trueNp / $sample)) : 0,
                fn (int $i) => $j->name.' '.self::NONPROFIT_SUFFIXES[$i % count(self::NONPROFIT_SUFFIXES)]
                    .($i >= count(self::NONPROFIT_SUFFIXES) ? ' '.(intdiv($i, count(self::NONPROFIT_SUFFIXES)) + 1) : ''),
                beat: $beat,
            );

            $trueBiz = intdiv($pop, max(1, (int) ($cfg['business_per'] ?? 10)));
            $out['businesses'] = self::mintOrgs(
                $j, Organization::TYPE_BUSINESS, $trueBiz,
                $trueBiz > 0 ? max(1, (int) ceil($trueBiz / $sample)) : 0,
                fn (int $i) => $j->name.' '.self::BUSINESS_SUFFIXES[$i % count(self::BUSINESS_SUFFIXES)]
                    .($i >= count(self::BUSINESS_SUFFIXES) ? ' '.(intdiv($i, count(self::BUSINESS_SUFFIXES)) + 1) : ''),
                workers: true,
                beat: $beat,
            );
        }

        // ── Bills: chamber dockets — introduced/referred/in-committee only
        //    (statuses with no implied vote history; adoption is the
        //    chambers' business, not a generator's). ──────────────────────────
        if ($legislature !== null && $seated->isNotEmpty()) {
            $trueBills = max(1, (int) $legislature->total_seats)
                * max(1, (int) ($cfg['bills_per_member'] ?? 20));
            $mintBills = max(3, (int) ceil($trueBills / max(1, (int) ($cfg['bill_sample'] ?? 1000))));
            $out['bills'] = self::mintBills($legislature, $j, $seated, $trueBills, $mintBills);
        }

        try {
            app(AuditService::class)->append(
                module: 'elections',
                event: 'sim.civics_minted',
                payload: ['jurisdiction_id' => (string) $j->id, 'run_id' => (string) $runId] + $out,
            );
        } catch (\Throwable) {
            // The mint is committed; a failed audit append must not fail the item.
        }

        return $out;
    }

    /** @return array{true:int, minted:int} */
    private static function mintOrgs(
        object $j, string $type, int $trueCount, int $mintCount,
        \Closure $nameFor, ?string $agentUserId = null, bool $workers = false,
        ?\Closure $beat = null,
    ): array {
        $existing = DB::table('organizations')
            ->where('jurisdiction_id', $j->id)->where('type', $type)
            ->whereNull('deleted_at')->count();

        $need = max(0, $mintCount - $existing);
        $now = now();
        $rows = [];
        for ($i = $existing; $i < $existing + $need; $i++) {
            $beat && $beat();
            $name = $nameFor($i);
            $rows[] = [
                'id' => (string) Str::uuid(),
                'jurisdiction_id' => (string) $j->id,
                'type' => $type,
                'name' => $name,
                'slug' => Str::slug($name).'-'.substr((string) $j->id, 0, 8).'-'.$i,
                'worker_count' => $workers ? self::workerCountAt($i) : 0,
                'is_active' => true,
                'is_registered' => true,
                'registered_at' => $now,
                'agent_user_id' => $agentUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($rows) >= self::CHUNK) {
                DB::table('organizations')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('organizations')->insert($rows);
        }

        return ['true' => $trueCount, 'minted' => max($existing, $mintCount)];
    }

    /** The deterministic worker-count pattern (Art. III §6 exemplars). */
    private static function workerCountAt(int $i): int
    {
        if ($i > 0 && $i % 50 === 0) {
            return 2500;   // past the 2,000 parity threshold
        }
        if ($i > 0 && $i % 20 === 0) {
            return 150;    // past the 100 first-seat threshold
        }

        return self::WORKER_CYCLE[$i % count(self::WORKER_CYCLE)];
    }

    /** @return array{true:int, minted:int} */
    private static function mintBills(
        Legislature $legislature, object $j, $seated, int $trueCount, int $mintCount,
    ): array {
        $existing = DB::table('bills')
            ->where('legislature_id', $legislature->id)->whereNull('deleted_at')->count();

        $need = max(0, $mintCount - $existing);
        if ($need === 0) {
            return ['true' => $trueCount, 'minted' => $existing];
        }

        $committees = DB::table('committees')
            ->where('legislature_id', $legislature->id)->whereNull('deleted_at')
            ->orderBy('created_at')->pluck('id')->all();

        $now = now();
        $bills = [];
        $versions = [];
        for ($i = $existing; $i < $existing + $need; $i++) {
            $topic = self::BILL_TOPICS[$i % count(self::BILL_TOPICS)];
            $sponsor = $seated[$i % $seated->count()];
            $billId = (string) Str::uuid();

            // Deterministic status mix with NO implied vote history; committee
            // routing only where the chamber actually has committees.
            $status = Bill::STATUS_INTRODUCED;
            $committeeId = null;
            if ($committees !== [] && $i % 3 === 1) {
                $status = Bill::STATUS_REFERRED;
                $committeeId = $committees[$i % count($committees)];
            } elseif ($committees !== [] && $i % 3 === 2) {
                $status = Bill::STATUS_IN_COMMITTEE;
                $committeeId = $committees[$i % count($committees)];
            }

            $bills[] = [
                'id' => $billId,
                'legislature_id' => (string) $legislature->id,
                'jurisdiction_id' => (string) $j->id,
                'sponsor_member_id' => (string) $sponsor->id,
                'title' => 'An Act on '.$topic,
                'act_type' => Bill::TYPE_ORDINARY,
                'scale' => json_encode([(string) $j->id]),
                'status' => $status,
                'committee_id' => $committeeId,
                'current_version_no' => 1,
                'introduced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $versions[] = [
                'id' => (string) Str::uuid(),
                'bill_id' => $billId,
                'version_no' => 1,
                'law_text' => sprintf(
                    'Provision for %s within %s, administered per the ordinary law of the jurisdiction.',
                    strtolower($topic), $j->name,
                ),
                'changed_by_member_id' => (string) $sponsor->id,
                'change_kind' => BillVersion::KIND_INTRODUCTION,
                'created_at' => $now,
            ];

            if (count($bills) >= self::CHUNK) {
                DB::table('bills')->insert($bills);
                DB::table('bill_versions')->insert($versions);
                $bills = [];
                $versions = [];
            }
        }
        if ($bills !== []) {
            DB::table('bills')->insert($bills);
            DB::table('bill_versions')->insert($versions);
        }

        return ['true' => $trueCount, 'minted' => max($existing, $mintCount)];
    }

    /** @return array{parties:array{true:int,minted:int}, nonprofits:array{true:int,minted:int}, businesses:array{true:int,minted:int}, bills:array{true:int,minted:int}, skipped:?string} */
    private static function result(?string $skipped = null): array
    {
        $zero = ['true' => 0, 'minted' => 0];

        return ['parties' => $zero, 'nonprofits' => $zero, 'businesses' => $zero,
            'bills' => $zero, 'skipped' => $skipped];
    }
}

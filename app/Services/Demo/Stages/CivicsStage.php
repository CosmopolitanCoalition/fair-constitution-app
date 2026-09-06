<?php

namespace App\Services\Demo\Stages;

use App\Models\Bill;
use App\Models\BillVersion;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\Organization;
use App\Services\AuditService;
use App\Services\Organizations\CgcService;
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

    /** Endorsers sampled PER KIND (orgs of any type; individual residents). */
    private const ENDORSER_SAMPLE = 8;

    /** Each endorser backs up to this many candidates in the open election. */
    private const ENDORSEMENTS_PER_ENDORSER = 2;

    /** Common Good Corporations chartered per jurisdiction (a public register). */
    private const CGC_TARGET = 2;

    private const CGC_NAMES = [
        'Public Works Corporation', 'Community Health Corporation',
        'Water Authority Corporation', 'Public Media Corporation',
    ];

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

            // Endorsements: a MIX of endorsers — organizations of every type AND
            // individual residents — back candidates in the open election. Under
            // polymorphic STV anybody or nobody may endorse any candidate, so
            // partisanship is mooted; the demo graph reflects that rather than a
            // party-only slate. Idempotent — skipped once the election carries them.
            $out['endorsements'] = self::mintEndorsements($j, $beat);

            // Common Good Corporations (Art. III §5): chartered by the chamber,
            // publicly owned, IP permanently public domain. Driven through the
            // real CgcService so the CGC register, the public stake, the
            // co-determined governor board and the genesis IP dedication all land
            // the same way a live charter would — the register is not empty.
            $out['cgcs'] = self::chartCgcs($j, $legislature, $seated, $beat);
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

    /**
     * Charter Common Good Corporations for this jurisdiction, DRIVEN through the
     * real CgcService (single owner) so every write a live charter makes lands:
     * the F-LEG-019 creation act, the public organization, the jurisdiction's
     * 100% stake (public ownership stands where shareholders would), the
     * co-determined governor board with its vacant governor seats, the GENESIS
     * IP dedication (all IP public domain, Art. III §5, irreversible), and the
     * co-determination watchers.
     *
     * The charter is a SYSTEM ACT at sim scale (ruling sub-institutions-path B):
     * a seated member proposes and a synthetic ADOPTED chamber vote carries it,
     * rather than a full per-CGC floor vote. Governor seats are left forming —
     * the overseeing executive committee nominates and the chamber consents in a
     * later pass; the register shows the CGC, its charter and its public-domain
     * IP now. Idempotent: skipped once the jurisdiction already holds CGCs.
     */
    private static function chartCgcs(object $j, Legislature $legislature, $seated, ?\Closure $beat): int
    {
        $existing = (int) DB::table('organizations')
            ->where('jurisdiction_id', $j->id)
            ->where('type', Organization::TYPE_COMMON_GOOD_CORP)
            ->whereNull('deleted_at')
            ->count();

        if ($existing > 0) {
            return $existing; // idempotent
        }

        $proposer = $seated->first();
        if ($proposer === null || empty($proposer->user_id)) {
            return 0;
        }

        // The overseeing executive committee (delegated from this legislature),
        // if one has been stood up; null is lawful — the charter records it.
        $execId = DB::table('executives')
            ->where('jurisdiction_id', $j->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'dissolved')
            ->value('id');

        $cgc = app(CgcService::class);
        $serving = max(1, (int) $legislature->total_seats);
        $created = 0;

        for ($i = 0; $i < self::CGC_TARGET; $i++) {
            $beat && $beat();

            $name = $j->name.' '.self::CGC_NAMES[$i % count(self::CGC_NAMES)];

            $vote = ChamberVote::create([
                'body_type'        => 'legislature',
                'body_id'          => (string) $legislature->id,
                'legislature_id'   => (string) $legislature->id,
                'jurisdiction_id'  => (string) $j->id,
                'vote_type'        => 'cgc_creation',
                'vote_method'      => ChamberVote::METHOD_YES_NO,
                'threshold_basis'  => 'majority',
                'serving_snapshot' => $serving,
                'stage'            => 'floor',
                'status'           => ChamberVote::STATUS_CLOSED,
                'outcome'          => ChamberVote::OUTCOME_ADOPTED,
                'opened_at'        => now(),
                'closed_at'        => now(),
            ]);

            $proposal = ChamberVoteProposal::create([
                'legislature_id'        => (string) $legislature->id,
                'proposal_kind'         => ChamberVoteProposal::KIND_CGC_CREATION,
                'proposed_by_member_id' => (string) $proposer->id,
                'vote_id'               => (string) $vote->id,
                'status'                => ChamberVoteProposal::STATUS_ADOPTED,
                'payload'               => [
                    'name'                   => $name,
                    'charter'                => "Chartered to provide {$name} as a public good. "
                        .'Public ownership; all intellectual property is permanently in the public domain (Art. III §5).',
                    'goods_services'         => $name,
                    'oversight_executive_id' => $execId !== null ? (string) $execId : null,
                    'owner_seats'            => 3,
                ],
            ]);

            $cgc->adoptCreation($vote, $proposal);
            $created++;
        }

        return $created;
    }

    /**
     * A MIX of endorsers back candidates in the jurisdiction's open election.
     *
     * Endorsement is polymorphic (endorsements.endorser_type): ANY organization
     * of ANY type AND any individual resident may endorse any candidate, or
     * nobody may — partisanship is mooted under polymorphic STV. So the endorser
     * set is a sample of the jurisdiction's orgs across every type plus a sample
     * of its residents, NOT a party slate. Candidates are assigned round-robin,
     * so a popular candidate collects several endorsements from different kinds
     * of endorser, which is the graph the demo should show.
     *
     * Deterministic (id order) and idempotent: if the election already carries
     * endorsements, this is a no-op. Returns the endorsement count.
     */
    private static function mintEndorsements(object $j, ?\Closure $beat): int
    {
        $election = DB::table('elections')
            ->where('jurisdiction_id', $j->id)
            ->where('kind', 'general')
            ->whereNotIn('status', ['certified', 'cancelled', 'final'])
            ->orderByDesc('created_at')
            ->first();

        if ($election === null) {
            return 0;
        }

        $existing = (int) DB::table('endorsements')->where('election_id', $election->id)->count();

        if ($existing > 0) {
            return $existing; // idempotent: already endorsed this cycle
        }

        // Endorsers: organizations of EVERY type, then individual residents — the
        // polymorphic set, capped per kind so the pass stays bounded.
        $endorsers = [];

        foreach (DB::table('organizations')
            ->where('jurisdiction_id', $j->id)->whereNull('deleted_at')
            ->orderBy('id')->limit(self::ENDORSER_SAMPLE)->pluck('id') as $orgId) {
            $endorsers[] = ['type' => 'organizations', 'id' => (string) $orgId];
        }

        foreach (DB::table('residency_confirmations as rc')
            ->join('users as u', 'u.id', '=', 'rc.user_id')
            ->where('rc.jurisdiction_id', $j->id)->where('rc.is_active', true)
            ->where('u.email', 'like', 'sim-%@demo.invalid')
            ->orderBy('rc.user_id')->limit(self::ENDORSER_SAMPLE)->pluck('rc.user_id') as $userId) {
            $endorsers[] = ['type' => 'users', 'id' => (string) $userId];
        }

        if ($endorsers === []) {
            return 0;
        }

        $candidates = DB::table('candidacies')
            ->where('election_id', $election->id)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($candidates === []) {
            return 0;
        }

        // Never more per endorser than there are candidates — a small field must
        // not have one endorser back the same candidate twice.
        $perEndorser = min(self::ENDORSEMENTS_PER_ENDORSER, count($candidates));

        $now = now();
        $rows = [];
        $pick = 0;
        foreach ($endorsers as $e) {
            for ($k = 0; $k < $perEndorser; $k++) {
                $rows[] = [
                    'id' => (string) Str::uuid(),
                    'election_id' => (string) $election->id,
                    // Round-robin: candidates collect endorsements from many
                    // kinds of endorser, and a small field is not exhausted.
                    'candidate_id' => (string) $candidates[$pick++ % count($candidates)],
                    'endorser_type' => $e['type'],
                    'endorser_id' => $e['id'],
                    'statement' => null,
                    'endorsed_at' => $now,
                    'is_active' => true,
                    'is_public' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $beat && $beat();
            DB::table('endorsements')->insert($chunk);
        }

        return count($rows);
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
            'bills' => $zero, 'endorsements' => 0, 'cgcs' => 0, 'skipped' => $skipped];
    }
}

<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly world rollup — the Atlas's data spine (ATLAS_DESIGN.md §3).
 *
 * One dated row of world totals, written once a night, read O(1) by the Atlas.
 * This exists because the alternative is impossible AND unsafe: computing the
 * vital signs per page load IS the ~75-second `SimConsoleController::world()`
 * aggregate, and a live headcount would hand an observer sub-minute resolution
 * on numbers k-anonymity publishes once a day — letting them defeat the
 * suppression by differencing. Snapshot or nothing, exactly as reach works.
 *
 * ── The rails ───────────────────────────────────────────────────────────────
 *  - CI-1, A GAUGE NEVER A LEVER. Nothing here is consulted on a rights path.
 *    There is no per-person figure anywhere in the payload — only places and
 *    institutions are counted.
 *  - CI-6, AUTHORITY NOT LEADERSHIP. Every count is scoped to jurisdictions
 *    this instance is authoritative for (`authoritative_server_id IS NULL`). A
 *    mirror runs its own scheduler and wins its own leader probe, so without
 *    this it would publish totals for places it does not own. Peers publish
 *    their own row; the Atlas sums them (§8 Q2, ruled option (a)).
 *  - A SUPPRESSED SNAPSHOT NEVER CONTRIBUTES A NUMBER. `verifiedTotal` sums
 *    published rows only. A place whose night is withheld still counts toward
 *    "places gauged" — it is gauged, we simply may not say by how much. Adding
 *    it to the total would republish the very count the floor withheld.
 *  - THE ETL RULE. The jurisdiction pass is keyset-chunked with per-chunk
 *    progress, never one planet-wide statement. (The reach precedent omits
 *    progress; this does not inherit that.)
 *
 * ⚑ NULL MEANS "NOT MEASURED", NEVER ZERO. A domain this instance cannot
 * compute is left ABSENT from the payload rather than written as 0 — the Atlas
 * renders an absent figure as an em-dash. Writing 0 would be a claim.
 */
class WorldStatsService
{
    /** Jurisdictions read per keyset page (the ETL rule — never one statement). */
    private const CHUNK = 25_000;

    /** The nil uuid seeds the keyset cursor. */
    private const NIL_UUID = '00000000-0000-0000-0000-000000000000';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Compute the whole payload. PURE — reads only, writes nothing, needs no
     * `world_stats` table, so the aggregation is testable before the table
     * exists and the CLI can print it on an instance that has never rolled up.
     *
     * @param  callable(string,int):void|null  $onProgress  (domain, rowsSeen)
     * @return array<string,array<string,mixed>>
     */
    public function compute(?callable $onProgress = null): array
    {
        $domains = [];

        $domains['world'] = $this->world($onProgress);
        $domains['reach'] = $this->reach();
        $domains['representation'] = $this->representation();
        $domains['executive'] = $this->executive();
        $domains['judiciary'] = $this->judiciary();
        $domains['organizations'] = $this->organizations();
        $domains['mesh'] = $this->mesh();

        // economy + people are RULED `planned` (§8 Q4, option (a)) until lane 13
        // and the achievements ledger land. They are ABSENT, not zeroed — the
        // Atlas badges the cards `Planned` and renders every tile as a gap.

        return $domains;
    }

    /**
     * Compute and persist one dated row, then append a single audit entry for
     * the run (the reach precedent's shape: one entry, not one per chunk).
     *
     * Idempotent for a given date: re-running replaces the row.
     *
     * @param  callable(string,int):void|null  $onProgress
     * @return array{as_of_date:string, domains:array<string,mixed>}
     */
    public function snapshot(?string $asOfDate = null, ?callable $onProgress = null): array
    {
        $date = $asOfDate ?? now()->toDateString();
        $domains = $this->compute($onProgress);

        DB::table('world_stats')->updateOrInsert(
            ['as_of_date' => $date],
            ['domains' => json_encode($domains), 'updated_at' => now(), 'created_at' => now()],
        );

        $this->audit->append(
            module: 'system',
            event: 'world.stats_rollup',
            payload: [
                'as_of_date' => $date,
                'domains' => array_keys($domains),
                'jurisdictions' => $domains['world']['jurisdictions'] ?? null,
                'verified_total' => $domains['reach']['verifiedTotal'] ?? null,
            ],
            ref: 'CI-6',
        );

        return ['as_of_date' => $date, 'domains' => $domains];
    }

    /**
     * CI-6 as a reusable predicate: this row's jurisdiction is one we own.
     *
     * A `whereExists` rather than an id list — a 955k-place world cannot be
     * bound into a query, and the correlated lookup is an index hit.
     */
    private function ours(string $table, string $column = 'jurisdiction_id'): callable
    {
        return function (Builder $q) use ($table, $column) {
            $q->select(DB::raw('1'))
                ->from('jurisdictions as authj')
                ->whereColumn('authj.id', "{$table}.{$column}")
                ->whereNull('authj.deleted_at')
                ->whereNull('authj.authoritative_server_id');
        };
    }

    /**
     * The world card. THE ONE BIG PASS, keyset-chunked with progress.
     *
     * `jurisdictions` has NO `status` column — civic-active is the
     * `is_civic_active` boolean (a verified convention exception).
     *
     * @param  callable(string,int):void|null  $onProgress
     * @return array<string,mixed>
     */
    private function world(?callable $onProgress = null): array
    {
        $byAdm = [];
        $modeled = 0;
        $civicActive = 0;
        $total = 0;
        $after = self::NIL_UUID;

        while (true) {
            $rows = DB::table('jurisdictions')
                ->whereNull('deleted_at')
                ->whereNull('authoritative_server_id') // CI-6
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'adm_level', 'population', 'is_civic_active']);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $after = $row->id;
                $total++;
                $level = (int) $row->adm_level;
                $byAdm[$level] = ($byAdm[$level] ?? 0) + 1;
                $modeled += (int) ($row->population ?? 0);

                if ($row->is_civic_active) {
                    $civicActive++;
                }
            }

            if ($onProgress !== null) {
                $onProgress('world', $total);
            }

            if ($rows->count() < self::CHUNK) {
                break;
            }
        }

        ksort($byAdm);

        // Earth = the root we are authoritative for. Its own stored population
        // is the modelled planet figure and is NOT the sum of its children
        // (geodata noise — parent never equals the sum, a settled fact).
        $earth = DB::table('jurisdictions')
            ->whereNull('deleted_at')
            ->whereNull('authoritative_server_id')
            ->orderBy('adm_level')
            ->value('population');

        return [
            'jurisdictions' => $total,
            'byAdmLevel' => $byAdm,
            'earthPopulation' => $earth !== null ? (int) $earth : null,
            'modeledPopulation' => $modeled,
            'civicActive' => $civicActive,
            // No opt-in store exists yet, so this is ABSENT rather than 0 —
            // "nobody has opted in" and "there is nowhere to opt in" differ.
            'mapOptIns' => null,
        ];
    }

    /**
     * Reach summed to the planet — the spine. Reads the latest night of
     * `legitimacy_snapshots`, which is ALREADY suppression-safe: the write side
     * nulled every sub-k count, and a CHECK constraint guarantees it.
     *
     * `legitimacy_snapshots` carries no `deleted_at` and no `status` (it is
     * append-only; the status-like column is named `state`).
     *
     * @return array<string,mixed>
     */
    private function reach(): array
    {
        $latest = DB::table('legitimacy_snapshots')->max('as_of_date');

        if ($latest === null) {
            // Never measured. Absent, not zero.
            return ['verifiedTotal' => null, 'measuredPlaces' => null, 'placesGauged' => null];
        }

        $rows = DB::table('legitimacy_snapshots')
            ->where('as_of_date', $latest)
            ->whereNull('source_server_id') // CI-6: written here
            ->selectRaw('
                count(*) AS gauged,
                count(*) FILTER (WHERE state = ?) AS measured,
                sum(verified_residents) FILTER (WHERE NOT suppressed) AS verified
            ', [LegitimacyService::STATE_MEASURED])
            ->first();

        return [
            // Published rows only. A suppressed place is gauged but contributes
            // no number — summing it would republish what the floor withheld.
            'verifiedTotal' => $rows->verified !== null ? (int) $rows->verified : null,
            'measuredPlaces' => (int) $rows->measured,
            'placesGauged' => (int) $rows->gauged,
            'asOfDate' => (string) $latest,
        ];
    }

    /**
     * Representation. ⚑ `legislature_members` holds BOTH `elected` and
     * `term_ended` rows — a naive count over-reports current seats ~2.5× on the
     * live box. Filled seats are `status = 'elected'` and not vacated.
     *
     * @return array<string,mixed>
     */
    private function representation(): array
    {
        $seats = DB::table('legislatures')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereExists($this->ours('legislatures'))
            ->selectRaw('count(*) AS chambers, sum(total_seats) AS seats')
            ->first();

        $filled = DB::table('legislature_members')
            ->whereNull('deleted_at')
            ->where('status', 'elected')
            ->whereNull('vacated_at')
            ->whereExists(function (Builder $q) {
                $q->select(DB::raw('1'))
                    ->from('legislatures as l')
                    ->join('jurisdictions as authj', 'authj.id', '=', 'l.jurisdiction_id')
                    ->whereColumn('l.id', 'legislature_members.legislature_id')
                    ->whereNull('l.deleted_at')
                    ->whereNull('authj.deleted_at')
                    ->whereNull('authj.authoritative_server_id');
            })
            ->count();

        $chamberSeats = $seats->seats !== null ? (int) $seats->seats : null;

        $elections = DB::table('elections')
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['certified', 'cancelled'])
            ->whereExists($this->ours('elections'))
            ->count();

        $candidacyIsOpen = DB::table('candidacies')
            ->whereNull('deleted_at')
            ->whereNull('withdrawn_at')
            ->count();

        return [
            'legislatures' => (int) $seats->chambers,
            'seats' => $chamberSeats,
            'seatsFilled' => $filled,
            'seatsOpen' => $chamberSeats === null ? null : max(0, $chamberSeats - $filled),
            'electionsOpen' => $elections,
            // Seats up for election: the seats of the races in open elections.
            'seatsUp' => $this->seatsUp(),
            'candidates' => $candidacyIsOpen,
            'petitionsGathering' => DB::table('petitions')
                ->whereNull('deleted_at')
                ->whereIn('status', ['collecting', 'gathering', 'open'])
                ->whereExists($this->ours('petitions'))
                ->count(),
            'committees' => DB::table('committees')->whereNull('deleted_at')->count(),
            'bills' => DB::table('bills')
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['enacted', 'failed'])
                ->count(),
        ];
    }

    private function seatsUp(): ?int
    {
        if (! Schema::hasTable('election_races')) {
            return null;
        }

        $sum = DB::table('election_races as r')
            ->join('elections as e', 'e.id', '=', 'r.election_id')
            ->whereNull('e.deleted_at')
            ->whereNotIn('e.status', ['certified', 'cancelled'])
            ->sum('r.seats');

        return (int) $sum;
    }

    /**
     * The executive. Governor and worker-elected seats live in `board_seats`
     * (`seat_class` = governor | owner_elected | worker_elected), not on the
     * board — there is no `boards_of_governors` table; the BoG is `boards`,
     * polymorphic via boardable_type/boardable_id.
     *
     * @return array<string,mixed>
     */
    private function executive(): array
    {
        $seats = DB::table('board_seats')
            ->whereNull('deleted_at')
            ->selectRaw("
                count(*) FILTER (WHERE seat_class = 'governor') AS governor,
                count(*) FILTER (WHERE seat_class = 'worker_elected') AS worker
            ")
            ->first();

        $emergency = DB::table('emergency_powers')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereExists($this->ours('emergency_powers'))
            ->selectRaw('count(*) AS active, max(expires_at) AS last_expiry')
            ->first();

        $daysLeft = null;

        if ($emergency->last_expiry !== null) {
            $daysLeft = max(0, (int) ceil((strtotime((string) $emergency->last_expiry) - time()) / 86400));
        }

        return [
            'departments' => DB::table('departments')
                ->whereNull('deleted_at')
                ->whereExists($this->ours('departments'))
                ->count(),
            'governorSeats' => (int) $seats->governor,
            'workerSeats' => (int) $seats->worker,
            'civilServiceWorkers' => (int) DB::table('departments')
                ->whereNull('deleted_at')
                ->sum('worker_count'),
            'emergencyPowersActive' => (int) $emergency->active,
            'emergencyDaysLeft' => $daysLeft,
        ];
    }

    /**
     * The judiciary. `cases.kind` is civil | constitutional | criminal.
     * `remedy_recommendations` has NO `status` column (a verified convention
     * exception) — a live remedy window is one whose due date has not passed.
     *
     * @return array<string,mixed>
     */
    private function judiciary(): array
    {
        return [
            'courts' => DB::table('judiciaries')
                ->whereNull('deleted_at')
                ->whereExists($this->ours('judiciaries'))
                ->count(),
            'casesOpen' => DB::table('cases')
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['closed', 'dismissed'])
                ->whereExists($this->ours('cases'))
                ->count(),
            'constitutionalChallenges' => DB::table('cases')
                ->whereNull('deleted_at')
                ->where('kind', 'constitutional')
                ->whereNotIn('status', ['closed', 'dismissed'])
                ->whereExists($this->ours('cases'))
                ->count(),
            'juriesSeated' => DB::table('juries')
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['discharged', 'dismissed'])
                ->count(),
            'remedyWindows' => DB::table('remedy_recommendations')
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('remedy_due_at')->orWhere('remedy_due_at', '>', now()))
                ->count(),
        ];
    }

    /**
     * Organizations. The type column is `type` (NOT `organization_type`), and
     * `endorsements` carries no `deleted_at` and no `status` — liveness is
     * `is_active` + `withdrawn_at`, and only PUBLIC endorsements are counted
     * (a private endorsement is nobody's business but the endorser's).
     *
     * @return array<string,mixed>
     */
    private function organizations(): array
    {
        $byType = DB::table('organizations')
            ->whereNull('deleted_at')
            ->selectRaw("
                count(*) AS total,
                count(*) FILTER (WHERE type = 'political_party') AS parties,
                count(*) FILTER (WHERE type = 'business') AS businesses,
                count(*) FILTER (WHERE type = 'nonprofit') AS nonprofits,
                count(*) FILTER (WHERE type = 'common_good_corp') AS cgcs,
                sum(worker_count) AS workers,
                count(*) FILTER (WHERE ip_is_public_domain) AS public_domain
            ")
            ->first();

        return [
            'total' => (int) $byType->total,
            'politicalParties' => (int) $byType->parties,
            'businesses' => (int) $byType->businesses,
            'nonprofits' => (int) $byType->nonprofits,
            'commonGoodCorps' => (int) $byType->cgcs,
            'endorsements' => DB::table('endorsements')
                ->where('is_active', true)
                ->where('is_public', true)
                ->whereNull('withdrawn_at')
                ->count(),
            'workersRepresented' => $byType->workers !== null ? (int) $byType->workers : null,
            'publicDomainWorks' => (int) $byType->public_domain,
        ];
    }

    /**
     * The mesh. Volunteer-run: keeping the world online buys no vote and no
     * seat. `federation_transports` has `deleted_at` but NO `status` — liveness
     * is the `enabled` boolean, and `is_self` excludes this node.
     *
     * Never a key, never a node's internals.
     *
     * @return array<string,mixed>
     */
    private function mesh(): array
    {
        if (! Schema::hasTable('federation_peers')) {
            return [];
        }

        $peers = DB::table('federation_peers')
            ->whereNull('deleted_at')
            ->selectRaw("
                count(*) AS nodes,
                count(*) FILTER (WHERE status NOT IN ('departed', 'merged')) AS alive,
                count(*) FILTER (WHERE status = 'trust_established') AS trusted,
                count(*) FILTER (WHERE last_synced_seq IS NOT NULL AND last_synced_seq >= peer_head_seq) AS caught_up,
                max(last_heartbeat_at) AS last_beat
            ")
            ->first();

        $nodes = (int) $peers->nodes;

        // A world with no peers yet is not a world of zero nodes — Phase 6 has
        // simply not been exercised. Absent, so the Atlas shows gaps.
        if ($nodes === 0) {
            return [];
        }

        return [
            'nodes' => $nodes,
            'alive' => (int) $peers->alive,
            'connectedPeers' => (int) $peers->trusted,
            'onLatest' => null,   // needs the version-compare pass; absent, not 0
            'onLatestOf' => $nodes,
            'transportsUp' => DB::table('federation_transports')
                ->whereNull('deleted_at')
                ->where('enabled', true)
                ->where('is_self', false)
                ->count(),
            'caughtUp' => (int) $peers->caught_up === $nodes ? 'all' : (string) (int) $peers->caught_up,
            'health' => null,
            'lastSync' => $peers->last_beat !== null ? (string) $peers->last_beat : null,
        ];
    }
}

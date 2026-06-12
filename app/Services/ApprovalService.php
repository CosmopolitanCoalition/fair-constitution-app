<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Approval;
use App\Models\ApprovalStanding;
use App\Models\Candidacy;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WI-B3 — revocable approval voting during the CLK-18 approval phase
 * (ESM-04, WF-CIV-08). Approvals are engine actions, DELIBERATELY NOT
 * FORMS (design §C).
 *
 * SECRECY CONTRACT — the deliberate, documented audit exception (pinned by
 * ApprovalSecrecyTest, WI-B10):
 *
 *   Individual approvals are constitutionally SECRET. cast() and revoke()
 *   produce ZERO per-approval audit entries — an audit row linking
 *   user → candidacy would violate the secrecy that the rest of the system
 *   guarantees structurally. The AUDITED event is the standings rollup
 *   (one entry per race per day, counts only, never identities).
 *
 * ARCHITECTURE NOTE — Earth-scale rule (design §A B-6): public standings
 * are read EXCLUSIVELY from `approval_standings`, the daily aggregate
 * written by ApprovalStandingsRollupJob (+ the frozen cutoff snapshot).
 * No controller or page may COUNT(*) the `approvals` table per request —
 * standings() below is the only read surface, and it never touches the
 * `approvals` table. The `Approval` model's owner global scope enforces
 * row secrecy; this service is one of the two legitimate cross-user
 * readers (the rollup is the other) and opts out explicitly, releasing
 * aggregates only.
 */
class ApprovalService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    // =========================================================================
    // Cast / revoke (owner-scoped, never audited — see secrecy contract)
    // =========================================================================

    /**
     * Cast a revocable approval for a candidacy. Open exactly while the
     * election's approval phase is open (CLK-18); idempotent — an existing
     * active approval is returned unchanged.
     */
    public function cast(User $user, Candidacy $candidacy): Approval
    {
        $this->assertApprovalPhaseOpen($candidacy);

        // Fresh read — a stale in-memory status must never widen the window.
        $candidacyStatus = Candidacy::query()->whereKey($candidacy->id)->value('status');

        if (! in_array($candidacyStatus, [
            Candidacy::STATUS_REGISTERED,
            Candidacy::STATUS_VALIDATED,
            Candidacy::STATUS_IN_POOL,
            Candidacy::STATUS_FINALIST,
        ], true)) {
            throw new ConstitutionalViolation(
                'Approvals can only be cast for standing candidacies.',
                'Art. II §2 · CGA open-ballot spec'
            );
        }

        return DB::transaction(function () use ($user, $candidacy) {
            $existing = Approval::withoutGlobalScope(Approval::SCOPE_OWNER)
                ->where('candidacy_id', $candidacy->id)
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            // ZERO audit entries here — see the secrecy contract above.
            return Approval::create([
                'election_id'  => $candidacy->election_id,
                'candidacy_id' => $candidacy->id,
                'user_id'      => $user->id,
                'created_at'   => now(),
            ]);
        });
    }

    /**
     * Revoke an active approval (append + revoke model — the row keeps its
     * history; a fresh cast() creates a new row). Only while the approval
     * phase is open; after the finalist cutoff the standings are frozen.
     */
    public function revoke(User $user, Candidacy $candidacy): bool
    {
        $this->assertApprovalPhaseOpen($candidacy);

        return DB::transaction(function () use ($user, $candidacy) {
            $active = Approval::withoutGlobalScope(Approval::SCOPE_OWNER)
                ->where('candidacy_id', $candidacy->id)
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($active === null) {
                return false;
            }

            // ZERO audit entries here — see the secrecy contract above.
            $active->forceFill(['revoked_at' => now()])->save();

            return true;
        });
    }

    /**
     * The authenticated citizen's own active approvals for an election
     * (the "your active approvals" panel — owner data only).
     *
     * @return Collection<int, Approval>
     */
    public function activeApprovalsFor(User $user, Election $election): Collection
    {
        return Approval::withoutGlobalScope(Approval::SCOPE_OWNER)
            ->where('user_id', $user->id)
            ->where('election_id', $election->id)
            ->whereNull('revoked_at')
            ->get();
    }

    // =========================================================================
    // Standings (the ONLY public read surface — aggregates, never rows)
    // =========================================================================

    /**
     * Public standings for a race: the most recent `approval_standings`
     * day (or the frozen cutoff snapshot once it exists). NEVER a live
     * count — see the architecture note in the class docblock.
     *
     * @return Collection<int, ApprovalStanding>
     */
    public function standings(ElectionRace $race): Collection
    {
        $frozenDate = ApprovalStanding::query()
            ->where('race_id', $race->id)
            ->frozen()
            ->max('as_of_date');

        $date = $frozenDate ?? ApprovalStanding::query()
            ->where('race_id', $race->id)
            ->max('as_of_date');

        if ($date === null) {
            return collect();
        }

        return ApprovalStanding::query()
            ->where('race_id', $race->id)
            ->whereDate('as_of_date', $date)
            ->orderBy('rank')
            ->get();
    }

    /**
     * Recompute the standings aggregate for one race — called by the daily
     * ApprovalStandingsRollupJob and, with $freeze = true, by the finalist
     * cutoff (the frozen snapshot archived to the chain). One audit entry
     * per race per rollup (module 'elections', event 'standings.rolled',
     * counts hash only) — never per approval, never per request.
     *
     * Ranking: approvals desc, then validated_at asc (registration
     * seniority), then candidacy id — the same deterministic order the
     * cutoff uses.
     *
     * @return list<array{candidacy_id: string, approvals_count: int, rank: int, delta: int}>
     */
    public function rollupRace(ElectionRace $race, bool $freeze = false, ?CarbonInterface $asOf = null): array
    {
        $asOfDate = Carbon::instance($asOf ?? now())->toDateString();

        // Cross-user aggregation — the legitimate, explicit scope opt-out.
        $counts = Approval::withoutGlobalScope(Approval::SCOPE_OWNER)
            ->join('candidacies', 'candidacies.id', '=', 'approvals.candidacy_id')
            ->where('candidacies.race_id', $race->id)
            ->whereNull('approvals.revoked_at')
            ->groupBy('approvals.candidacy_id')
            ->selectRaw('approvals.candidacy_id, COUNT(*) AS n')
            ->pluck('n', 'candidacy_id');

        $candidacies = Candidacy::query()
            ->where('race_id', $race->id)
            ->whereIn('status', [
                Candidacy::STATUS_VALIDATED,
                Candidacy::STATUS_IN_POOL,
                Candidacy::STATUS_FINALIST,
                Candidacy::STATUS_NON_FINALIST,
            ])
            ->get();

        $ranked = $candidacies->sort(function (Candidacy $a, Candidacy $b) use ($counts) {
            $byCount = ((int) ($counts[$b->id] ?? 0)) <=> ((int) ($counts[$a->id] ?? 0));
            if ($byCount !== 0) {
                return $byCount;
            }
            $bySeniority = ($a->validated_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->validated_at?->getTimestamp() ?? PHP_INT_MAX);

            return $bySeniority !== 0 ? $bySeniority : strcmp($a->id, $b->id);
        })->values();

        $priorCounts = ApprovalStanding::query()
            ->where('race_id', $race->id)
            ->where('as_of_date', '<', $asOfDate)
            ->orderByDesc('as_of_date')
            ->get()
            ->unique('candidacy_id')
            ->pluck('approvals_count', 'candidacy_id');

        $rows   = [];
        $result = [];

        foreach ($ranked as $i => $candidacy) {
            $count = (int) ($counts[$candidacy->id] ?? 0);
            $rank  = $i + 1;
            $delta = $count - (int) ($priorCounts[$candidacy->id] ?? 0);

            $rows[] = [
                'id'              => (string) \Illuminate\Support\Str::uuid(),
                'race_id'         => $race->id,
                'candidacy_id'    => $candidacy->id,
                'as_of_date'      => $asOfDate,
                'approvals_count' => $count,
                'rank'            => $rank,
                'delta'           => $delta,
                'is_frozen'       => $freeze,
                'created_at'      => now(),
            ];

            $result[] = [
                'candidacy_id'    => $candidacy->id,
                'approvals_count' => $count,
                'rank'            => $rank,
                'delta'           => $delta,
            ];
        }

        DB::transaction(function () use ($rows, $race, $asOfDate, $freeze, $result) {
            if ($rows !== []) {
                ApprovalStanding::query()->upsert(
                    $rows,
                    ['candidacy_id', 'as_of_date'],
                    ['approvals_count', 'rank', 'delta', 'is_frozen', 'race_id'],
                );
            }

            // The audited event (counts only — identities never leave).
            $this->audit->append(
                module: 'elections',
                event: 'standings.rolled',
                payload: [
                    'race_id'     => $race->id,
                    'election_id' => $race->election_id,
                    'as_of_date'  => $asOfDate,
                    'frozen'      => $freeze,
                    'candidates'  => count($result),
                    'counts_hash' => hash('sha256', AuditService::canonicalJson(
                        array_map(fn ($r) => [$r['candidacy_id'], $r['approvals_count']], $result)
                    )),
                ],
                ref: 'CLK-18',
                jurisdictionId: $race->jurisdiction_id,
            );
        });

        return $result;
    }

    // =========================================================================

    private function assertApprovalPhaseOpen(Candidacy $candidacy): void
    {
        // Always a fresh DB read — never a (possibly stale) loaded
        // relation: once the cutoff freezes the standings, no in-memory
        // copy of the election may keep the approval window open.
        $status = Election::query()->whereKey($candidacy->election_id)->value('status');

        if ($status !== Election::STATUS_APPROVAL_OPEN) {
            throw new ConstitutionalViolation(
                'Approvals can only be cast or revoked while the approval phase is open (CLK-18).',
                'Art. II §2 · CGA open-ballot spec'
            );
        }
    }
}

<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ClockTimer;
use App\Models\EmergencyPower;
use App\Models\EmergencyPowerRenewal;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * C-E1 (PHASE_C_DESIGN_votes_laws §F) — emergency powers (ESM-12,
 * Art. II §7).
 *
 * ALL validation runs PRE-VOTE (the rejection rows are the
 * operator-visible record, exactly like F-LEG-031):
 *  - cause ∈ the CLOSED enum (natural_disaster | actual_invasion) —
 *    economic / political / public-order rationales rejected with
 *    citation;
 *  - duration 1..min(90, resolved emergency_powers_max_days);
 *  - area = the legislature's jurisdiction or a descendant (≤ its
 *    authority);
 *  - methods non-empty ("within constitutional order").
 *
 * The power row is created ON ADOPTION of the emergency_invoke
 * supermajority vote; CLK-03 arms at expires_at with payload.derive NULL —
 * deliberately non-re-derivable: an active power keeps its DECLARED
 * duration; a lowered max binds only new declarations/renewals (flagged
 * decision, ClockRederivationService pins the exclusion).
 *
 * Renewals (F-LEG-025): fresh supermajority, each extension ≤ the fresh
 * ceiling, extending from CURRENT expiry, filed only inside the renewal
 * window (the final config('cga.emergency_renewal_window_days', 14) days
 * — 'as implemented', matching the mockup's "window opens day 76" of 90).
 * "Nothing rolls over silently" — there is NO auto-renewal path anywhere.
 *
 * Civic-process protection is ENGINE-level, not here: see
 * ConstitutionalValidator::EMERGENCY_PROTECTED_FORMS (Art. II §7 shield)
 * — no handler for a protected form may read emergency state, and no
 * undeclared form may cite a power as enabling authority.
 */
class EmergencyPowerService
{
    /** Jurisdiction-chain walk bound (matches BillService). */
    private const MAX_CHAIN_DEPTH = 32;

    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingsResolver $settings,
        private readonly PublicRecordService $records,
        private readonly ChamberVoteService $votes,
        private readonly ClockService $clocks,
    ) {
    }

    // =========================================================================
    // PURE Art. II §7 guards — pinned by EmergencyCeilingTest
    // =========================================================================

    /** The closed cause enum — anything else is rejected pre-vote. */
    public static function assertCause(string $cause): void
    {
        if (! in_array($cause, EmergencyPower::CAUSES, true)) {
            throw new ConstitutionalViolation(
                sprintf(
                    'Emergency powers exist for natural disaster or actual invasion only — cause %s is '
                    . 'rejected pre-vote (economic, political, or public-order rationales are not causes).',
                    json_encode($cause)
                ),
                'Art. II §7'
            );
        }
    }

    /**
     * 1..min(90, resolved max) — the hardened 90-day ceiling binds every
     * declaration AND every renewal extension alike.
     */
    public static function assertDuration(int $days, int $resolvedMaxDays, string $what = 'duration'): void
    {
        $ceiling = min(EmergencyPower::HARD_MAX_DAYS, max(1, $resolvedMaxDays));

        if ($days < 1 || $days > $ceiling) {
            throw new ConstitutionalViolation(
                sprintf(
                    'Rejected pre-vote: %s of %d day(s) exceeds the %d-day constitutional ceiling '
                    . '(resolved emergency_powers_max_days = %d; hardened maximum 90 · CLK-03).',
                    $what,
                    $days,
                    $ceiling,
                    $resolvedMaxDays
                ),
                'Art. II §7'
            );
        }
    }

    /** When the renewal window opens (the final N days before expiry). */
    public static function renewalWindowOpensAt(CarbonInterface $expiresAt, int $windowDays): CarbonInterface
    {
        return $expiresAt->copy()->subDays(max(1, $windowDays));
    }

    // =========================================================================
    // F-LEG-024 — declaration
    // =========================================================================

    /**
     * Pre-vote validation + open the emergency_invoke supermajority vote.
     * The power row is created only on adoption.
     *
     * @param  array{cause: string, label: string, duration_days: int|string,
     *               area_jurisdiction_id?: ?string, methods: string}  $payload
     * @return array{proposal_id: string, vote_id: string}
     */
    public function proposeInvocation(Legislature $legislature, LegislatureMember $proposer, array $payload): array
    {
        $cause = (string) ($payload['cause'] ?? '');
        self::assertCause($cause);

        $days = (int) ($payload['duration_days'] ?? 0);
        self::assertDuration($days, $this->resolvedMaxDays((string) $legislature->jurisdiction_id), 'declared duration');

        $label = trim((string) ($payload['label'] ?? ''));

        if ($label === '') {
            throw new ConstitutionalViolation(
                'An emergency declaration names its emergency.',
                'Art. II §7 · as implemented'
            );
        }

        $methods = trim((string) ($payload['methods'] ?? ''));

        if ($methods === '') {
            throw new ConstitutionalViolation(
                'An emergency declaration states its methods — "within constitutional order" is published, not implied.',
                'Art. II §7'
            );
        }

        $areaId = (string) ($payload['area_jurisdiction_id'] ?? $legislature->jurisdiction_id);

        if (! $this->inSubtree($areaId, (string) $legislature->jurisdiction_id)) {
            throw new ConstitutionalViolation(
                'The declared area must be this legislature\'s jurisdiction or a descendant — '
                . 'never beyond its authority.',
                'Art. II §7'
            );
        }

        $proposal = ChamberVoteProposal::create([
            'legislature_id'        => $legislature->id,
            'proposal_kind'         => ChamberVoteProposal::KIND_EMERGENCY_INVOCATION,
            'payload'               => [
                'cause'                => $cause,
                'label'                => $label,
                'duration_days'        => $days,
                'area_jurisdiction_id' => $areaId,
                'methods'              => $methods,
            ],
            'proposed_by_member_id' => $proposer->id,
            'status'                => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: 'emergency_invoke',
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return ['proposal_id' => (string) $proposal->id, 'vote_id' => (string) $vote->id];
    }

    /** Adoption side-effect: the power activates + CLK-03 arms (same txn). */
    public function activateFromProposal(ChamberVoteProposal $proposal, ChamberVote $vote): EmergencyPower
    {
        $legislature = $proposal->legislature()->firstOrFail();
        $payload     = (array) $proposal->payload;

        $startsAt  = now();
        $expiresAt = $startsAt->copy()->addDays((int) $payload['duration_days']);

        $power = EmergencyPower::create([
            'legislature_id'         => (string) $legislature->id,
            'jurisdiction_id'        => (string) $legislature->jurisdiction_id,
            'cause'                  => (string) $payload['cause'],
            'label'                  => (string) $payload['label'],
            'declared_duration_days' => (int) $payload['duration_days'],
            'area_jurisdiction_id'   => (string) $payload['area_jurisdiction_id'],
            'methods'                => (string) $payload['methods'],
            'invoke_vote_id'         => (string) $vote->id,
            'status'                 => EmergencyPower::STATUS_ACTIVE,
            'starts_at'              => $startsAt,
            'expires_at'             => $expiresAt,
        ]);

        // CLK-03 — deliberately NON-re-derivable (derive null): the
        // declaration fixed the duration at vote time (Art. II §7).
        $this->clocks->arm(
            'CLK-03',
            (string) $legislature->jurisdiction_id,
            'emergency_power',
            (string) $power->id,
            $expiresAt,
            ['derive' => null, 'declared_duration_days' => (int) $payload['duration_days']],
        );

        $this->records->publish(
            kind: 'act',
            title: "Emergency powers invoked — {$power->label}",
            body: sprintf(
                "Cause: %s. Duration: %d day(s) — auto-expires %s; nothing rolls over silently (CLK-03). "
                . "Area: within this legislature's authority. Methods: %s\n\n"
                . 'Elections, sessions, courts, residency, petitions, and records cannot be disrupted — '
                . 'enforced in code (Art. II §7).',
                $power->cause,
                $power->declared_duration_days,
                $expiresAt->toDateString(),
                $power->methods
            ),
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-024',
                'subject_type'    => 'emergency_power',
                'subject_id'      => (string) $power->id,
            ],
        );

        return $power;
    }

    // =========================================================================
    // F-LEG-025 — renewal
    // =========================================================================

    /**
     * Pre-vote validation + open the fresh emergency_renew supermajority.
     *
     * @return array{proposal_id: string, vote_id: string}
     */
    public function proposeRenewal(EmergencyPower $power, LegislatureMember $proposer, int $extensionDays): array
    {
        if (! in_array($power->status, EmergencyPower::LIVE_STATUSES, true)) {
            throw new ConstitutionalViolation(
                "An expired or struck power cannot be renewed (status: {$power->status}) — "
                . 'a new emergency requires a new declaration.',
                'Art. II §7'
            );
        }

        // Fresh ceiling on every renewal — resolved at filing time.
        self::assertDuration(
            $extensionDays,
            $this->resolvedMaxDays((string) $power->jurisdiction_id),
            'renewal extension'
        );

        $windowDays = (int) config('cga.emergency_renewal_window_days', 14);
        $opensAt    = self::renewalWindowOpensAt($power->expires_at, $windowDays);

        if (now()->lt($opensAt)) {
            throw new ConstitutionalViolation(
                sprintf(
                    'The renewal window opens %s (the final %d days before expiry) — a renewal vote this '
                    . 'early would pre-commit a future chamber.',
                    $opensAt->toDateString(),
                    $windowDays
                ),
                'Art. II §7 · as implemented'
            );
        }

        $legislature = $power->legislature()->firstOrFail();

        $proposal = ChamberVoteProposal::create([
            'legislature_id'        => (string) $legislature->id,
            'proposal_kind'         => ChamberVoteProposal::KIND_EMERGENCY_RENEWAL,
            'payload'               => [
                'emergency_power_id' => (string) $power->id,
                'extension_days'     => $extensionDays,
            ],
            'proposed_by_member_id' => $proposer->id,
            'status'                => ChamberVoteProposal::STATUS_OPEN,
        ]);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: 'emergency_renew',
            votable: $proposal,
            stage: ChamberVote::STAGE_FLOOR,
            opener: $proposer,
        );

        $proposal->forceFill(['vote_id' => (string) $vote->id])->save();

        return ['proposal_id' => (string) $proposal->id, 'vote_id' => (string) $vote->id];
    }

    /** Adoption side-effect: extend from CURRENT expiry, re-arm CLK-03. */
    public function renewFromProposal(ChamberVoteProposal $proposal, ChamberVote $vote): EmergencyPowerRenewal
    {
        $payload = (array) $proposal->payload;

        $power = EmergencyPower::query()
            ->whereKey((string) $payload['emergency_power_id'])
            ->lockForUpdate()
            ->firstOrFail();

        if (! in_array($power->status, EmergencyPower::LIVE_STATUSES, true)) {
            throw new ConstitutionalViolation(
                "The power expired before the renewal vote closed (status: {$power->status}) — "
                . 'nothing rolls over silently; a new declaration is required.',
                'Art. II §7'
            );
        }

        $extension = (int) $payload['extension_days'];

        // The ceiling binds at adoption too (TOCTOU posture, like settings).
        self::assertDuration($extension, $this->resolvedMaxDays((string) $power->jurisdiction_id), 'renewal extension');

        $previous = $power->expires_at;
        $new      = $previous->copy()->addDays($extension);

        $renewal = EmergencyPowerRenewal::create([
            'emergency_power_id'  => (string) $power->id,
            'vote_id'             => (string) $vote->id,
            'extension_days'      => $extension,
            'previous_expires_at' => $previous,
            'new_expires_at'      => $new,
            'created_at'          => now(),
        ]);

        $power->forceFill([
            'expires_at' => $new,
            'status'     => EmergencyPower::STATUS_RENEWED,
        ])->save();

        // Cancel + re-arm CLK-03 (never move an armed timer).
        foreach ($this->armedTimers($power) as $timer) {
            $this->clocks->cancel($timer, 'renewed by fresh supermajority — CLK-03 re-armed at the new expiry');
        }

        $this->clocks->arm(
            'CLK-03',
            (string) $power->jurisdiction_id,
            'emergency_power',
            (string) $power->id,
            $new,
            ['derive' => null, 'extension_days' => $extension],
        );

        $this->records->publish(
            kind: 'act',
            title: "Emergency powers renewed — {$power->label}",
            body: sprintf(
                'Fresh supermajority extends the power by %d day(s): %s → %s. Each renewal carries its own '
                . '≤ %d-day ceiling; nothing rolls over silently (Art. II §7 · CLK-03).',
                $extension,
                $previous->toDateString(),
                $new->toDateString(),
                EmergencyPower::HARD_MAX_DAYS
            ),
            attrs: [
                'jurisdiction_id' => (string) $power->jurisdiction_id,
                'legislature_id'  => (string) $power->legislature_id,
                'via_form'        => 'F-LEG-025',
                'subject_type'    => 'emergency_power',
                'subject_id'      => (string) $power->id,
            ],
        );

        return $renewal;
    }

    // =========================================================================
    // CLK-03 — auto-expiry (ExpireEmergencyPowerJob)
    // =========================================================================

    public function expire(EmergencyPower $power): bool
    {
        $fresh = EmergencyPower::query()->whereKey($power->id)->lockForUpdate()->firstOrFail();

        if (! in_array($fresh->status, EmergencyPower::LIVE_STATUSES, true)) {
            return false; // idempotent — already resolved
        }

        $fresh->forceFill(['status' => EmergencyPower::STATUS_EXPIRED])->save();

        $this->audit->append(
            module: 'legislature',
            event: 'emergency.expired',
            payload: [
                'emergency_power_id' => (string) $fresh->id,
                'label'              => $fresh->label,
                'declared_duration'  => (int) $fresh->declared_duration_days,
                'expired_at'         => now()->toIso8601String(),
            ],
            ref: 'CLK-03',
            jurisdictionId: (string) $fresh->jurisdiction_id,
        );

        $this->records->publish(
            kind: 'other',
            title: "Emergency powers expired — {$fresh->label}",
            body: 'The declared duration elapsed and the power expired automatically — no action was '
                . 'required and none was possible; nothing rolls over silently (Art. II §7 · CLK-03).',
            attrs: [
                'jurisdiction_id' => (string) $fresh->jurisdiction_id,
                'legislature_id'  => (string) $fresh->legislature_id,
                'via_clock'       => 'CLK-03',
                'subject_type'    => 'emergency_power',
                'subject_id'      => (string) $fresh->id,
            ],
        );

        // Phase D (PHASE_D_DESIGN_executive §A D-5): department rules
        // enabled by the dead power EXPIRE with it — the CLK-03 cascade.
        app(\App\Services\Executive\DepartmentService::class)
            ->expireRulesForEmergencyPower((string) $fresh->id);

        return true;
    }

    // =========================================================================
    // Agenda slot-1 feed (SessionService consumes — votes_laws §F)
    // =========================================================================

    /**
     * Live powers visible from a jurisdiction: area = the jurisdiction
     * itself, an ANCESTOR (downward visibility — the Dorinda pattern: the
     * county sees the state's power), or a DESCENDANT.
     *
     * @return list<object{id: string, label: string, legislature_id: string}>
     */
    public function activeInFootprint(string $jurisdictionId): array
    {
        return DB::select(
            'WITH RECURSIVE ups AS (
                SELECT j.id, j.parent_id FROM jurisdictions j WHERE j.id = ?
                UNION ALL
                SELECT p.id, p.parent_id FROM ups u JOIN jurisdictions p ON p.id = u.parent_id
            ),
            downs AS (
                SELECT j.id FROM jurisdictions j WHERE j.id = ?
                UNION ALL
                SELECT c.id FROM downs d JOIN jurisdictions c ON c.parent_id = d.id AND c.deleted_at IS NULL
            )
            SELECT ep.id, ep.label, ep.legislature_id
            FROM emergency_powers ep
            WHERE ep.deleted_at IS NULL
              AND ep.status IN (\'active\', \'renewed\', \'under_review\', \'narrowed\')
              AND (ep.area_jurisdiction_id IN (SELECT id FROM ups)
                   OR ep.area_jurisdiction_id IN (SELECT id FROM downs))
            ORDER BY ep.starts_at',
            [$jurisdictionId, $jurisdictionId]
        );
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function resolvedMaxDays(string $jurisdictionId): int
    {
        return $this->settings->resolveInt($jurisdictionId, 'emergency_powers_max_days', EmergencyPower::HARD_MAX_DAYS);
    }

    /** @return list<ClockTimer> */
    private function armedTimers(EmergencyPower $power): array
    {
        return ClockTimer::query()
            ->armed()
            ->where('clock_id', 'CLK-03')
            ->where('subject_type', 'emergency_power')
            ->where('subject_id', (string) $power->id)
            ->get()
            ->all();
    }

    /** Is $candidate inside (or equal to) $root's subtree? Bounded walk up parent_id. */
    private function inSubtree(string $candidate, string $root): bool
    {
        if ($candidate === $root) {
            return true;
        }

        $current = $candidate;

        for ($depth = 0; $depth < self::MAX_CHAIN_DEPTH; $depth++) {
            $parent = DB::table('jurisdictions')->where('id', $current)->whereNull('deleted_at')->value('parent_id');

            if ($parent === null) {
                return false;
            }

            if ((string) $parent === $root) {
                return true;
            }

            $current = (string) $parent;
        }

        return false;
    }
}

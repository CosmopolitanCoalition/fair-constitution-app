<?php

namespace App\Services\Jurisdictions;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\RestorationEvent;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Art. VI §2–3 — constitutional restoration. A restoration event is DECLARED on
 * one of the three conditions, but only CONFIRMED once a court has found the
 * condition by constitutional finding (no unilateral activation, Art. VI §2 "as
 * implemented" — tied to a Phase E case). The three-tier cascade then restores
 * order strictly in order: Tier 1 (constituents elect) → Tier 2 (encompassing
 * calls elections) → Tier 3 (individuals self-organize). A tier may only be
 * entered after the prior tier has been tried.
 */
class RestorationService
{
    public function __construct(private readonly AuditService $audit) {}

    /** Declare a restoration condition (judicial review pending). */
    public function declare(string $jurisdictionId, string $condition, array $evidence = [], ?string $reviewCaseId = null): RestorationEvent
    {
        if (! in_array($condition, [
            RestorationEvent::CONDITION_COUNTERMANDED,
            RestorationEvent::CONDITION_CAPTURED,
            RestorationEvent::CONDITION_DESTROYED,
        ], true)) {
            throw new ConstitutionalViolation(__('Unknown restoration condition [:condition].', ['condition' => $condition]), 'Art. VI §2');
        }

        return DB::transaction(function () use ($jurisdictionId, $condition, $evidence, $reviewCaseId) {
            $event = RestorationEvent::create([
                'jurisdiction_id' => $jurisdictionId,
                'condition' => $condition,
                'evidence' => $evidence,
                'review_case_id' => $reviewCaseId,
                'status' => RestorationEvent::STATUS_DECLARED,
            ]);

            $this->audit->append('jurisdictions', 'restoration.declared', [
                'restoration_event_id' => (string) $event->id,
                'jurisdiction_id' => $jurisdictionId,
                'condition' => $condition,
            ], 'WF-JUR-07', null, $jurisdictionId);

            return $event;
        });
    }

    /**
     * Confirm the condition — REQUIRES a judicial constitutional finding on the
     * tied case (Art. VI §2: no unilateral declaration). $judicialFinding is the
     * court's confirmation; without it, confirmation is refused.
     */
    public function confirm(RestorationEvent $event, bool $judicialFinding): RestorationEvent
    {
        if (! $judicialFinding || $event->review_case_id === null) {
            throw new ConstitutionalViolation(
                __('A restoration condition is activated only on a judicial constitutional finding — no Government may unilaterally declare Article VI active.'),
                'Art. VI §2'
            );
        }

        return DB::transaction(function () use ($event) {
            $event->forceFill([
                'judicially_confirmed' => true,
                'status' => RestorationEvent::STATUS_CONFIRMED,
            ])->save();

            $this->audit->append('jurisdictions', 'restoration.confirmed', [
                'restoration_event_id' => (string) $event->id,
                'jurisdiction_id' => (string) $event->jurisdiction_id,
                'review_case_id' => (string) $event->review_case_id,
            ], 'WF-JUR-07', null, (string) $event->jurisdiction_id);

            return $event->refresh();
        });
    }

    /**
     * Enter the next restoration tier. Tiers run in order: a tier may be entered
     * only after the prior one (Art. VI §3 cascade). Tier 1 reuses the election
     * bootstrap (constituents elect a new legislature).
     */
    public function advanceTier(RestorationEvent $event, int $tier, ?string $tierElectionId = null): RestorationEvent
    {
        if (! $event->judicially_confirmed) {
            throw new ConstitutionalViolation(__('Restoration tiers run only after judicial confirmation.'), 'Art. VI §3');
        }
        if (! in_array($tier, [1, 2, 3], true)) {
            throw new ConstitutionalViolation(__('Restoration tiers are 1, 2, or 3.'), 'Art. VI §3');
        }

        $current = (int) ($event->tier ?? 0);
        if ($tier !== $current + 1) {
            throw new ConstitutionalViolation(
                __('Restoration tier :tier cannot be entered from tier :current — the cascade runs in order (constituents → encompassing → individuals).', ['tier' => $tier, 'current' => $current]),
                'Art. VI §3'
            );
        }

        return DB::transaction(function () use ($event, $tier, $tierElectionId) {
            $event->forceFill([
                'tier' => $tier,
                'tier_election_id' => $tierElectionId,
                'status' => RestorationEvent::STATUS_RESTORING,
            ])->save();

            $this->audit->append('jurisdictions', 'restoration.tier_entered', [
                'restoration_event_id' => (string) $event->id,
                'jurisdiction_id' => (string) $event->jurisdiction_id,
                'tier' => $tier,
            ], 'WF-JUR-07', null, (string) $event->jurisdiction_id);

            return $event->refresh();
        });
    }

    /**
     * The cascade succeeded: a legitimate government stands again. Reachable
     * only after the third tier has been entered (individuals self-organized
     * from a dormant boundary — Art. VI §3), which the ordered advanceTier
     * cascade guarantees. Writes the terminal STATUS_RESTORED that nothing set
     * before (the degenerate tier-3 branch left every tier at RESTORING).
     */
    public function complete(RestorationEvent $event): RestorationEvent
    {
        $event = $event->refresh();

        if (! $event->judicially_confirmed) {
            throw new ConstitutionalViolation(__('Restoration completes only after judicial confirmation.'), 'Art. VI §3');
        }
        if ((int) ($event->tier ?? 0) !== 3) {
            throw new ConstitutionalViolation(
                __('Restoration completes only from the third tier — the cascade runs constituents → encompassing → individuals in order.'),
                'Art. VI §3'
            );
        }

        return DB::transaction(function () use ($event) {
            $event->forceFill(['status' => RestorationEvent::STATUS_RESTORED])->save();

            $this->audit->append('jurisdictions', 'restoration.restored', [
                'restoration_event_id' => (string) $event->id,
                'jurisdiction_id' => (string) $event->jurisdiction_id,
                'tier' => (int) $event->tier,
            ], 'WF-JUR-07', null, (string) $event->jurisdiction_id);

            return $event->refresh();
        });
    }

    /**
     * The cascade is abandoned before a government stands again (the condition
     * was resolved by other means, or the effort is stood down). Terminal, and
     * legal from any non-terminal state.
     */
    public function abandon(RestorationEvent $event): RestorationEvent
    {
        $event = $event->refresh();

        if (in_array($event->status, [RestorationEvent::STATUS_RESTORED, RestorationEvent::STATUS_ABANDONED], true)) {
            throw new ConstitutionalViolation(__('This restoration event has already reached a terminal state.'), 'Art. VI §3');
        }

        return DB::transaction(function () use ($event) {
            $event->forceFill(['status' => RestorationEvent::STATUS_ABANDONED])->save();

            $this->audit->append('jurisdictions', 'restoration.abandoned', [
                'restoration_event_id' => (string) $event->id,
                'jurisdiction_id' => (string) $event->jurisdiction_id,
            ], 'WF-JUR-07', null, (string) $event->jurisdiction_id);

            return $event->refresh();
        });
    }
}

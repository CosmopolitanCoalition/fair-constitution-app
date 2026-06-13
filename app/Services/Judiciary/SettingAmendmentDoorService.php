<?php

namespace App\Services\Judiciary;

use App\Models\Bill;
use App\Models\Law;
use App\Models\Legislature;
use App\Models\MultiJurisdictionVote;
use App\Services\AuditService;
use App\Services\ConstituentResolver;
use App\Services\EnactmentService;
use App\Services\MultiJurisdictionVoteService;
use App\Services\PublicRecordService;

/**
 * SettingAmendmentDoorService (PHASE_E_DESIGN_challenge_law §E) — the
 * amendments TWO-DOOR reconciliation. Door 1 (legislative supermajority) is the
 * existing F-LEG-031 path. This service owns Door 2a (constituent supermajority)
 * for DUAL_DOOR_KEYS settings (Art. IV §3 "a Supermajority of Constituent
 * Jurisdictions must ALSO consent"), reusing the SAME MultiJurisdictionVote
 * substrate Phase D built for executive conversion (zero new substrate).
 *
 * F-LEG-031 stays the single mutation entry point: both doors converge on
 * EnactmentService::applySettingChangeForKey. The doors differ only in the GATE
 * before the mutation — Door 2a inserts the constituent process between chamber
 * adoption and the setting mutation.
 *
 *   chamber supermajority adopts the dual-door setting bill
 *     → onDualDoorChamberAdoption:
 *         no constituents  → apply immediately (constituent door vacuous)
 *         constituents     → open the MJV setting_amendment process (HOLD)
 *     → resolveConstituentConsentVote dispatches the decided process here
 *         → onProcessEvaluated:
 *             passed → applySettingChangeForKey(route=constituent_supermajority)
 *             failed → the setting does NOT change (Art. IV §3)
 */
class SettingAmendmentDoorService
{
    public function __construct(
        private readonly MultiJurisdictionVoteService $processes,
        private readonly EnactmentService $enactments,
        private readonly PublicRecordService $records,
        private readonly AuditService $audit,
    ) {}

    /**
     * Chamber adoption of a DUAL_DOOR_KEYS setting bill (called from
     * EnactmentService::applySettingChange instead of the direct mutation).
     */
    public function onDualDoorChamberAdoption(Bill $bill, Law $law): void
    {
        $legislature = Legislature::query()->find((string) $bill->legislature_id);

        if ($legislature === null) {
            return;
        }

        $constituents = ConstituentResolver::ids($legislature);

        $key = (string) $bill->targets_setting_key;

        if ($constituents === []) {
            // No constituent legislatures: the constituent door is vacuously
            // satisfied (the "where constituents exist" reading, Art. IV §3) —
            // the chamber supermajority suffices.
            $this->enactments->applySettingChangeForKey(
                (string) $bill->jurisdiction_id,
                (string) $legislature->id,
                $key,
                $bill->proposed_value,
                $law,
                route: 'constituent_supermajority',
                processId: null,
                constituentConsented: true,
            );

            $this->records->publish(
                kind: 'act',
                title: sprintf('Dual-door setting amended — %s (no constituents to consent)', $key),
                body: sprintf(
                    'Act %s amended [%s]: no direct constituent jurisdiction holds a legislature able to vote; '
                    .'the chamber supermajority alone suffices (Art. IV §3 — where constituents exist).',
                    $law->act_number,
                    $key
                ),
                attrs: [
                    'jurisdiction_id' => (string) $bill->jurisdiction_id,
                    'legislature_id' => (string) $legislature->id,
                    'via_form' => 'F-LEG-031',
                    'subject_type' => 'constitutional_settings',
                    'subject_id' => (string) $law->id,
                ],
            );

            return;
        }

        // Constituents exist: open the constituent-consent process. The setting
        // does NOT mutate until it passes (Art. IV §3 "must ALSO consent").
        $process = $this->processes->open(
            'setting_amendment',
            $legislature,
            $constituents,
            MultiJurisdictionVote::BASIS_SUPERMAJORITY,
            null, // the chamber vote already closed (the bill enacted); provenance rides the law
            'constitutional_settings',
            (string) $law->id, // the law row carries the pending amendment; the bill is its source
        );

        $this->records->publish(
            kind: 'act',
            title: sprintf('Dual-door setting amendment — constituent consent requested (%s)', $key),
            body: sprintf(
                'Act %s proposes amending [%s]; a constituent dual-supermajority process opened across %d '
                .'constituent legislature(s). The setting changes only when they ALSO consent (Art. IV §3).',
                $law->act_number,
                $key,
                count($constituents)
            ),
            attrs: [
                'jurisdiction_id' => (string) $bill->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-LEG-031',
                'subject_type' => 'constitutional_settings',
                'subject_id' => (string) $law->id,
            ],
        );
    }

    /**
     * Process decided → subject effect (dispatched from
     * ExecutiveFormationService::resolveConstituentConsentVote for
     * subject_type 'constitutional_settings'). Passed ⇒ the setting mutates;
     * failed ⇒ it does not (the chamber supermajority alone is insufficient).
     */
    public function onProcessEvaluated(MultiJurisdictionVote $process): void
    {
        if ($process->subject_type !== 'constitutional_settings'
            || $process->kind !== 'setting_amendment'
            || $process->status === MultiJurisdictionVote::STATUS_OPEN) {
            return;
        }

        $law = Law::query()->find((string) $process->subject_id);

        if ($law === null) {
            return;
        }

        $bill = $law->enacting_bill_id !== null ? Bill::query()->find((string) $law->enacting_bill_id) : null;

        if ($bill === null || $bill->targets_setting_key === null) {
            return;
        }

        $key = (string) $bill->targets_setting_key;

        if ($process->status === MultiJurisdictionVote::STATUS_PASSED) {
            $this->enactments->applySettingChangeForKey(
                (string) $bill->jurisdiction_id,
                $bill->legislature_id !== null ? (string) $bill->legislature_id : null,
                $key,
                $bill->proposed_value,
                $law,
                route: 'constituent_supermajority',
                processId: (string) $process->id,
                constituentConsented: true,
            );

            $this->records->publish(
                kind: 'act',
                title: sprintf('Dual-door setting amended — %s (constituent supermajority)', $key),
                body: sprintf(
                    'Process %s passed (%d yes / %d no of %d; required %d). The constituent jurisdictions ALSO '
                    .'consented (Art. IV §3); [%s] is amended via Act %s.',
                    (string) $process->id,
                    (int) $process->yes_count,
                    (int) $process->no_count,
                    (int) $process->constituent_total,
                    (int) $process->required,
                    $key,
                    $law->act_number
                ),
                attrs: [
                    'jurisdiction_id' => (string) $bill->jurisdiction_id,
                    'legislature_id' => $bill->legislature_id !== null ? (string) $bill->legislature_id : null,
                    'via_form' => 'F-LEG-031',
                    'subject_type' => 'constitutional_settings',
                    'subject_id' => (string) $law->id,
                ],
            );

            return;
        }

        // Failed / expired: the setting does NOT change (Art. IV §3).
        $this->audit->append(
            module: 'settings',
            event: 'setting.dual_door_failed',
            payload: [
                'process_id' => (string) $process->id,
                'setting_key' => $key,
                'law_id' => (string) $law->id,
                'yes' => (int) $process->yes_count,
                'no' => (int) $process->no_count,
                'required' => (int) $process->required,
            ],
            ref: 'F-LEG-031',
            jurisdictionId: (string) $bill->jurisdiction_id,
        );

        $this->records->publish(
            kind: 'act',
            title: sprintf('Dual-door setting amendment failed — %s (constituent consent not reached)', $key),
            body: sprintf(
                'Process %s closed %s; the constituent supermajority was not reached, so [%s] is unchanged '
                .'(Art. IV §3 — the chamber supermajority alone is insufficient).',
                (string) $process->id,
                $process->status,
                $key
            ),
            attrs: [
                'jurisdiction_id' => (string) $bill->jurisdiction_id,
                'via_form' => 'F-LEG-031',
                'subject_type' => 'constitutional_settings',
                'subject_id' => (string) $law->id,
            ],
        );
    }
}

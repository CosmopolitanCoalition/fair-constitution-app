<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Jobs\Clocks\RederiveClockTimersJob;
use App\Models\Bill;
use App\Models\ChamberVote;
use App\Models\ConstitutionalSettings;
use App\Models\Law;
use App\Models\LawVersion;
use App\Models\Legislature;
use App\Models\SettingChange;
use Illuminate\Support\Facades\DB;

/**
 * C-B1/C-B2 (PHASE_C_DESIGN_votes_laws §C.5) — enactment: bill → law.
 *
 * Runs INSIDE the closing vote's engine transaction. Allocates the act
 * number under pg_advisory_xact_lock, writes laws + law_versions v1
 * (complete text, sha256 text_hash into the audit payload), flips the
 * bill to `enacted`, publishes the public record kind 'act'.
 *
 * SETTING BILLS (the F-LEG-031 legislative path — supersedes Phase A's
 * record-only behavior): re-run checkSettingChange (TOCTOU guard against
 * a bounds change between vote and enactment), write the setting_changes
 * ledger row, mutate the constitutional_settings row (creating it from
 * the nearest-ancestor copy when the jurisdiction has none), stamp
 * last_amended_by_act_id/at, bust the SettingsResolver memo, and
 * after-commit dispatch RederiveClockTimersJob so dependent clock timers
 * (CLK-01 from election_interval_months, CLK-02 from
 * max_days_between_meetings) re-derive their deadlines.
 *
 * Also exposes enactDirect() — the chamber-ops seam (their design names
 * it `LawService::enactDirect`) for F-LEG-032/033 rules-of-order/ethics
 * adoption at first sessions, before committees exist.
 */
class EnactmentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ConstitutionalValidator $validator,
        private readonly PublicRecordService $records,
        private readonly SettingsResolver $settings,
    ) {
    }

    // =========================================================================
    // Bill enactment
    // =========================================================================

    public function enact(Bill $bill, ChamberVote $vote): Law
    {
        if ($bill->status !== Bill::STATUS_PASSED) {
            throw new ConstitutionalViolation(
                'Only a passed bill enacts (ESM-07).',
                'Art. II §2 · as implemented'
            );
        }

        $version = $bill->currentVersion();

        if ($version === null) {
            throw new ConstitutionalViolation('Bill has no current version text.', 'Art. II §2 · as implemented');
        }

        $law = $this->writeLaw(
            legislatureId: (string) $bill->legislature_id,
            jurisdictionId: (string) $bill->jurisdiction_id,
            title: $bill->title,
            kind: $bill->act_type === Bill::TYPE_SETTING_CHANGE ? Law::KIND_SETTING_CHANGE : Law::KIND_ORDINARY,
            scale: $bill->scale ?? [(string) $bill->jurisdiction_id],
            text: $version->law_text,
            origin: Law::ORIGIN_BILL,
            sourceRefType: 'bill',
            sourceRefId: (string) $bill->id,
            scopeJudiciaryId: $bill->scope_judiciary_id,
            enactingBillId: (string) $bill->id,
            effectiveAt: $bill->effective_at,
            viaForm: 'F-LEG-004',
        );

        $bill->forceFill([
            'status'         => Bill::STATUS_ENACTED,
            'enacted_at'     => now(),
            'enacted_law_id' => $law->id,
        ])->save();

        if ($bill->act_type === Bill::TYPE_SETTING_CHANGE) {
            $this->applySettingChange($bill, $law);
        }

        return $law;
    }

    /**
     * Chamber-ops seam (F-LEG-032/033 and other direct-adoption acts):
     * laws row + v1 straight from an adopted chamber vote, no bill.
     */
    public function enactDirect(
        Legislature $legislature,
        string $kind,
        string $title,
        string $text,
        ChamberVote $vote,
    ): Law {
        if ($vote->outcome !== ChamberVote::OUTCOME_ADOPTED) {
            throw new ConstitutionalViolation('Direct adoption requires an adopted chamber vote.', 'Art. II §2');
        }

        return $this->writeLaw(
            legislatureId: (string) $legislature->id,
            jurisdictionId: (string) $legislature->jurisdiction_id,
            title: $title,
            kind: $kind,
            scale: [(string) $legislature->jurisdiction_id],
            text: $text,
            origin: Law::ORIGIN_BILL,
            sourceRefType: 'chamber_vote',
            sourceRefId: (string) $vote->id,
            scopeJudiciaryId: null,
            enactingBillId: null,
            effectiveAt: null,
            viaForm: null,
        );
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function writeLaw(
        string $legislatureId,
        string $jurisdictionId,
        string $title,
        string $kind,
        array $scale,
        string $text,
        string $origin,
        string $sourceRefType,
        string $sourceRefId,
        ?string $scopeJudiciaryId,
        ?string $enactingBillId,
        $effectiveAt,
        ?string $viaForm,
    ): Law {
        $actNumber = $this->allocateActNumber($legislatureId);
        $textHash  = hash('sha256', $text);

        $law = Law::create([
            'jurisdiction_id'    => $jurisdictionId,
            'legislature_id'     => $legislatureId,
            'act_number'         => $actNumber,
            'title'              => $title,
            'kind'               => $kind,
            'scale'              => $scale,
            'scope_judiciary_id' => $scopeJudiciaryId,
            'origin'             => $origin,
            'enacting_bill_id'   => $enactingBillId,
            'status'             => Law::STATUS_IN_FORCE,
            'current_version_no' => 1,
            'effective_at'       => $effectiveAt ?? now(),
            'enacted_at'         => now(),
        ]);

        LawVersion::create([
            'law_id'          => $law->id,
            'version_no'      => 1,
            'text'            => $text,
            'text_hash'       => $textHash,
            'source'          => LawVersion::SOURCE_ENACTMENT,
            'source_ref_type' => $sourceRefType,
            'source_ref_id'   => $sourceRefId,
            'created_at'      => now(),
        ]);

        $this->audit->append(
            module: 'legislature',
            event: 'law.enacted',
            payload: [
                'law_id'     => $law->id,
                'act_number' => $actNumber,
                'kind'       => $kind,
                'title'      => $title,
                'text_hash'  => $textHash,
                'origin'     => $origin,
                'source'     => [$sourceRefType => $sourceRefId],
            ],
            ref: 'WF-LEG-06',
            jurisdictionId: $jurisdictionId,
        );

        $this->records->publish(
            kind: 'act',
            title: "{$actNumber} — {$title}",
            body: $text,
            attrs: [
                'jurisdiction_id' => $jurisdictionId,
                'legislature_id'  => $legislatureId,
                'via_form'        => $viaForm,
                'subject_type'    => 'law',
                'subject_id'      => (string) $law->id,
            ],
        );

        return $law;
    }

    /**
     * "Act {YYYY}-{NN}" under pg_advisory_xact_lock — serial within the
     * enacting transaction; unique (legislature_id, act_number) is the
     * DB backstop.
     */
    private function allocateActNumber(string $legislatureId): string
    {
        DB::statement("SELECT pg_advisory_xact_lock(hashtext('act_number:' || ?))", [$legislatureId]);

        $year = now()->year;

        $taken = Law::query()
            ->where('legislature_id', $legislatureId)
            ->where('act_number', 'like', "Act {$year}-%")
            ->withTrashed()
            ->count();

        return sprintf('Act %d-%02d', $year, $taken + 1);
    }

    /**
     * The settings path (§C.5 + the exit criterion's second half).
     */
    private function applySettingChange(Bill $bill, Law $law): void
    {
        $key   = (string) $bill->targets_setting_key;
        $value = $bill->proposed_value;

        // TOCTOU guard: the bounds may have moved between vote and
        // enactment — re-run the PROTECTED check.
        $this->validator->checkSettingChange(['setting_key' => $key, 'value' => $value]);

        $row = ConstitutionalSettings::query()
            ->where('jurisdiction_id', $bill->jurisdiction_id)
            ->first();

        if ($row === null) {
            $row = $this->createFromNearestAncestor((string) $bill->jurisdiction_id);
        }

        $old = $row->{$key};

        $row->forceFill([
            $key                     => $value,
            'last_amended_by_act_id' => $law->id,
            'last_amended_at'        => now(),
        ])->save();

        SettingChange::create([
            'jurisdiction_id' => $bill->jurisdiction_id,
            'legislature_id'  => $bill->legislature_id,
            'setting_key'     => $key,
            'old_value'       => $old,
            'new_value'       => $value,
            'law_id'          => $law->id,
            'applied_at'      => now(),
            'created_at'      => now(),
        ]);

        $this->audit->append(
            module: 'settings',
            event: 'setting.applied',
            payload: [
                'setting_key' => $key,
                'old_value'   => $old,
                'new_value'   => $value,
                'law_id'      => $law->id,
                'act_number'  => $law->act_number,
            ],
            ref: 'F-LEG-031',
            jurisdictionId: (string) $bill->jurisdiction_id,
        );

        // Resolution happens at evaluation time everywhere — bust the
        // per-request memo so this very transaction's later reads see it.
        $this->settings->flush();

        // Dependent clock timers re-derive AFTER commit (the job reads
        // committed state; queue config decides sync/async execution).
        $jurisdictionId = (string) $bill->jurisdiction_id;
        DB::afterCommit(fn () => RederiveClockTimersJob::dispatch($key, $jurisdictionId));
    }

    /**
     * Activation semantics: a jurisdiction without its own settings row
     * inherits — enactment materializes the inheritance as a row copied
     * from the nearest ancestor that has one, then amends it.
     */
    private function createFromNearestAncestor(string $jurisdictionId): ConstitutionalSettings
    {
        $current = $jurisdictionId;

        for ($depth = 0; $depth < 32; $depth++) {
            $parent = DB::table('jurisdictions')->where('id', $current)->value('parent_id');

            if ($parent === null) {
                break;
            }

            $ancestor = ConstitutionalSettings::query()->where('jurisdiction_id', $parent)->first();

            if ($ancestor !== null) {
                $copy = $ancestor->replicate(['id', 'jurisdiction_id', 'last_amended_by_act_id', 'last_amended_at']);
                $copy->id = (string) \Illuminate\Support\Str::uuid();
                $copy->jurisdiction_id = $jurisdictionId;
                $copy->save();

                return $copy;
            }

            $current = (string) $parent;
        }

        // No ancestor row anywhere: migration defaults apply.
        return ConstitutionalSettings::create([
            'id'              => (string) \Illuminate\Support\Str::uuid(),
            'jurisdiction_id' => $jurisdictionId,
        ]);
    }
}

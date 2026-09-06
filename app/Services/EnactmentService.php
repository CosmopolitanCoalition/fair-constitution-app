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
use Illuminate\Support\Str;

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
    ) {}

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
            'status' => Bill::STATUS_ENACTED,
            'enacted_at' => now(),
            'enacted_law_id' => $law->id,
        ])->save();

        if ($bill->act_type === Bill::TYPE_SETTING_CHANGE) {
            $this->applySettingChange($bill, $law);
        }

        return $law;
    }

    /**
     * C-R1 (votes_laws §D "Certify") — enact a PASSED referendum question:
     * laws row origin 'referendum', kind 'referendum_act', with the CLK-19
     * shield inputs. `$passedBySupermajority` is computed by the caller
     * REGARDLESS of the question's threshold class (a majority-class
     * question passing at population-supermajority strength earns the
     * shield — Art. II §6 shields "acts passed by population
     * supermajority"); `$shieldElectionId` = the legislature's open
     * successor general — the shield lapses when it certifies.
     *
     * Setting questions apply through the SAME setting_changes path as
     * setting bills (TOCTOU re-check included).
     */
    public function enactFromReferendum(
        \App\Models\ReferendumQuestion $question,
        bool $passedBySupermajority,
        ?string $shieldElectionId,
    ): Law {
        $election = $question->election()->firstOrFail();
        $legislature = Legislature::query()->findOrFail($election->legislature_id);

        $law = $this->writeLaw(
            legislatureId: (string) $legislature->id,
            jurisdictionId: (string) $question->jurisdiction_id,
            title: mb_strimwidth($question->question, 0, 255, '…'),
            kind: Law::KIND_REFERENDUM_ACT,
            scale: [(string) $question->jurisdiction_id],
            text: $question->law_text,
            origin: Law::ORIGIN_REFERENDUM,
            sourceRefType: 'referendum_question',
            sourceRefId: (string) $question->id,
            scopeJudiciaryId: null,
            enactingBillId: null,
            effectiveAt: null,
            viaForm: 'F-ELB-004',
        );

        $law->forceFill([
            'origin_ref_type' => 'referendum_question',
            'origin_ref_id' => (string) $question->id,
            'referendum_passed_by_supermajority' => $passedBySupermajority,
            'shield_expires_with_election_id' => $passedBySupermajority ? $shieldElectionId : null,
        ])->save();

        if ($question->targets_setting_key !== null) {
            // Door 2b (PHASE_E_DESIGN_challenge_law §E.3): a population
            // referendum amends a setting (and a population-supermajority pass
            // earns the CLK-19 shield). A DUAL_DOOR_KEYS key is reachable here —
            // the population door is never the ONLY route, it widens (Art. II §6).
            $this->applySettingChangeForKey(
                (string) $question->jurisdiction_id,
                (string) $legislature->id,
                (string) $question->targets_setting_key,
                $question->proposed_value,
                $law,
                route: 'population_supermajority',
                processId: (string) $question->id,
                constituentConsented: true,
            );
        }

        return $law;
    }

    /**
     * THE law-amendment writer (versions 2+). DEFENSE IN DEPTH behind the
     * CLK-19 validator rule (referendum.shield): a shielded law — origin
     * 'referendum', passed by population supermajority, shield election
     * not yet certified — accepts NO legislative version source; only a
     * judicial remedy (Phase E) can pierce it. Pinned by
     * ReferendumShieldTest.
     */
    public function amendLaw(
        Law $law,
        string $text,
        string $source,
        string $sourceRefType,
        string $sourceRefId,
        ?string $viaForm = null,
    ): Law {
        if (trim($text) === '') {
            throw new ConstitutionalViolation('An amendment carries replacement law text.', 'Art. II §2 · as implemented');
        }

        $shielded = $law->origin === Law::ORIGIN_REFERENDUM
            && (bool) $law->referendum_passed_by_supermajority
            && app(ReferendumService::class)->shieldElectionPending($law);

        if ($shielded && $source !== LawVersion::SOURCE_JUDICIAL_REMEDY) {
            throw new ConstitutionalViolation(
                "Act {$law->act_number} was passed by population supermajority — the legislature cannot "
                .'modify or repeal it until the next general election certifies (the shield lapses there).',
                'Art. II §6'
            );
        }

        $next = (int) $law->current_version_no + 1;
        $textHash = hash('sha256', $text);

        LawVersion::create([
            'law_id' => $law->id,
            'version_no' => $next,
            'text' => $text,
            'text_hash' => $textHash,
            'source' => $source,
            'source_ref_type' => $sourceRefType,
            'source_ref_id' => $sourceRefId,
            'created_at' => now(),
        ]);

        $law->forceFill([
            'current_version_no' => $next,
            'status' => Law::STATUS_AMENDED,
        ])->save();

        $this->audit->append(
            module: 'legislature',
            event: 'law.amended',
            payload: [
                'law_id' => (string) $law->id,
                'act_number' => $law->act_number,
                'version_no' => $next,
                'source' => $source,
                'text_hash' => $textHash,
                'source_ref' => [$sourceRefType => $sourceRefId],
            ],
            ref: $viaForm ?? 'WF-LEG-06',
            jurisdictionId: (string) $law->jurisdiction_id,
        );

        $this->records->publish(
            kind: 'act',
            title: "{$law->act_number} amended — version {$next}",
            body: $text,
            attrs: [
                'jurisdiction_id' => (string) $law->jurisdiction_id,
                'legislature_id' => (string) $law->legislature_id,
                'via_form' => $viaForm,
                'subject_type' => 'law',
                'subject_id' => (string) $law->id,
            ],
        );

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

    /**
     * A FOUNDING law (Wave 6): written by the system actor at Step 4 for an
     * unseated chamber — today the charter of a system-act department
     * (F-LEG-016). origin = founding, source = the system act. The same
     * chain entry and public record as every other enactment.
     */
    public function enactFounding(
        Legislature $legislature,
        string $kind,
        string $title,
        string $text,
        string $viaForm,
    ): Law {
        return $this->writeLaw(
            legislatureId: (string) $legislature->id,
            jurisdictionId: (string) $legislature->jurisdiction_id,
            title: $title,
            kind: $kind,
            scale: [(string) $legislature->jurisdiction_id],
            text: $text,
            origin: Law::ORIGIN_FOUNDING,
            sourceRefType: 'system_act',
            sourceRefId: (string) $legislature->id,
            scopeJudiciaryId: null,
            enactingBillId: null,
            effectiveAt: null,
            viaForm: $viaForm,
        );
    }

    /**
     * SET-BASED founding enactment (performance 2026-09-06): the same product
     * as calling enactFounding once per law — one charter law + its v1 version
     * + the buffered law.enacted audit act + the 'act' public record — but for
     * a whole unit's laws in a fixed number of round trips. The act-number
     * block is allocated under ONE advisory lock and ONE max() query; the law
     * rows, version rows and record rows are each a single multi-row insert.
     * Audit acts buffer into the open legislature batch exactly as the
     * per-law path does (in law.enacted then records.published order per law),
     * so the one committed entry carries the same acts.
     *
     * Each input law: ['title'=>string, 'text'=>string]. Shared for all:
     * kind, origin=founding, scale=[jurisdictionId], source_ref (system_act →
     * legislatureId), viaForm. Returns, per input in order,
     * ['law_id'=>string, 'act_number'=>string].
     *
     * @param  list<array{title:string,text:string}>  $laws
     * @return list<array{law_id:string, act_number:string}>
     */
    public function enactFoundingMany(
        Legislature $legislature,
        string $kind,
        array $laws,
        string $viaForm,
    ): array {
        if ($laws === []) {
            return [];
        }

        $legislatureId  = (string) $legislature->id;
        $jurisdictionId = (string) $legislature->jurisdiction_id;
        $now            = now();
        $scale          = json_encode([$jurisdictionId]);

        $actNumbers = $this->allocateActNumberBlock($legislatureId, count($laws));

        $lawRows     = [];
        $versionRows = [];
        $recordRows  = [];
        $out         = [];

        foreach ($laws as $i => $law) {
            $lawId     = (string) Str::uuid();
            $actNumber = $actNumbers[$i];
            $title     = (string) $law['title'];
            $text      = (string) $law['text'];
            $textHash  = hash('sha256', $text);

            $lawRows[] = [
                'id'                 => $lawId,
                'jurisdiction_id'    => $jurisdictionId,
                'legislature_id'     => $legislatureId,
                'act_number'         => $actNumber,
                'title'              => $title,
                'kind'               => $kind,
                'scale'              => $scale,
                'origin'             => Law::ORIGIN_FOUNDING,
                'status'             => Law::STATUS_IN_FORCE,
                'current_version_no' => 1,
                'effective_at'       => $now,
                'enacted_at'         => $now,
                'created_at'         => $now,
                'updated_at'         => $now,
            ];

            $versionRows[] = [
                'id'              => (string) Str::uuid(),
                'law_id'          => $lawId,
                'version_no'      => 1,
                'text'            => $text,
                'text_hash'       => $textHash,
                'source'          => LawVersion::SOURCE_ENACTMENT,
                'source_ref_type' => 'system_act',
                'source_ref_id'   => $legislatureId,
                'created_at'      => $now,
            ];

            // law.enacted, then the record — the same two-act order the
            // per-law writeLaw() buffers.
            $this->audit->append(
                module: 'legislature',
                event: 'law.enacted',
                payload: [
                    'law_id'     => $lawId,
                    'act_number' => $actNumber,
                    'kind'       => $kind,
                    'title'      => $title,
                    'text_hash'  => $textHash,
                    'origin'     => Law::ORIGIN_FOUNDING,
                    'source'     => ['system_act' => $legislatureId],
                ],
                ref: 'WF-LEG-06',
                jurisdictionId: $jurisdictionId,
            );

            $recordId = (string) Str::uuid();
            $this->audit->append(
                module: 'records',
                event: 'published',
                payload: [
                    'record_id'    => $recordId,
                    'kind'         => 'act',
                    'title'        => "{$actNumber} — {$title}",
                    'subject_type' => 'law',
                    'subject_id'   => $lawId,
                    'via_form'     => $viaForm,
                    'via_workflow' => null,
                    'via_clock'    => null,
                ],
                ref: $viaForm,
                jurisdictionId: $jurisdictionId,
            );
            $recordRows[] = [
                'id'              => $recordId,
                'kind'            => 'act',
                'title'          => "{$actNumber} — {$title}",
                'body'            => $text,
                'jurisdiction_id' => $jurisdictionId,
                'legislature_id'  => $legislatureId,
                'via_form'        => $viaForm,
                'subject_type'    => 'law',
                'subject_id'      => $lawId,
                'audit_seq'       => null,
                'published_at'    => $now,
            ];

            $out[] = ['law_id' => $lawId, 'act_number' => $actNumber];
        }

        DB::table('laws')->insert($lawRows);
        DB::table('law_versions')->insert($versionRows);
        DB::table('public_records')->insert($recordRows);

        return $out;
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
        $textHash = hash('sha256', $text);

        $law = Law::create([
            'jurisdiction_id' => $jurisdictionId,
            'legislature_id' => $legislatureId,
            'act_number' => $actNumber,
            'title' => $title,
            'kind' => $kind,
            'scale' => $scale,
            'scope_judiciary_id' => $scopeJudiciaryId,
            'origin' => $origin,
            'enacting_bill_id' => $enactingBillId,
            'status' => Law::STATUS_IN_FORCE,
            'current_version_no' => 1,
            'effective_at' => $effectiveAt ?? now(),
            'enacted_at' => now(),
        ]);

        LawVersion::create([
            'law_id' => $law->id,
            'version_no' => 1,
            'text' => $text,
            'text_hash' => $textHash,
            'source' => LawVersion::SOURCE_ENACTMENT,
            'source_ref_type' => $sourceRefType,
            'source_ref_id' => $sourceRefId,
            'created_at' => now(),
        ]);

        $this->audit->append(
            module: 'legislature',
            event: 'law.enacted',
            payload: [
                'law_id' => $law->id,
                'act_number' => $actNumber,
                'kind' => $kind,
                'title' => $title,
                'text_hash' => $textHash,
                'origin' => $origin,
                'source' => [$sourceRefType => $sourceRefId],
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
                'legislature_id' => $legislatureId,
                'via_form' => $viaForm,
                'subject_type' => 'law',
                'subject_id' => (string) $law->id,
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

        // HIGHEST + 1, never COUNT + 1.
        //
        // COUNT is only correct while the sequence has no holes, and holes are
        // ordinary: a demo teardown hard-deletes its rows, an act is purged,
        // an act number outside this pattern is issued alongside. The moment
        // one exists, COUNT re-issues a number that is already taken and the
        // unique index rejects the enactment — which surfaces as a *vote*
        // failing to carry, because the law is written in the same
        // transaction as the tally. That is a long way from the real cause.
        //
        // Observed: a legislature holding Act 2026-02 and Act 2026-03 with 01
        // deleted counted 2 and minted "Act 2026-03" — a duplicate. It blocked
        // institutions:demo-d at the CGC charter and institutions:demo-e at
        // judiciary creation, and read as a bicameral vote refusing to pass.
        //
        // MAX is gap-safe and monotonic: numbers are never reused, so an act
        // number stays a permanent citation even after a neighbour is removed.
        // withTrashed() keeps soft-deleted acts reserved for the same reason.
        $highest = Law::query()
            ->where('legislature_id', $legislatureId)
            ->where('act_number', 'like', "Act {$year}-%")
            ->withTrashed()
            ->selectRaw("MAX(CAST(SUBSTRING(act_number FROM 'Act [0-9]{4}-([0-9]+)$') AS INTEGER)) AS n")
            ->value('n');

        return sprintf('Act %d-%02d', $year, ((int) $highest) + 1);
    }

    /**
     * A contiguous block of $count act numbers under ONE advisory lock and ONE
     * max() query (performance 2026-09-06). Identical result to calling
     * allocateActNumber() $count times inside one transaction — highest+1,
     * highest+2, … — with one round trip instead of $count. Same lock key, so
     * a concurrent single allocation on the same legislature still serializes.
     *
     * @return list<string> $count act-number strings in ascending order
     */
    private function allocateActNumberBlock(string $legislatureId, int $count): array
    {
        DB::statement("SELECT pg_advisory_xact_lock(hashtext('act_number:' || ?))", [$legislatureId]);

        $year = now()->year;

        $highest = Law::query()
            ->where('legislature_id', $legislatureId)
            ->where('act_number', 'like', "Act {$year}-%")
            ->withTrashed()
            ->selectRaw("MAX(CAST(SUBSTRING(act_number FROM 'Act [0-9]{4}-([0-9]+)$') AS INTEGER)) AS n")
            ->value('n');

        $next = ((int) $highest) + 1;

        $out = [];
        for ($k = 0; $k < $count; $k++) {
            $out[] = sprintf('Act %d-%02d', $year, $next + $k);
        }

        return $out;
    }

    /**
     * The settings path (§C.5 + the exit criterion's second half).
     *
     * Phase E (PHASE_E_DESIGN_challenge_law §E.3) — Door 2a detour: a setting
     * bill targeting a DUAL_DOOR_KEYS key does NOT mutate the setting on chamber
     * adoption. The chamber supermajority is insufficient (Art. IV §3 "must ALSO
     * consent"); the constituent process opens and the setting mutates only when
     * it passes. Where no constituents exist the constituent door is vacuously
     * satisfied and the chamber supermajority suffices.
     */
    private function applySettingChange(Bill $bill, Law $law): void
    {
        $key = (string) $bill->targets_setting_key;

        if (in_array($key, \App\Services\ConstitutionalValidator::DUAL_DOOR_KEYS, true)) {
            app(\App\Services\Judiciary\SettingAmendmentDoorService::class)
                ->onDualDoorChamberAdoption($bill, $law);

            return;
        }

        $this->applySettingChangeForKey(
            (string) $bill->jurisdiction_id,
            $bill->legislature_id !== null ? (string) $bill->legislature_id : null,
            $key,
            $bill->proposed_value,
            $law,
        );
    }

    /**
     * The generalized settings application — shared by setting BILLS, setting
     * REFERENDUM QUESTIONS (votes_laws §D), and the Door 2a constituent pass
     * (PHASE_E_DESIGN_challenge_law §E.3). `$route`/`$processId` stamp the
     * two-door provenance; `$constituentConsented` carries the dual-door
     * authorization through the TOCTOU re-check (a DUAL_DOOR_KEYS key applies
     * ONLY via the constituent door, never Door 1 alone).
     */
    public function applySettingChangeForKey(
        string $jurisdictionId,
        ?string $legislatureId,
        string $key,
        mixed $value,
        Law $law,
        ?string $route = null,
        ?string $processId = null,
        bool $constituentConsented = false,
    ): void {
        // TOCTOU guard: the bounds may have moved between vote and
        // enactment — re-run the PROTECTED check. A DUAL_DOOR_KEYS key carries
        // the constituent-consent authorization so the re-check passes (the
        // door was satisfied upstream).
        $this->validator->checkSettingChange([
            'setting_key' => $key,
            'value' => $value,
        ] + ($constituentConsented ? ['requires_constituent_consent' => true] : []));

        $row = ConstitutionalSettings::query()
            ->where('jurisdiction_id', $jurisdictionId)
            ->first();

        if ($row === null) {
            $row = $this->createFromNearestAncestor($jurisdictionId);
        }

        $old = $row->{$key};

        $row->forceFill([
            $key => $value,
            'last_amended_by_act_id' => $law->id,
            'last_amended_at' => now(),
            'last_amendment_route' => $route,
            'last_amendment_process_id' => $processId,
        ])->save();

        SettingChange::create([
            'jurisdiction_id' => $jurisdictionId,
            'legislature_id' => $legislatureId,
            'setting_key' => $key,
            'old_value' => $old,
            'new_value' => $value,
            'law_id' => $law->id,
            'applied_at' => now(),
            'created_at' => now(),
        ]);

        $this->audit->append(
            module: 'settings',
            event: 'setting.applied',
            payload: [
                'setting_key' => $key,
                'old_value' => $old,
                'new_value' => $value,
                'law_id' => $law->id,
                'act_number' => $law->act_number,
            ],
            ref: 'F-LEG-031',
            jurisdictionId: $jurisdictionId,
        );

        // Resolution happens at evaluation time everywhere — bust the
        // per-request memo so this very transaction's later reads see it.
        $this->settings->flush();

        // Dependent clock timers re-derive AFTER commit (the job reads
        // committed state; queue config decides sync/async execution).
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
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'jurisdiction_id' => $jurisdictionId,
        ]);
    }
}

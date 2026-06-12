<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Bill;
use App\Models\BillVersion;
use App\Models\ChamberVote;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\LegislatureSession;
use Illuminate\Support\Facades\DB;

/**
 * C-B1 (PHASE_C_DESIGN_votes_laws §C) — ESM-07 bill lifecycle:
 *
 *   introduced → referred → in_committee → (reported | tabled)
 *              → on_floor → (passed | failed) → enacted, plus withdrawn.
 *
 * `act_type` fixes the floor-vote basis at introduction; `scale` is
 * validated ⊆ the legislature's jurisdiction subtree at F-LEG-003 and
 * fixed there (Art. V §4). Setting bills run
 * ConstitutionalValidator::checkSettingChange PRE-VOTE (the Phase A
 * rejection path, unchanged) and again at enactment (TOCTOU guard).
 *
 * Bicameral chambers pass only when the committee vote AND the floor
 * vote each adopt per kind — the vote engine enforces (q-ledger #q7);
 * this lifecycle just consumes `outcome`.
 *
 * Committee seams for the chamber-ops scope: referToCommittee() /
 * openCommitteeVote() / committeeReported() / moveToFloor() — their
 * F-CHR-003 referral gate calls moveToFloor(); direct-to-floor (the
 * Phase C exit-criterion path) reaches it through an adopted
 * direct_to_floor motion.
 */
class BillService
{
    /** Jurisdiction-chain walk bound (matches SettingsResolver). */
    private const MAX_CHAIN_DEPTH = 32;

    public function __construct(
        private readonly ConstitutionalValidator $validator,
        private readonly PublicRecordService $records,
    ) {
    }

    // =========================================================================
    // F-LEG-003 — introduce
    // =========================================================================

    /**
     * @param  array{
     *   title: string, law_text: string, act_type: string,
     *   scale?: list<string>, scope_judiciary_id?: ?string,
     *   targets_setting_key?: ?string, proposed_value?: mixed,
     *   effective_at?: ?string
     * } $payload
     */
    public function introduce(Legislature $legislature, LegislatureMember $sponsor, array $payload): Bill
    {
        if ((string) $sponsor->legislature_id !== (string) $legislature->id
            || ! in_array($sponsor->status, LegislatureMember::CURRENT_STATUSES, true)) {
            throw new ConstitutionalViolation(
                'Only a currently seated member of this legislature may sponsor a bill.',
                'Art. II §2'
            );
        }

        $actType = (string) ($payload['act_type'] ?? '');

        if (! in_array($actType, Bill::ACT_TYPES, true)) {
            throw new ConstitutionalViolation("Unknown act_type [{$actType}].", 'Art. II §2 · as implemented');
        }

        $lawText = (string) ($payload['law_text'] ?? '');

        if (trim($lawText) === '') {
            throw new ConstitutionalViolation('A bill carries binding law text.', 'Art. II §2 · as implemented');
        }

        // Scale: defaults to the legislature's own jurisdiction; every
        // bound id must lie in the legislature's subtree (Art. V §4 —
        // fixed at introduction).
        $scale = array_values(array_map('strval', $payload['scale'] ?? [(string) $legislature->jurisdiction_id]));

        foreach ($scale as $jurisdictionId) {
            if (! $this->inSubtree($jurisdictionId, (string) $legislature->jurisdiction_id)) {
                throw new ConstitutionalViolation(
                    "Scale jurisdiction [{$jurisdictionId}] lies outside this legislature's jurisdiction subtree.",
                    'Art. V §4'
                );
            }
        }

        // Scope judiciary (when named): must belong to the legislature's
        // jurisdiction or an ancestor (an encompassing court may hear).
        $judiciaryId = $payload['scope_judiciary_id'] ?? null;

        if ($judiciaryId !== null) {
            $judiciaryJurisdiction = DB::table('judiciaries')->where('id', $judiciaryId)->value('jurisdiction_id');

            if ($judiciaryJurisdiction === null
                || ! $this->inSubtree((string) $legislature->jurisdiction_id, (string) $judiciaryJurisdiction)) {
                throw new ConstitutionalViolation(
                    'scope_judiciary_id must name a judiciary of this jurisdiction or an encompassing one.',
                    'Art. IV §1 · as implemented'
                );
            }
        }

        // Setting bills: bounds-checked PRE-VOTE (Phase A path, unchanged).
        $settingKey    = $payload['targets_setting_key'] ?? null;
        $proposedValue = $payload['proposed_value'] ?? null;

        if (($actType === Bill::TYPE_SETTING_CHANGE) !== ($settingKey !== null)) {
            throw new ConstitutionalViolation(
                'setting_change bills (and only they) target a setting key.',
                'Art. VII'
            );
        }

        if ($settingKey !== null) {
            $this->validator->checkSettingChange([
                'setting_key' => $settingKey,
                'value'       => $proposedValue,
            ] + (is_array($proposedValue) ? [] : []));
        }

        $bill = Bill::create([
            'legislature_id'      => $legislature->id,
            'jurisdiction_id'     => $legislature->jurisdiction_id,
            'sponsor_member_id'   => $sponsor->id,
            'title'               => (string) $payload['title'],
            'act_type'            => $actType,
            'scale'               => $scale,
            'scope_judiciary_id'  => $judiciaryId,
            'targets_setting_key' => $settingKey,
            'proposed_value'      => $proposedValue,
            'effective_at'        => $payload['effective_at'] ?? null,
            'status'              => Bill::STATUS_INTRODUCED,
            'current_version_no'  => 1,
            'introduced_at'       => now(),
        ]);

        BillVersion::create([
            'bill_id'              => $bill->id,
            'version_no'           => 1,
            'law_text'             => $lawText,
            'changed_by_member_id' => $sponsor->id,
            'change_kind'          => BillVersion::KIND_INTRODUCTION,
            'created_at'           => now(),
        ]);

        $this->records->publish(
            kind: 'bill',
            title: "Bill introduced — {$bill->title}",
            body: $lawText,
            attrs: [
                'actor_user_id'   => (string) $sponsor->user_id,
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-LEG-003',
                'subject_type'    => 'bill',
                'subject_id'      => (string) $bill->id,
            ],
        );

        return $bill;
    }

    // =========================================================================
    // Committee seams (chamber-ops consumes)
    // =========================================================================

    public function referToCommittee(?Bill $bill, ?string $committeeId): void
    {
        if ($bill === null) {
            return;
        }

        $this->assertStatus($bill, [Bill::STATUS_INTRODUCED, Bill::STATUS_REFERRED]);

        $bill->forceFill([
            'status'       => $committeeId !== null ? Bill::STATUS_IN_COMMITTEE : Bill::STATUS_REFERRED,
            'committee_id' => $committeeId,
        ])->save();
    }

    /** Open the committee_bill vote (stage='committee' — q7 applies). */
    public function openCommitteeVote(Bill $bill, string $committeeId, ?LegislatureMember $opener = null): ChamberVote
    {
        $this->assertStatus($bill, [Bill::STATUS_REFERRED, Bill::STATUS_IN_COMMITTEE]);

        return app(ChamberVoteService::class)->open(
            bodyType: ChamberVote::BODY_COMMITTEE,
            bodyId: $committeeId,
            voteType: 'committee_bill',
            votable: $bill,
            stage: ChamberVote::STAGE_COMMITTEE,
            opener: $opener,
            basisOverride: $this->basisFor($bill),
        );
    }

    /** Committee outcome consumer (also reachable via resolveBillVote). */
    public function committeeReported(Bill $bill, bool $adopted): void
    {
        $this->assertStatus($bill, [Bill::STATUS_REFERRED, Bill::STATUS_IN_COMMITTEE]);

        $bill->forceFill([
            'status' => $adopted ? Bill::STATUS_REPORTED : Bill::STATUS_TABLED,
        ])->save();
    }

    public function table(?Bill $bill): void
    {
        if ($bill === null) {
            return;
        }

        $bill->forceFill(['status' => Bill::STATUS_TABLED])->save();
    }

    // =========================================================================
    // Floor
    // =========================================================================

    /**
     * on_floor + open the floor vote in the same transaction. Reached by
     * an adopted direct_to_floor motion (the exit-criterion path) or by
     * the chamber-ops F-CHR-003 referral after a passing committee vote.
     */
    public function moveToFloor(?Bill $bill, ?LegislatureSession $session = null, ?LegislatureMember $opener = null): ?ChamberVote
    {
        if ($bill === null) {
            return null;
        }

        $this->assertStatus($bill, [Bill::STATUS_INTRODUCED, Bill::STATUS_REFERRED, Bill::STATUS_REPORTED, Bill::STATUS_IN_COMMITTEE]);

        $bill->forceFill(['status' => Bill::STATUS_ON_FLOOR])->save();

        // dual_supermajority: the constituent-consent process
        // (multi_jurisdiction_votes) gains its consumer with F-LEG-015
        // (Phase D); the floor vote itself runs at supermajority now.
        return app(ChamberVoteService::class)->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $bill->legislature_id,
            voteType: 'bill_pass',
            votable: $bill,
            stage: ChamberVote::STAGE_FLOOR,
            session: $session,
            opener: $opener,
            basisOverride: $this->basisFor($bill),
        );
    }

    /** Floor amendments append versions (adopted `amendment` motions). */
    public function applyAmendment(?Bill $bill, string $text, ?LegislatureMember $member, string $changeKind): void
    {
        if ($bill === null || trim($text) === '') {
            return;
        }

        $next = $bill->current_version_no + 1;

        BillVersion::create([
            'bill_id'              => $bill->id,
            'version_no'           => $next,
            'law_text'             => $text,
            'changed_by_member_id' => $member?->id,
            'change_kind'          => $changeKind,
            'created_at'           => now(),
        ]);

        $bill->forceFill(['current_version_no' => $next])->save();
    }

    /**
     * Vote-close side-effect (called by ChamberVoteService inside the
     * closing transaction): consume the outcome at either stage.
     * Adopted floor vote → passed → ENACTED in the same transaction
     * (failed bills are archived with their casts + explanations —
     * already public records).
     */
    public function resolveBillVote(ChamberVote $vote, string $outcome): void
    {
        $bill = Bill::query()->find($vote->votable_id);

        if ($bill === null) {
            return;
        }

        if ($vote->stage === ChamberVote::STAGE_COMMITTEE) {
            if (in_array($bill->status, [Bill::STATUS_REFERRED, Bill::STATUS_IN_COMMITTEE], true)) {
                $this->committeeReported($bill, $outcome === ChamberVote::OUTCOME_ADOPTED);
            }

            return;
        }

        if ($bill->status !== Bill::STATUS_ON_FLOOR) {
            return;
        }

        if ($outcome === ChamberVote::OUTCOME_ADOPTED) {
            $bill->forceFill(['status' => Bill::STATUS_PASSED, 'passed_at' => now()])->save();

            app(EnactmentService::class)->enact($bill, $vote);
        } else {
            $bill->forceFill(['status' => Bill::STATUS_FAILED, 'failed_at' => now()])->save();
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * The act_type → floor basis fix (ESM-07): ordinary/setting_change
     * vote at the bill_pass majority; supermajority/dual_supermajority
     * carry a supermajority basis. PURE — pinned by SettingEnactmentTest.
     */
    public static function basisForActType(string $actType): ?string
    {
        return match ($actType) {
            Bill::TYPE_SUPERMAJORITY, Bill::TYPE_DUAL_SUPERMAJORITY => ChamberVote::BASIS_SUPERMAJORITY,
            default => null, // registry default (majority)
        };
    }

    private function basisFor(Bill $bill): ?string
    {
        return self::basisForActType($bill->act_type);
    }

    private function assertStatus(Bill $bill, array $allowed): void
    {
        if (! in_array($bill->status, $allowed, true)) {
            throw new ConstitutionalViolation(
                "Illegal bill transition from [{$bill->status}] (ESM-07).",
                'Art. II §2 · as implemented'
            );
        }
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

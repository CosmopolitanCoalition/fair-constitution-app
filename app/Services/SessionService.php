<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\ChamberVote;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\LegislatureSession;
use App\Models\Motion;
use App\Models\SessionAttendance;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-S1 (PHASE_C_DESIGN_votes_laws §A C-2, §G) — session lifecycle
 * orchestrator. Every mutation is invoked from inside an engine handler
 * (F-SPK-001/002/003/008/009, F-LEG-002/007) — the engine transaction and
 * chain entry wrap each one.
 *
 * Quorum semantics (hardened): serving members only — a vacant seat is
 * simply not serving (Montegiardino: 9 seats, 8 serving, quorum 5); the
 * thresholds snapshot through ConstitutionalValidator at open. Bicameral
 * chambers additionally snapshot PER-KIND quorums (q-ledger #q7 extended:
 * a session where one kind is below its own quorum has not met quorum,
 * because no bicameral act could validly pass in it).
 *
 * CLK-02 (Art. II §2, ≤90 days between meetings): adjourn() writes
 * legislatures.last_met_on / next_meeting_due_by and cancel+re-arms the
 * CLK-02 timer with a derivation anchor
 * payload.derive = {anchor_at: last_met_on, unit: 'days'} so a later
 * max_days_between_meetings setting change re-derives the deadline
 * (ClockService::rederiveForSetting).
 */
class SessionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly SettingsResolver $settings,
        private readonly PublicRecordService $records,
        private readonly ClockService $clocks,
    ) {
    }

    // =========================================================================
    // F-SPK-001 — call / open
    // =========================================================================

    public function call(
        Legislature $legislature,
        ?LegislatureMember $calledBy = null,
        ?CarbonInterface $scheduledFor = null,
        bool $openNow = false,
    ): LegislatureSession {
        $open = LegislatureSession::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', [LegislatureSession::STATUS_SCHEDULED, LegislatureSession::STATUS_OPEN, LegislatureSession::STATUS_FAILED_QUORUM])
            ->exists();

        if ($open) {
            throw new ConstitutionalViolation(
                'The chamber already has an unresolved session (scheduled or open).',
                'Art. II §2 · as implemented'
            );
        }

        $sessionNo = (int) LegislatureSession::query()
            ->where('legislature_id', $legislature->id)
            ->withTrashed()
            ->max('session_no') + 1;

        $session = LegislatureSession::create([
            'legislature_id'      => $legislature->id,
            'session_no'          => $sessionNo,
            'called_by_member_id' => $calledBy?->id,
            'scheduled_for'       => $scheduledFor ?? now(),
            'status'              => LegislatureSession::STATUS_SCHEDULED,
        ]);

        return $openNow ? $this->open($session) : $session;
    }

    /**
     * Open: snapshot serving + quorum (per-kind when bicameral) through
     * the PROTECTED functions, materialize attendance rows (absent until
     * F-LEG-002 flips them), and compose the locked agenda head — one
     * slot-1 item per emergency power active in the chamber's footprint
     * (Art. II §2 order of business; the emergency substrate is batch 2 —
     * until its table exists the head is honestly empty) plus the
     * empty-capable slot-2 (constitutional matters, Phase E feed).
     */
    public function open(LegislatureSession $session): LegislatureSession
    {
        if (! in_array($session->status, [LegislatureSession::STATUS_SCHEDULED, LegislatureSession::STATUS_FAILED_QUORUM], true)) {
            throw new ConstitutionalViolation(
                "Session {$session->session_no} cannot open from status [{$session->status}].",
                'Art. II §2 · as implemented'
            );
        }

        $legislature = $session->legislature;

        $members = LegislatureMember::query()
            ->where('legislature_id', $legislature->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->get(['id', 'seat_type']);

        $serving   = $members->count();
        $bicameral = (int) $legislature->type_b_seats > 0;

        $servingByKind = $quorumByKind = null;

        if ($bicameral) {
            $a = $members->where('seat_type', 'a')->count();
            $b = $members->where('seat_type', 'b')->count();

            $servingByKind = ['type_a' => $a, 'type_b' => $b];
            $quorumByKind  = [
                'type_a' => ConstitutionalValidator::quorum($a),
                'type_b' => ConstitutionalValidator::quorum($b),
            ];
        }

        $session->forceFill([
            'status'                  => LegislatureSession::STATUS_OPEN,
            'opened_at'               => now(),
            'serving_at_open'         => $serving,
            'quorum_required'         => ConstitutionalValidator::quorum($serving),
            'serving_by_kind'         => $servingByKind,
            'quorum_required_by_kind' => $quorumByKind,
            'agenda'                  => $this->composeLockedHead($legislature, $session->agenda ?? []),
        ])->save();

        // Materialize attendance (absent until F-LEG-002 / F-SPK-008).
        foreach ($members as $member) {
            SessionAttendance::query()->firstOrCreate(
                ['session_id' => $session->id, 'member_id' => $member->id],
                ['status' => SessionAttendance::STATUS_ABSENT, 'recorded_via_form' => 'system', 'recorded_at' => now()],
            );
        }

        return $session;
    }

    // =========================================================================
    // F-LEG-002 / F-SPK-008 — attendance
    // =========================================================================

    public function registerAttendance(
        LegislatureSession $session,
        LegislatureMember $member,
        string $status = SessionAttendance::STATUS_PRESENT,
        string $viaForm = 'F-LEG-002',
    ): SessionAttendance {
        if ($session->status !== LegislatureSession::STATUS_OPEN
            && ! ($viaForm === 'F-SPK-008' && $session->status === LegislatureSession::STATUS_FAILED_QUORUM)) {
            throw new ConstitutionalViolation(
                'Attendance is recorded against an open session.',
                'Art. II §2 · as implemented'
            );
        }

        if ((string) $member->legislature_id !== (string) $session->legislature_id
            || ! in_array($member->status, LegislatureMember::CURRENT_STATUSES, true)) {
            throw new ConstitutionalViolation(
                'Only currently serving members of this chamber register attendance.',
                'Art. II §2'
            );
        }

        return SessionAttendance::query()->updateOrCreate(
            ['session_id' => $session->id, 'member_id' => $member->id],
            ['status' => $status, 'recorded_via_form' => $viaForm, 'recorded_at' => now()],
        );
    }

    /** F-SPK-008 — compel every still-absent member (WF-LEG-20). */
    public function compelAttendance(LegislatureSession $session): int
    {
        if ($session->status !== LegislatureSession::STATUS_FAILED_QUORUM) {
            throw new ConstitutionalViolation(
                'Attendance compulsion follows a failed quorum count (WF-LEG-20).',
                'Art. II §2'
            );
        }

        $compelled = SessionAttendance::query()
            ->where('session_id', $session->id)
            ->where('status', SessionAttendance::STATUS_ABSENT)
            ->update([
                'status'            => SessionAttendance::STATUS_COMPELLED,
                'recorded_via_form' => 'F-SPK-008',
                'recorded_at'       => now(),
            ]);

        $this->records->publish(
            kind: 'other',
            title: "Attendance compulsion order — session {$session->session_no}",
            body: "{$compelled} member(s) compelled to attend (Art. II §2, WF-LEG-20).",
            attrs: [
                'jurisdiction_id' => (string) $session->legislature->jurisdiction_id,
                'legislature_id'  => (string) $session->legislature_id,
                'via_form'        => 'F-SPK-008',
                'subject_type'    => 'legislature_session',
                'subject_id'      => (string) $session->id,
            ],
        );

        return $compelled;
    }

    // =========================================================================
    // F-SPK-003 — quorum count publication
    // =========================================================================

    /**
     * Snapshot present vs quorum_required (per kind when bicameral —
     * EVERY kind must meet its own peg quorum). Not met → failed_quorum
     * (WF-LEG-20 branch); met → session stays/returns open.
     *
     * @return array{present: int, quorum_required: int, met: bool, by_kind: ?array}
     */
    public function publishQuorumCount(LegislatureSession $session): array
    {
        if (! in_array($session->status, [LegislatureSession::STATUS_OPEN, LegislatureSession::STATUS_FAILED_QUORUM], true)) {
            throw new ConstitutionalViolation(
                'Quorum is counted in an open session.',
                'Art. II §2'
            );
        }

        $present = SessionAttendance::query()
            ->where('session_id', $session->id)
            ->whereIn('status', SessionAttendance::COUNTED_PRESENT)
            ->count();

        $met    = $present >= (int) $session->quorum_required;
        $byKind = null;

        if ($session->quorum_required_by_kind !== null) {
            $byKind = [];

            foreach ($session->quorum_required_by_kind as $kind => $required) {
                $seatType = $kind === 'type_a' ? 'a' : 'b';

                $kindPresent = SessionAttendance::query()
                    ->where('session_id', $session->id)
                    ->whereIn('status', SessionAttendance::COUNTED_PRESENT)
                    ->whereIn('member_id', fn ($sub) => $sub->select('id')->from('legislature_members')
                        ->where('legislature_id', $session->legislature_id)
                        ->where('seat_type', $seatType)
                        ->whereIn('status', LegislatureMember::CURRENT_STATUSES))
                    ->count();

                $kindMet = $kindPresent >= (int) $required;
                $byKind[$kind] = ['present' => $kindPresent, 'required' => (int) $required, 'met' => $kindMet];
                $met = $met && $kindMet;
            }
        }

        $session->forceFill([
            'quorum_met' => $met,
            'status'     => $met ? LegislatureSession::STATUS_OPEN : LegislatureSession::STATUS_FAILED_QUORUM,
        ])->save();

        $this->records->publish(
            kind: 'participation',
            title: sprintf(
                'Quorum count — session %d: %d of %d serving present — %s',
                $session->session_no,
                $present,
                $session->serving_at_open,
                $met ? 'quorum met' : 'quorum NOT met'
            ),
            body: $byKind !== null ? json_encode($byKind) : null,
            attrs: [
                'jurisdiction_id' => (string) $session->legislature->jurisdiction_id,
                'legislature_id'  => (string) $session->legislature_id,
                'via_form'        => 'F-SPK-003',
                'subject_type'    => 'legislature_session',
                'subject_id'      => (string) $session->id,
            ],
        );

        return [
            'present'         => $present,
            'quorum_required' => (int) $session->quorum_required,
            'met'             => $met,
            'by_kind'         => $byKind,
        ];
    }

    // =========================================================================
    // F-SPK-002 — agenda
    // =========================================================================

    /**
     * Replace the unlocked agenda tail / mark a locked slot-1 item
     * addressed. Locked items are immutable to filings (Art. II §2 order
     * — the validator-grade rule, enforced server-side here).
     *
     * @param  list<array>  $tail   new unlocked items (slot ≥ 3 ordering preserved)
     * @param  string|null  $addressRefId  slot-1 item ref_id to mark addressed
     */
    public function setAgenda(LegislatureSession $session, array $tail = [], ?string $addressRefId = null): array
    {
        if ($session->status !== LegislatureSession::STATUS_OPEN) {
            throw new ConstitutionalViolation('Agenda is set on an open session.', 'Art. II §2');
        }

        $agenda = $session->agenda ?? [];
        $locked = array_values(array_filter($agenda, fn (array $i) => $i['locked'] ?? false));

        if ($addressRefId !== null) {
            $found = false;

            foreach ($locked as &$item) {
                if (($item['ref_id'] ?? null) === $addressRefId && ($item['status'] ?? null) === 'pending') {
                    $item['status'] = 'addressed';
                    $found = true;
                }
            }
            unset($item);

            if (! $found) {
                throw new ConstitutionalViolation(
                    'No pending locked agenda item matches the acknowledgment.',
                    'Art. II §2'
                );
            }
        }

        $slot = 3; // slots 1–2 are the locked head
        $newTail = [];

        foreach ($tail as $item) {
            $item = (array) $item;

            if ($item['locked'] ?? false) {
                throw new ConstitutionalViolation(
                    'Filings may not insert locked agenda items — the locked head is engine-composed.',
                    'Art. II §2'
                );
            }

            $newTail[] = [
                'slot'     => $slot++,
                'kind'     => in_array($item['kind'] ?? 'general', LegislatureSession::AGENDA_KINDS, true) ? ($item['kind'] ?? 'general') : 'general',
                'ref_type' => $item['ref_type'] ?? null,
                'ref_id'   => $item['ref_id'] ?? null,
                'title'    => (string) ($item['title'] ?? ''),
                'locked'   => false,
                'status'   => $item['status'] ?? 'pending',
            ];
        }

        $agenda = array_merge($locked, $newTail);

        $session->forceFill(['agenda' => $agenda])->save();

        return $agenda;
    }

    // =========================================================================
    // F-LEG-007 — motions (+ the procedural vote)
    // =========================================================================

    /**
     * Create a motion and open its procedural_motion chamber vote in the
     * same filing (ESM-08; the speaker-recognition flow can use the
     * intermediate statuses later — the deciding threshold is identical
     * either way: ordinary majority of all serving, the owner ruling).
     */
    public function submitMotion(
        LegislatureSession $session,
        LegislatureMember $movedBy,
        string $kind,
        string $text,
        ?string $billId = null,
        ?string $amendmentText = null,
        bool $openVote = true,
    ): Motion {
        if ($session->status !== LegislatureSession::STATUS_OPEN) {
            throw new ConstitutionalViolation('Motions are submitted in an open session.', 'Art. II §2');
        }

        if (! in_array($kind, Motion::KINDS, true)) {
            throw new ConstitutionalViolation("Unknown motion kind [{$kind}].", 'Art. II §2 · as implemented');
        }

        if (in_array($kind, Motion::BILL_KINDS, true) && $billId === null) {
            throw new ConstitutionalViolation("A [{$kind}] motion names a bill.", 'Art. II §2 · as implemented');
        }

        $motion = Motion::create([
            'session_id'         => $session->id,
            'bill_id'            => $billId,
            'moved_by_member_id' => $movedBy->id,
            'text'               => $text,
            'kind'               => $kind,
            'status'             => Motion::STATUS_SUBMITTED,
            'amendment_text'     => $amendmentText,
        ]);

        if ($openVote) {
            $vote = app(ChamberVoteService::class)->open(
                bodyType: ChamberVote::BODY_LEGISLATURE,
                bodyId: (string) $session->legislature_id,
                voteType: 'procedural_motion',
                votable: $motion,
                stage: null,
                session: $session,
                opener: $movedBy,
            );

            $motion->forceFill(['status' => Motion::STATUS_VOTED, 'vote_id' => $vote->id])->save();
        }

        return $motion;
    }

    /**
     * Vote-close side-effect (called by ChamberVoteService inside the
     * closing transaction): resolve the motion and apply its ESM-08
     * consequence.
     */
    public function resolveMotionVote(ChamberVote $vote, string $outcome): void
    {
        $motion = Motion::query()->find($vote->votable_id);

        if ($motion === null || in_array($motion->status, [Motion::STATUS_ADOPTED, Motion::STATUS_FAILED, Motion::STATUS_WITHDRAWN], true)) {
            return;
        }

        $motion->forceFill([
            'status' => $outcome === ChamberVote::OUTCOME_ADOPTED ? Motion::STATUS_ADOPTED : Motion::STATUS_FAILED,
        ])->save();

        if ($outcome !== ChamberVote::OUTCOME_ADOPTED) {
            return;
        }

        $bills = app(BillService::class);

        match ($motion->kind) {
            Motion::KIND_DIRECT_TO_FLOOR => $bills->moveToFloor($motion->bill, $motion->session, $motion->movedBy),
            Motion::KIND_REFERRAL        => $bills->referToCommittee($motion->bill, $motion->bill?->committee_id),
            Motion::KIND_TABLE           => $bills->table($motion->bill),
            Motion::KIND_AMENDMENT       => $bills->applyAmendment(
                $motion->bill,
                (string) $motion->amendment_text,
                $motion->movedBy,
                \App\Models\BillVersion::KIND_FLOOR_AMENDMENT,
            ),
            // replace_speaker adoption opens the chamber-ops speaker
            // balloting (auto-recognized — the chair cannot block its own
            // replacement; the supermajority lives in the resulting
            // ballot, not the motion). adjourn resolves through F-SPK-009.
            Motion::KIND_REPLACE_SPEAKER => app(\App\Services\Legislature\SpeakerService::class)
                ->openBallot($motion->session->legislature),
            default => null,
        };
    }

    // =========================================================================
    // F-SPK-009 — minutes + adjournment + CLK-02
    // =========================================================================

    /**
     * Publish minutes, adjourn, stamp last_met_on / next_meeting_due_by,
     * and cancel+re-arm CLK-02 with the derivation anchor. "The meeting
     * occurred" anchors at the session's quorum-verified opening date.
     */
    public function adjourn(LegislatureSession $session, string $minutesBody, ?string $minutesTitle = null): LegislatureSession
    {
        if (! in_array($session->status, [LegislatureSession::STATUS_OPEN, LegislatureSession::STATUS_FAILED_QUORUM], true)) {
            throw new ConstitutionalViolation(
                "Session {$session->session_no} cannot adjourn from [{$session->status}].",
                'Art. II §2 · as implemented'
            );
        }

        $legislature = $session->legislature;

        $record = $this->records->publish(
            kind: 'minutes',
            title: $minutesTitle ?? "Session {$session->session_no} minutes — " . now()->toDateString(),
            body: $minutesBody,
            attrs: [
                'jurisdiction_id' => (string) $legislature->jurisdiction_id,
                'legislature_id'  => (string) $legislature->id,
                'via_form'        => 'F-SPK-009',
                'subject_type'    => 'legislature_session',
                'subject_id'      => (string) $session->id,
            ],
        );

        $session->forceFill([
            'status'            => LegislatureSession::STATUS_ADJOURNED,
            'adjourned_at'      => now(),
            'minutes_record_id' => $record->id,
        ])->save();

        // A failed-quorum session never resets the clock — the chamber
        // did not constitutionally MEET (WF-LEG-20: "90-day clock still
        // enforced").
        if ($session->quorum_met === true) {
            $this->resetMeetingClock($legislature, Carbon::parse($session->opened_at ?? now()));
        }

        return $session;
    }

    /**
     * CLK-02 bookkeeping: last_met_on / next_meeting_due_by + cancel and
     * re-arm the rolling deadline with payload.derive — the anchor a
     * max_days_between_meetings change re-derives from.
     */
    public function resetMeetingClock(Legislature $legislature, CarbonInterface $metAt): void
    {
        $days  = $this->settings->resolveInt((string) $legislature->jurisdiction_id, 'max_days_between_meetings', 90);
        $dueBy = $metAt->copy()->startOfDay()->addDays($days);

        $legislature->forceFill([
            'last_met_on'         => $metAt->toDateString(),
            'next_meeting_due_by' => $dueBy->toDateString(),
        ])->save();

        $stale = \App\Models\ClockTimer::query()
            ->armed()
            ->where('clock_id', 'CLK-02')
            ->where('subject_type', 'legislature')
            ->where('subject_id', (string) $legislature->id)
            ->get();

        foreach ($stale as $timer) {
            $this->clocks->cancel($timer, 'chamber met — CLK-02 re-armed from the new meeting');
        }

        $this->clocks->arm(
            'CLK-02',
            (string) $legislature->jurisdiction_id,
            'legislature',
            (string) $legislature->id,
            $dueBy,
            [
                'derive' => [
                    'anchor_at' => $metAt->copy()->startOfDay()->toIso8601String(),
                    'unit'      => 'days',
                ],
            ],
        );
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Locked agenda head: slot-1 emergency items (batch 2 fills the
     * table; until then the head is honestly empty) — Art. II §2 order.
     * Existing locked items keep their addressed state across re-opens.
     */
    private function composeLockedHead(Legislature $legislature, array $existing): array
    {
        $head = array_values(array_filter($existing, fn (array $i) => $i['locked'] ?? false));
        $tail = array_values(array_filter($existing, fn (array $i) => ! ($i['locked'] ?? false)));

        if ($head === [] && Schema::hasTable('emergency_powers')) {
            $powers = DB::table('emergency_powers')
                ->where('legislature_id', $legislature->id)
                ->whereIn('status', ['active', 'renewed', 'under_review'])
                ->whereNull('deleted_at')
                ->get(['id', 'label']);

            foreach ($powers as $power) {
                $head[] = [
                    'slot'     => 1,
                    'kind'     => 'emergency_power',
                    'ref_type' => 'emergency_power',
                    'ref_id'   => (string) $power->id,
                    'title'    => 'Emergency powers review — ' . $power->label,
                    'locked'   => true,
                    'status'   => 'pending',
                ];
            }
        }

        return array_merge($head, $tail);
    }
}

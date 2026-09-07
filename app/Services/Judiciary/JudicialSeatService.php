<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Appointment;
use App\Models\ChamberVote;
use App\Models\JudicialNomination;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Models\Term;
use App\Services\AuditService;
use App\Services\ChamberVoteService;
use App\Services\CivilAppointmentService;
use App\Services\ClockService;
use App\Services\ConstitutionalValidator;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use App\Services\SettingsResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Judicial nomination consent pipeline (PHASE_E_DESIGN_judiciary §B.2/§B.3)
 * — a near-verbatim mirror of BoardGovernorService: nomination →
 * F-LEG-021 consent (cast via F-LEG-004 under `bog_consent` — ordinary
 * majority of ALL serving, the unstated-threshold owner ruling) → seating
 * with a 10-year civil-appointment term (CLK-09 armed through the shared
 * CivilAppointmentService — the ONE Art. II §9 / Art. IV §1 path).
 *
 * Both Art. IV §2 nomination paths (equal-per-constituent, judicial
 * committee) feed this SAME consent pipeline; they differ only in WHO
 * nominates and the equal-count invariant.
 *
 * Nominee eligibility = active jurisdiction association ONLY (Art. I —
 * neutrality is a duty of office, not an eligibility test; the BoG/
 * F-LEG-012 posture verbatim).
 */
class JudicialSeatService
{
    public const CONSENT_VOTE_TYPE = 'bog_consent';

    public function __construct(
        private readonly ChamberVoteService $votes,
        private readonly CivilAppointmentService $civil,
        private readonly PublicRecordService $records,
        private readonly AuditService $audit,
        private readonly SettingsResolver $settings,
        private readonly ClockService $clocks,
        private readonly RoleService $roles,
    ) {}

    // =========================================================================
    // Nomination (§B.2 — both Art. IV §2 paths produce these)
    // =========================================================================

    /**
     * Constituent nomination (Art. IV §2): the constituent's agent nominates
     * a judge onto one of THAT constituent's allocated, vacant seats — the
     * equal-per-constituent invariant binds the allocation. Publishes the
     * dossier and opens the F-LEG-021 consent vote in the chartering chamber.
     *
     * @return array{appointment_id: string, seat_id: string, consent_vote_id: string, nomination_id: string}
     */
    public function nominate(
        JudicialSeat $seat,
        string $nomineeUserId,
        string $nominatingJurisdictionId,
        ?string $nominatedByUserId = null,
        ?string $dossier = null,
    ): array {
        if ($seat->seat_class !== JudicialSeat::CLASS_CONSTITUENT_NOMINATED
            || (string) $seat->nominating_jurisdiction_id !== $nominatingJurisdictionId) {
            throw new ConstitutionalViolation(
                'A constituent nominates only onto ITS OWN allocated seats (Art. IV §2 — equal number by each).',
                'Art. IV §2'
            );
        }

        return $this->openNomination(
            $seat,
            $nomineeUserId,
            JudicialNomination::MODE_CONSTITUENT,
            $nominatingJurisdictionId,
            $nominatedByUserId,
            $dossier,
        );
    }

    /**
     * Judicial-committee nomination (Art. IV §2, for jurisdictions WITHOUT
     * constituents): a committee slate (gated on a passed committee
     * supermajority vote upstream) flows into the SAME consent pipeline.
     *
     * @return array{appointment_id: string, seat_id: string, consent_vote_id: string, nomination_id: string}
     */
    public function committeeNominate(
        JudicialSeat $seat,
        string $nomineeUserId,
        ?string $nominatedByUserId = null,
        ?string $dossier = null,
    ): array {
        if ($seat->seat_class !== JudicialSeat::CLASS_COMMITTEE_NOMINATED) {
            throw new ConstitutionalViolation(
                'Committee nomination fills committee-nominated seats (Art. IV §2).',
                'Art. IV §2'
            );
        }

        return $this->openNomination(
            $seat,
            $nomineeUserId,
            JudicialNomination::MODE_COMMITTEE,
            null,
            $nominatedByUserId,
            $dossier,
        );
    }

    /** @return array{appointment_id: string, seat_id: string, consent_vote_id: string, nomination_id: string} */
    private function openNomination(
        JudicialSeat $seat,
        string $nomineeUserId,
        string $mode,
        ?string $nominatingJurisdictionId,
        ?string $nominatedByUserId,
        ?string $dossier,
    ): array {
        if ($seat->status !== JudicialSeat::STATUS_VACANT) {
            throw new ConstitutionalViolation(
                'A judge is nominated onto a VACANT seat of the court.',
                'Art. IV §2'
            );
        }

        $judiciary = Judiciary::query()->whereKey($seat->judiciary_id)->firstOrFail();
        $legislature = $this->charteringChamber($judiciary);

        // Eligibility = active association ONLY (Art. I — neutrality is a
        // duty of office, never an eligibility test).
        $this->assertNomineeAssociation($nomineeUserId, (string) $judiciary->jurisdiction_id);

        $appointment = Appointment::create([
            'appointable_type' => 'judicial_seats',
            'appointable_id' => (string) $seat->id,
            'nominee_user_id' => $nomineeUserId,
            'nominated_by' => $nominatedByUserId,
            'nominated_via_form' => 'F-LEG-021',
            'status' => Appointment::STATUS_NOMINATED,
        ]);

        $record = $this->records->publish(
            kind: 'other',
            title: sprintf('Judge nominated — court %s, seat %d', (string) $judiciary->id, (int) $seat->seat_number),
            body: $dossier,
            attrs: [
                'actor_user_id' => $nominatedByUserId,
                'jurisdiction_id' => (string) $judiciary->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-LEG-021',
                'subject_type' => 'appointments',
                'subject_id' => (string) $appointment->id,
            ],
        );

        $nomination = JudicialNomination::create([
            'judiciary_id' => (string) $judiciary->id,
            'seat_id' => (string) $seat->id,
            'mode' => $mode,
            'nominating_jurisdiction_id' => $nominatingJurisdictionId,
            'nominee_user_id' => $nomineeUserId,
            'appointment_id' => (string) $appointment->id,
            'dossier_record_id' => (string) $record->id,
            'status' => JudicialNomination::STATUS_NOMINATED,
        ]);

        $seat->forceFill([
            'appointment_id' => (string) $appointment->id,
            'status' => JudicialSeat::STATUS_NOMINATED,
        ])->save();

        // F-LEG-021 IS the consent vote (cast via F-LEG-004 — the form stays
        // unregistered as a handler; the FormRegistry posture). Ordinary
        // majority of ALL serving — the bog_consent threshold.
        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: self::CONSENT_VOTE_TYPE,
            votable: $appointment,
            stage: ChamberVote::STAGE_FLOOR,
        );

        $appointment->forceFill(['consent_vote_id' => (string) $vote->id])->save();

        return [
            'appointment_id' => (string) $appointment->id,
            'seat_id' => (string) $seat->id,
            'consent_vote_id' => (string) $vote->id,
            'nomination_id' => (string) $nomination->id,
        ];
    }

    // =========================================================================
    // Slate consent (SIM BULK PATH ONLY — operator ruling 2026-09-08)
    // =========================================================================
    //
    // A chamber may lawfully consent to a WHOLE bench in one vote, and vote the
    // slate down to take seats one at a time on objection. The sim's consent is
    // unanimous by construction, so a slate vote seats the identical bench a
    // per-seat vote would, but casts once (M members) instead of once per seat
    // (J seats x M members) — the judiciary performance pole. Ordinary play is
    // UNTOUCHED: it keeps nominate()/committeeNominate() + the per-seat consent
    // above. These three methods are called only by the sim's JudiciaryStage.

    /**
     * Stage ONE seat's nomination without opening a consent vote: the same
     * artifacts a nomination writes (appointment, dossier record, Judicial
     * nomination, seat -> nominated), minus the vote. Every check the per-seat
     * path runs holds here — VACANT seat, nominee association (Art. I). The slate
     * is consented once by openSlateConsent()/seatSlateOnAdoption().
     *
     * @return array{appointment_id: string, seat_id: string}
     */
    public function stageSlateNomination(
        JudicialSeat $seat,
        string $nomineeUserId,
        string $mode,
        ?string $nominatingJurisdictionId = null,
    ): array {
        if ($seat->status !== JudicialSeat::STATUS_VACANT) {
            throw new ConstitutionalViolation(
                'A judge is nominated onto a VACANT seat of the court.',
                'Art. IV §2'
            );
        }

        $judiciary = Judiciary::query()->whereKey($seat->judiciary_id)->firstOrFail();
        $legislature = $this->charteringChamber($judiciary);

        // Eligibility = active association ONLY (Art. I), the per-seat posture.
        $this->assertNomineeAssociation($nomineeUserId, (string) $judiciary->jurisdiction_id);

        $appointment = Appointment::create([
            'appointable_type' => 'judicial_seats',
            'appointable_id' => (string) $seat->id,
            'nominee_user_id' => $nomineeUserId,
            'nominated_by' => null,
            'nominated_via_form' => 'F-LEG-021',
            'status' => Appointment::STATUS_NOMINATED,
        ]);

        $record = $this->records->publish(
            kind: 'other',
            title: sprintf('Judge nominated — court %s, seat %d', (string) $judiciary->id, (int) $seat->seat_number),
            body: null,
            attrs: [
                'actor_user_id' => null,
                'jurisdiction_id' => (string) $judiciary->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-LEG-021',
                'subject_type' => 'appointments',
                'subject_id' => (string) $appointment->id,
            ],
        );

        JudicialNomination::create([
            'judiciary_id' => (string) $judiciary->id,
            'seat_id' => (string) $seat->id,
            'mode' => $mode,
            'nominating_jurisdiction_id' => $nominatingJurisdictionId,
            'nominee_user_id' => $nomineeUserId,
            'appointment_id' => (string) $appointment->id,
            'dossier_record_id' => (string) $record->id,
            'status' => JudicialNomination::STATUS_NOMINATED,
        ]);

        $seat->forceFill([
            'appointment_id' => (string) $appointment->id,
            'status' => JudicialSeat::STATUS_NOMINATED,
        ])->save();

        return [
            'appointment_id' => (string) $appointment->id,
            'seat_id' => (string) $seat->id,
        ];
    }

    /**
     * Open ONE consent vote for the whole bench. The votable is the judiciary,
     * whose votable_type ('judiciary') has no close handler, so the vote-close
     * dispatch (ChamberVoteService::dispatchVotableEffects) hits its default
     * no-op — the per-appointment resolveConsentVote is never routed to. The sim
     * seats the slate explicitly on adoption via seatSlateOnAdoption(). Ordinary
     * majority of ALL serving — the same bog_consent threshold the per-seat
     * consent carries.
     */
    public function openSlateConsent(Judiciary $judiciary): ChamberVote
    {
        $legislature = $this->charteringChamber($judiciary);

        $vote = $this->votes->open(
            bodyType: ChamberVote::BODY_LEGISLATURE,
            bodyId: (string) $legislature->id,
            voteType: self::CONSENT_VOTE_TYPE,
            votable: $judiciary,
            stage: ChamberVote::STAGE_FLOOR,
        );

        // Bind every staged appointment to this consent vote — the appointment ->
        // vote link the per-seat path sets via consent_vote_id (openNomination).
        // Without it a slate-seated judge's appointment traces only judiciary ->
        // vote, not appointment -> vote, degrading the certify/review trail. One
        // bulk update over this bench's nominated appointments restores it.
        $appointmentIds = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_NOMINATED)
            ->whereNotNull('appointment_id')
            ->pluck('appointment_id')
            ->all();

        if ($appointmentIds !== []) {
            Appointment::query()
                ->whereIn('id', $appointmentIds)
                ->where('status', Appointment::STATUS_NOMINATED)
                ->update(['consent_vote_id' => (string) $vote->id]);
        }

        return $vote;
    }

    /**
     * On slate-consent adoption, seat EVERY nominated seat of the judiciary
     * through the SAME per-judge seat() pipeline (10-year CLK-09 term,
     * certification record, equal-constituent advance to appointed). Only the
     * consent was bulked; each seating is unchanged. Idempotent: a seat already
     * seated is skipped, so a re-run after a partial seating is safe.
     *
     * @return int judges seated
     */
    public function seatSlateOnAdoption(Judiciary $judiciary): int
    {
        $seats = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', JudicialSeat::STATUS_NOMINATED)
            ->whereNotNull('appointment_id')
            ->get();

        if ($seats->isEmpty()) {
            return 0;
        }

        $appointments = Appointment::query()
            ->whereIn('id', $seats->pluck('appointment_id')->map(fn ($id) => (string) $id)->all())
            ->where('status', Appointment::STATUS_NOMINATED)
            ->get()
            ->keyBy(fn ($a) => (string) $a->id);

        $legislature = $this->charteringChamber($judiciary);
        $starts = CarbonImmutable::now('UTC')->startOfDay();
        $years = $this->settings->resolveInt((string) $judiciary->jurisdiction_id, 'judicial_appointment_years', 10);
        $ends = $starts->addYears($years);
        $now = now();
        $startsOn = $starts->toDateString();
        $endsOn = $ends->toDateString();

        // Build the term rows and certification specs for the whole bench, then
        // bulk them (the per-judge seat() pipeline, in bulk). Mirrors
        // CivilAppointmentService::openCivilTerm (the 10-year civil-appointment
        // term) and seat()'s certification record — only the write shape changes.
        $termRows = [];
        $certSpecs = [];
        $consentedApptIds = [];
        $plan = [];

        foreach ($seats as $seat) {
            $appt = $appointments->get((string) $seat->appointment_id);
            if ($appt === null) {
                continue;   // appointment not NOMINATED (idempotent skip on re-run)
            }
            $termId = (string) Str::uuid();
            $uid = (string) $appt->nominee_user_id;

            $termRows[] = [
                'id'                    => $termId,
                'office_kind'           => 'judicial_seat',
                'office_type'           => 'judicial_seats',
                'office_id'             => (string) $seat->id,
                'holder_user_id'        => $uid,
                'jurisdiction_id'       => (string) $judiciary->jurisdiction_id,
                'legislature_id'        => (string) $legislature->id,
                'term_class'            => Term::CLASS_CIVIL_APPOINTMENT,
                'starts_on'             => $startsOn,
                'ends_on'               => $endsOn,
                'source_appointment_id' => (string) $appt->id,
                'status'                => Term::STATUS_ACTIVE,
                'created_at'            => $now,
                'updated_at'            => $now,
            ];

            $certSpecs[] = [
                'kind'  => 'certification',
                'title' => sprintf('Judge seated - court %s, seat %d', (string) $judiciary->id, (int) $seat->seat_number),
                'body'  => sprintf(
                    'Appointee %s consented by majority of all serving (F-LEG-021) and seated '
                    .'(judicial appointment, %d years - Art. IV Sec 1 / Art. II Sec 9; CLK-09 armed at %s).',
                    $uid, $years, $endsOn
                ),
                'attrs' => [
                    'actor_user_id'   => $uid,
                    'jurisdiction_id' => (string) $judiciary->jurisdiction_id,
                    'legislature_id'  => (string) $legislature->id,
                    'via_form'        => 'F-LEG-021',
                    'subject_type'    => 'judicial_seats',
                    'subject_id'      => (string) $seat->id,
                ],
            ];

            $consentedApptIds[] = (string) $appt->id;
            $plan[] = ['seat_id' => (string) $seat->id, 'appt_id' => (string) $appt->id, 'term_id' => $termId, 'uid' => $uid];
        }

        if ($plan === []) {
            return 0;
        }

        DB::transaction(function () use ($termRows, $plan, $consentedApptIds, $certSpecs, $judiciary, $starts, $ends, $startsOn, $endsOn, $now) {
            // Terms FIRST: judicial_seats.term_id and appointments.term_id both FK
            // to terms; terms.source_appointment_id names the already-staged
            // appointment (satisfied). One bulk insert per 500.
            foreach (array_chunk($termRows, 500) as $chunk) {
                DB::table('terms')->insert($chunk);
            }

            // Per-seat indexed single-row writes + the CLK-09 expiry arm — cheaper
            // than terms/certs, so looped rather than bulk-cased. Role flush is an
            // in-memory cache unset (RoleService::flushUser), so it stays per-user.
            foreach ($plan as $p) {
                DB::table('appointments')->where('id', $p['appt_id'])->update([
                    'status'     => Appointment::STATUS_SEATED,
                    'term_id'    => $p['term_id'],
                    'updated_at' => $now,
                ]);
                DB::table('judicial_seats')->where('id', $p['seat_id'])->update([
                    'user_id'        => $p['uid'],
                    'term_id'        => $p['term_id'],
                    'term_starts_on' => $startsOn,
                    'term_ends_on'   => $endsOn,
                    'status'         => JudicialSeat::STATUS_SEATED,
                    'updated_at'     => $now,
                ]);
                $this->clocks->arm(
                    'CLK-09',
                    (string) $judiciary->jurisdiction_id,
                    'term',
                    $p['term_id'],
                    $ends->startOfDay(),
                    ['step' => 'civil_term_expiry', 'ends_on' => $endsOn],
                );
                $this->roles->flushUser($p['uid']);
            }

            // Bulk: nominations -> consented, and one publishMany for every judge's
            // certification record (was a publish() per judge).
            JudicialNomination::query()
                ->whereIn('appointment_id', $consentedApptIds)
                ->where('status', JudicialNomination::STATUS_NOMINATED)
                ->update(['status' => JudicialNomination::STATUS_CONSENTED, 'updated_at' => $now]);

            $this->records->publishMany($certSpecs);

            // Advance the court to appointed ONCE (equal-per-constituent asserted
            // inside), after the whole bench is seated.
            $this->maybeAdvanceToAppointed($judiciary->refresh());
        });

        return count($plan);
    }

    // =========================================================================
    // Consent close (ChamberActService::resolveConsentVote dispatch)
    // =========================================================================

    /**
     * Adopted consent → seat the judge: judicial_seats `seated`, the
     * 10-year civil-appointment term (CLK-09 armed at expiry via the shared
     * CivilAppointmentService), certification record, R-19 derivable. When
     * every seat is seated AND the equal-constituent invariant holds the
     * court advances creating → appointed (§B.4).
     *
     * @return array<string, mixed>
     */
    public function seat(Appointment $appointment): array
    {
        $seat = JudicialSeat::query()->whereKey($appointment->appointable_id)->lockForUpdate()->firstOrFail();
        $judiciary = Judiciary::query()->whereKey($seat->judiciary_id)->firstOrFail();

        $starts = CarbonImmutable::now('UTC')->startOfDay();
        $years = $this->settings->resolveInt((string) $judiciary->jurisdiction_id, 'judicial_appointment_years', 10);
        $ends = $starts->addYears($years);

        $legislature = $this->charteringChamber($judiciary);

        // 10-year civil-appointment term — the ONE CLK-09 path, lockstep
        // with civil appointments (Art. IV §1 · Art. II §9).
        $term = $this->civil->openCivilTerm(
            officeKind: 'judicial_seat',
            officeType: 'judicial_seats',
            officeId: (string) $seat->id,
            holderUserId: (string) $appointment->nominee_user_id,
            jurisdictionId: (string) $judiciary->jurisdiction_id,
            legislatureId: (string) $legislature->id,
            appointment: $appointment,
            starts: $starts,
            ends: $ends,
        );

        $seat->forceFill([
            'user_id' => (string) $appointment->nominee_user_id,
            'term_id' => (string) $term->id,
            'term_starts_on' => $starts->toDateString(),
            'term_ends_on' => $ends->toDateString(),
            'status' => JudicialSeat::STATUS_SEATED,
        ])->save();

        JudicialNomination::query()
            ->where('appointment_id', (string) $appointment->id)
            ->update(['status' => JudicialNomination::STATUS_CONSENTED, 'updated_at' => now()]);

        $this->records->publish(
            kind: 'certification',
            title: sprintf('Judge seated — court %s, seat %d', (string) $judiciary->id, (int) $seat->seat_number),
            body: sprintf(
                'Appointee %s consented by majority of all serving (F-LEG-021) and seated '
                .'(judicial appointment, %d years — Art. IV §1 · Art. II §9; CLK-09 armed at %s).',
                (string) $appointment->nominee_user_id,
                $years,
                $ends->toDateString()
            ),
            attrs: [
                'actor_user_id' => (string) $appointment->nominee_user_id,
                'jurisdiction_id' => (string) $judiciary->jurisdiction_id,
                'legislature_id' => (string) $legislature->id,
                'via_form' => 'F-LEG-021',
                'subject_type' => 'judicial_seats',
                'subject_id' => (string) $seat->id,
            ],
        );

        $this->roles->flushUser((string) $appointment->nominee_user_id);

        $advanced = $this->maybeAdvanceToAppointed($judiciary->refresh());

        return [
            'appointment_id' => (string) $appointment->id,
            'seat_id' => (string) $seat->id,
            'term_id' => (string) $term->id,
            'appointed' => $advanced,
        ];
    }

    /** Rejected consent → the seat reopens for renomination (the loop). */
    public function handleRejectedNomination(Appointment $appointment): void
    {
        $seat = JudicialSeat::query()->whereKey($appointment->appointable_id)->first();

        if ($seat === null || $seat->status !== JudicialSeat::STATUS_NOMINATED) {
            return;
        }

        JudicialNomination::query()
            ->where('appointment_id', (string) $appointment->id)
            ->where('status', JudicialNomination::STATUS_NOMINATED)
            ->update(['status' => JudicialNomination::STATUS_REJECTED, 'updated_at' => now()]);

        $seat->forceFill(['appointment_id' => null, 'status' => JudicialSeat::STATUS_VACANT])->save();
    }

    /**
     * creating → appointed when EVERY seat is seated AND the equal-constituent
     * invariant holds (the maybeAdvanceToOperating mirror). The court is now
     * live — cases can be filed (the cases agent's entry gate).
     */
    public function maybeAdvanceToAppointed(Judiciary $judiciary): bool
    {
        if ($judiciary->status !== Judiciary::STATUS_CREATING) {
            return false;
        }

        $unseated = JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->where('status', '!=', JudicialSeat::STATUS_SEATED)
            ->exists();

        if ($unseated) {
            return false;
        }

        if ($judiciary->nomination_mode === Judiciary::NOMINATION_CONSTITUENT) {
            ConstitutionalValidator::assertEqualConstituentNomination(
                app(JudiciaryFormationService::class)->seatCountsByConstituent($judiciary)
            );
        }

        $judiciary->forceFill(['status' => Judiciary::STATUS_APPOINTED])->save();

        $this->audit->append(
            module: 'judiciary',
            event: 'judiciary.appointed',
            payload: [
                'judiciary_id' => (string) $judiciary->id,
                'judge_count' => (int) $judiciary->judge_count,
            ],
            ref: 'F-LEG-021',
            jurisdictionId: (string) $judiciary->jurisdiction_id,
        );

        $this->records->publish(
            kind: 'certification',
            title: 'Appointed judiciary is live',
            body: sprintf(
                'Judiciary %s reached `appointed`: every seat consented and the equal-constituent '
                .'invariant holds (Art. IV §1/§2). The court may now hear cases.',
                (string) $judiciary->id
            ),
            attrs: [
                'jurisdiction_id' => (string) $judiciary->jurisdiction_id,
                'via_form' => 'F-LEG-021',
                'subject_type' => 'judiciaries',
                'subject_id' => (string) $judiciary->id,
            ],
        );

        return true;
    }

    // =========================================================================
    // CLK-09 expiry (fired by CivilTermExpiryJob, the governor parallel)
    // =========================================================================

    /** Term expiry → seat term_ended; renomination opens on the record. */
    public function expireJudicialTerm(Term $term): void
    {
        $seat = JudicialSeat::query()
            ->where('term_id', $term->id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->first();

        if ($seat === null) {
            return;
        }

        $judiciary = Judiciary::query()->whereKey($seat->judiciary_id)->firstOrFail();

        if ($term->status === Term::STATUS_ACTIVE) {
            $term->forceFill(['status' => Term::STATUS_COMPLETED])->save();
        }

        $holder = $seat->user_id !== null ? (string) $seat->user_id : null;

        $seat->forceFill(['status' => JudicialSeat::STATUS_TERM_ENDED])->save();

        // The constituent/committee re-nominates into a fresh vacant seat.
        $this->reopenSeat($judiciary, $seat);

        if ($holder !== null) {
            $this->roles->flushUser($holder);
        }

        $this->records->publish(
            kind: 'other',
            title: sprintf('Judge term ended — court %s, seat %d: renomination open', (string) $judiciary->id, (int) $seat->seat_number),
            body: sprintf(
                'The %s judicial appointment reached its expiry (CLK-09). A fresh seat reopens for '
                .'F-LEG-021 nomination and consent.',
                $term->ends_on?->toDateString() ?? ''
            ),
            attrs: [
                'jurisdiction_id' => (string) $judiciary->jurisdiction_id,
                'via_clock' => 'CLK-09',
                'subject_type' => 'judicial_seats',
                'subject_id' => (string) $seat->id,
            ],
        );
    }

    /**
     * Reopen a closed appointed seat for renomination (the §B.3/§B.6 loop):
     * a fresh vacant seat of the SAME class + nominating jurisdiction so the
     * equal-constituent invariant is preserved.
     */
    public function reopenSeat(Judiciary $judiciary, JudicialSeat $closed): JudicialSeat
    {
        return JudicialSeat::create([
            'judiciary_id' => (string) $judiciary->id,
            'seat_number' => $this->nextSeatNumber($judiciary),
            'seat_class' => $closed->seat_class,
            'nominating_jurisdiction_id' => $closed->nominating_jurisdiction_id,
            'status' => JudicialSeat::STATUS_VACANT,
        ]);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function charteringChamber(Judiciary $judiciary): Legislature
    {
        if ($judiciary->source_legislature_id !== null) {
            $legislature = Legislature::query()->find((string) $judiciary->source_legislature_id);

            if ($legislature !== null) {
                return $legislature;
            }
        }

        $legislature = Legislature::query()
            ->where('jurisdiction_id', $judiciary->jurisdiction_id)
            ->first();

        if ($legislature === null) {
            throw new ConstitutionalViolation(
                'No legislature exists to consent — the judicial consent pipeline requires the chartering chamber.',
                'Art. IV §2'
            );
        }

        return $legislature;
    }

    private function nextSeatNumber(Judiciary $judiciary): int
    {
        return (int) JudicialSeat::query()
            ->where('judiciary_id', $judiciary->id)
            ->withTrashed()
            ->max('seat_number') + 1;
    }

    private function assertNomineeAssociation(string $userId, string $jurisdictionId): void
    {
        $associated = DB::table('residency_confirmations')
            ->where('user_id', $userId)
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->exists();

        if (! $associated) {
            throw new ConstitutionalViolation(
                'F-LEG-021 nominee holds no active association with the jurisdiction — association '
                .'is the ONLY eligibility check (Art. I; neutrality is a duty of office).',
                'Art. I'
            );
        }
    }
}

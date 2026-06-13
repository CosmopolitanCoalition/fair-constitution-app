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

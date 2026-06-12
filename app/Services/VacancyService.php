<?php

namespace App\Services;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Support\RaceFootprint;
use App\Jobs\Elections\RunCountbackJob;
use App\Models\Candidacy;
use App\Models\ElectionRace;
use App\Models\LegislatureMember;
use App\Models\Tabulation;
use App\Models\Term;
use App\Models\User;
use App\Models\Vacancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * WI-B6 — ESM-13: vacancy → countback → special election (Art. II §5;
 * design §B.2.6, counting design §A.4/§D "Countback").
 *
 *   declare()      — vacate the seat (member → 'vacated', term status →
 *                    'vacated'; its ends_on is NEVER touched — CLK-10),
 *                    open the vacancy row, queue the countback. Phase B
 *                    declarations are system/dev (`declared_via_form =
 *                    'dev'`); F-LEG-036 arrives in Phase C.
 *   runCountback() — the UNIVERSAL countback: a full deterministic re-run
 *                    of the ORIGINAL race's stored ballots at the original
 *                    seat count, with the candidacies of every member who
 *                    has vacated this race's seats struck ("as if the
 *                    vacating member never ran" — prior vacancies were
 *                    already adjudicated and cannot be re-seated). NO
 *                    other filter of any kind exists — universality is
 *                    structural (q-ledger #q6, pinned by
 *                    CountbackUniversalTest on the counting core).
 *                    Replacements are re-run winners who hold no current
 *                    seat, in re-run election order; eligibility is
 *                    re-checked at certification, not inside the count
 *                    (§A.4.5): a replacement must still hold an active
 *                    association inside the race footprint and must not
 *                    already sit in the chamber.
 *                      → eligible winner: CertificationService::
 *                        certifyCountback seats them with the INHERITED
 *                        original expiry; vacancy 'filled'.
 *                      → none: vacancy 'countback_failed', the CLK-04
 *                        backstop is armed at declared_at +
 *                        special_election_max_days, and the special
 *                        election is AUTO-scheduled inside the
 *                        [declared_at + min, + max] window — discretion
 *                        can never produce "no election".
 */
class VacancyService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly ClockService $clocks,
        private readonly SettingsResolver $settings,
        private readonly ElectionLifecycleService $lifecycle,
        private readonly CertificationService $certification,
        private readonly TabulationRecorder $recorder,
        private readonly VoteCountingService $counter,
        private readonly RoleService $roles,
    ) {
    }

    /**
     * Declare a vacancy on a current legislature seat and queue the
     * countback. `$queueCountback = false` lets the dev command run the
     * countback inline (`vacancy:declare --sync`).
     */
    public function declare(
        LegislatureMember $member,
        string $reason = 'resigned',
        ?User $declaredBy = null,
        string $via = 'dev',
        bool $queueCountback = true,
    ): Vacancy {
        if (! in_array($member->status, LegislatureMember::CURRENT_STATUSES, true)) {
            throw new ConstitutionalViolation(
                "Seat [{$member->id}] is not currently held (status: {$member->status}) — only current seats can be vacated.",
                'Art. II §5'
            );
        }

        $legislature = $member->legislature()->firstOrFail();

        $vacancy = DB::transaction(function () use ($member, $legislature, $reason, $declaredBy, $via): Vacancy {
            $member->forceFill([
                'status'         => LegislatureMember::STATUS_VACATED,
                'vacated_at'     => now(),
                'vacancy_reason' => $reason,
            ])->save();

            // The term row's STATUS moves; its ends_on never does (CLK-10).
            if ($member->term_id !== null) {
                Term::query()
                    ->whereKey($member->term_id)
                    ->where('status', Term::STATUS_ACTIVE)
                    ->update(['status' => Term::STATUS_VACATED, 'updated_at' => now()]);
            }

            $vacancy = Vacancy::create([
                'seat_type'         => 'legislature_members',
                'seat_id'           => $member->id,
                'legislature_id'    => $legislature->id,
                'jurisdiction_id'   => $legislature->jurisdiction_id,
                'declared_by'       => $declaredBy?->getKey(),
                'declared_via_form' => $via,
                'status'            => Vacancy::STATUS_DECLARED,
                'detected_at'       => now(),
                'declared_at'       => now(),
            ]);

            $this->audit->append(
                module: 'elections',
                event: 'vacancy.declared',
                payload: [
                    'vacancy_id'     => (string) $vacancy->id,
                    'seat_type'      => 'legislature_members',
                    'seat_id'        => (string) $member->id,
                    'user_id'        => (string) $member->user_id,
                    'legislature_id' => (string) $legislature->id,
                    'reason'         => $reason,
                    'via'            => $via,
                ],
                ref: 'ESM-13',
                actorId: $declaredBy?->getKey() !== null ? (string) $declaredBy->getKey() : null,
                jurisdictionId: $legislature->jurisdiction_id,
            );

            return $vacancy;
        });

        $this->roles->flushUser((string) $member->user_id);

        if ($queueCountback) {
            RunCountbackJob::dispatch((string) $vacancy->id);
        }

        return $vacancy;
    }

    /**
     * Run the countback for a declared vacancy (see class docblock).
     * Idempotent: only a 'detected'/'declared' vacancy is runnable.
     */
    public function runCountback(Vacancy $vacancy): Vacancy
    {
        if (! in_array($vacancy->status, [Vacancy::STATUS_DETECTED, Vacancy::STATUS_DECLARED], true)) {
            return $vacancy;
        }

        $member = LegislatureMember::query()->find($vacancy->seat_id);
        $race   = $member?->elected_in_race_id !== null
            ? ElectionRace::query()->find($member->elected_in_race_id)
            : null;

        $struckCandidacy = ($race !== null && $member !== null)
            ? Candidacy::query()
                ->where('race_id', $race->id)
                ->where('user_id', $member->user_id)
                ->first()
            : null;

        if ($race === null || $struckCandidacy === null) {
            // No election provenance (e.g. a seat created outside an
            // election) — countback is impossible; straight to the
            // special-election path.
            return $this->failCountback($vacancy, null, 'no original race or candidacy to re-run');
        }

        $vacancy->forceFill(['status' => Vacancy::STATUS_COUNTBACK_RUNNING])->save();

        $struck  = $this->struckCandidacies($race);
        $sitting = $this->sittingCandidacies($race);

        $this->audit->append(
            module: 'elections',
            event: 'vacancy.countback_started',
            payload: [
                'vacancy_id' => (string) $vacancy->id,
                'race_id'    => (string) $race->id,
                'struck'     => $struck,
                'sitting'    => $sitting,
            ],
            ref: 'ESM-13',
            jurisdictionId: $vacancy->jurisdiction_id,
        );

        $tabulation = $this->recorder->begin($race, Tabulation::KIND_COUNTBACK, (string) $struckCandidacy->id);
        $input      = $this->recorder->countInput($race);

        $countback = $this->counter->countback($input, $struck, $sitting);

        $this->recorder->complete($tabulation, $race, $countback->tabulation, updateRace: false, auditExtra: [
            'struck'       => $countback->struck,
            'sitting'      => $countback->sitting,
            'replacements' => $countback->replacements,
        ]);

        $vacancy->forceFill(['countback_tabulation_id' => $tabulation->id])->save();

        $winner = $this->firstEligibleReplacement($countback->replacements, $race, (string) $vacancy->legislature_id);

        if ($winner === null) {
            return $this->failCountback($vacancy, $tabulation, 'ballots exhausted — no eligible new winner');
        }

        $this->certification->certifyCountback($vacancy, $tabulation, $winner);

        return $vacancy->refresh();
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Cumulative strikes: candidacies of every member who held a seat in
     * this race and no longer does (the just-vacated member included —
     * declare() flipped them first). A countback fills THE vacancy; it
     * never re-litigates or re-seats prior departures.
     *
     * @return list<string>
     */
    private function struckCandidacies(ElectionRace $race): array
    {
        $departedUserIds = LegislatureMember::query()
            ->where('elected_in_race_id', $race->id)
            ->whereIn('status', [LegislatureMember::STATUS_VACATED, LegislatureMember::STATUS_REMOVED])
            ->pluck('user_id');

        return Candidacy::query()
            ->where('race_id', $race->id)
            ->whereIn('user_id', $departedUserIds)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** @return list<string> candidacies of the race's currently seated members */
    private function sittingCandidacies(ElectionRace $race): array
    {
        $sittingUserIds = LegislatureMember::query()
            ->where('elected_in_race_id', $race->id)
            ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
            ->pluck('user_id');

        return Candidacy::query()
            ->where('race_id', $race->id)
            ->whereIn('user_id', $sittingUserIds)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * §A.4.5 — eligibility re-check at certification, never inside the
     * count: the replacement must still hold an active association inside
     * the race footprint and must not already hold a current seat in the
     * chamber. Ineligible winners are skipped in re-run election order.
     *
     * @param  list<string>  $replacements
     */
    private function firstEligibleReplacement(array $replacements, ElectionRace $race, string $legislatureId): ?Candidacy
    {
        foreach ($replacements as $candidacyId) {
            $candidacy = Candidacy::query()->find($candidacyId);

            if ($candidacy === null) {
                continue;
            }

            $alreadySeated = LegislatureMember::query()
                ->where('legislature_id', $legislatureId)
                ->where('user_id', $candidacy->user_id)
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->exists();

            if ($alreadySeated) {
                continue;
            }

            if (! RaceFootprint::userInFootprint((string) $candidacy->user_id, $race)) {
                continue;
            }

            return $candidacy;
        }

        return null;
    }

    /**
     * Countback failed → CLK-04 backstop armed at declared_at +
     * special_election_max_days, then the special election is
     * auto-scheduled inside the window (design §B.2.6).
     */
    private function failCountback(Vacancy $vacancy, ?Tabulation $tabulation, string $why): Vacancy
    {
        $declaredAt = CarbonImmutable::parse($vacancy->declared_at ?? $vacancy->detected_at ?? now());

        $minDays = $this->settings->resolveInt($vacancy->jurisdiction_id, 'special_election_min_days', 90);
        $maxDays = $this->settings->resolveInt($vacancy->jurisdiction_id, 'special_election_max_days', 180);

        DB::transaction(function () use ($vacancy, $tabulation, $why, $declaredAt, $minDays, $maxDays) {
            $vacancy->forceFill(['status' => Vacancy::STATUS_COUNTBACK_FAILED])->save();

            $this->audit->append(
                module: 'elections',
                event: 'vacancy.countback_failed',
                payload: [
                    'vacancy_id'    => (string) $vacancy->id,
                    'tabulation_id' => $tabulation !== null ? (string) $tabulation->id : null,
                    'reason'        => $why,
                    'citation'      => 'Art. II §5',
                ],
                ref: 'ESM-13',
                jurisdictionId: $vacancy->jurisdiction_id,
            );

            // The hard backstop: discretion can never produce "no election".
            $this->clocks->arm(
                'CLK-04',
                $vacancy->jurisdiction_id,
                'vacancy',
                (string) $vacancy->id,
                $declaredAt->addDays($maxDays),
                [
                    'step'             => 'special_window_close',
                    'window_opens_at'  => $declaredAt->addDays($minDays)->toIso8601String(),
                    'window_closes_at' => $declaredAt->addDays($maxDays)->toIso8601String(),
                ],
            );
        });

        // Auto-schedule inside the window (its own audited transaction).
        $this->lifecycle->scheduleSpecial($vacancy);

        return $vacancy->refresh();
    }
}

<?php

namespace App\Console\Commands;

use App\Models\LegislatureMember;
use App\Models\Vacancy;
use App\Services\VacancyService;
use Illuminate\Console\Command;

/**
 * WI-B6 dev tool — declare a vacancy on a seated member and drive the
 * ESM-13 machine (countback → fill | special election). Operator-only by
 * construction: artisan is not reachable from the HTTP surface, and the
 * vacancy rides `declared_via_form = 'dev'` so the chain records the
 * provenance honestly. F-LEG-036 (the real declaration form) is Phase C.
 *
 *   php artisan vacancy:declare {member-uuid}
 *   php artisan vacancy:declare --race={race-uuid}     # first current member
 *   php artisan vacancy:declare {member-uuid} --sync   # countback inline
 */
class VacancyDeclareCommand extends Command
{
    protected $signature = 'vacancy:declare
        {member? : legislature_members uuid to vacate}
        {--race= : pick the first current member elected in this race}
        {--reason=resigned : recorded vacancy reason}
        {--sync : run the countback inline instead of queueing it}';

    protected $description = 'Dev: declare a legislature seat vacancy and run the countback (Art. II §5)';

    public function handle(VacancyService $vacancies): int
    {
        $member = $this->resolveMember();

        if ($member === null) {
            return self::FAILURE;
        }

        $this->line("Vacating member <info>{$member->id}</info> (user {$member->user_id}, seat {$member->seat_no}, status {$member->status})");

        $sync = (bool) $this->option('sync');

        $vacancy = $vacancies->declare(
            $member,
            (string) $this->option('reason'),
            declaredBy: null,
            via: 'dev',
            queueCountback: ! $sync,
        );

        if ($sync) {
            $vacancy = $vacancies->runCountback($vacancy);
        }

        $vacancy->refresh();

        $this->line("Vacancy <info>{$vacancy->id}</info> → status <comment>{$vacancy->status}</comment>");

        match ($vacancy->status) {
            Vacancy::STATUS_FILLED =>
                $this->info("Filled by countback: user {$vacancy->filled_by_user_id} (tabulation {$vacancy->countback_tabulation_id})"),
            Vacancy::STATUS_SPECIAL_SCHEDULED =>
                $this->warn("Countback exhausted — special election {$vacancy->special_election_id} scheduled inside the Art. II §5 window."),
            Vacancy::STATUS_DECLARED =>
                $this->line('Countback queued on the long-running queue (run with --sync to drive it inline).'),
            default => $this->line('See the audit chain for the full record.'),
        };

        return self::SUCCESS;
    }

    private function resolveMember(): ?LegislatureMember
    {
        $memberId = $this->argument('member');
        $raceId   = $this->option('race');

        if ($memberId !== null) {
            $member = LegislatureMember::query()->find($memberId);

            if ($member === null) {
                $this->error("No legislature_members row [{$memberId}].");

                return null;
            }

            return $member;
        }

        if ($raceId !== null) {
            $member = LegislatureMember::query()
                ->where('elected_in_race_id', $raceId)
                ->whereIn('status', LegislatureMember::CURRENT_STATUSES)
                ->orderBy('seat_no')
                ->first();

            if ($member === null) {
                $this->error("Race [{$raceId}] has no current members.");

                return null;
            }

            return $member;
        }

        $this->error('Pass a member uuid or --race={race-uuid}.');

        return null;
    }
}

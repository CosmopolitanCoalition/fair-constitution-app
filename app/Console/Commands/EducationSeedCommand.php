<?php

namespace App\Console\Commands;

use App\Services\Education\EducationCatalogService;
use App\Services\Education\SeatedMemberTrainingService;
use Illuminate\Console\Command;

/**
 * education:seed — write the server-side content catalog
 * (config/cga/education.php 'content') into the education tables, then
 * PRE-TRAIN every currently-seated role-holder (operator ruling Option A,
 * Wave 4 §①).
 *
 * ⚠ ARMING WARNING (plan §5.2, the availability precondition): seeding a
 * LIVE module for a civic track key arms the act-gate on this box — every
 * untrained role-holder's next role-authority act will redirect to Learn.
 * That is the ruled behaviour for a played world; for a box whose test
 * fixtures file role-acts with untrained members, sequence this with
 * fixture training. The command says so before doing it.
 *
 * THE PUBLISH → ARM → TRAIN ORDER. Publishing must precede the pre-train
 * pass: F-EDU-001 refuses a completion of a module that is not live yet, so
 * the seated-member backfill runs LAST, over content this same command just
 * made live. That is why "seated members are trained" lives here and not in
 * the seeders that seat them — a seeder on a fresh box has no live module to
 * train against (the backfill no-ops there, harmless). `--no-pretrain` arms
 * without training (a fresh world with no seated members, or a box where the
 * operator wants the redirect demonstrated).
 *
 * Idempotent: upserts on the natural keys; re-running after a config edit
 * revises rows in place, and the pre-train pass skips holders already
 * trained (mints no second achievement/stipend). Content revision through
 * the LIVE plane is F-EDU-002's job — this command is setup tooling, like
 * the other demo seeders. (Δ4 authorship/dedication columns are lane 14's
 * bridge and not on these tables yet; when they land, seeding dedications
 * goes through CgcIpRegisterService::dedicate() and nothing else.)
 */
class EducationSeedCommand extends Command
{
    protected $signature = 'education:seed {--force : skip the arming confirmation} {--no-pretrain : arm the tracks but skip the seated-member pre-train pass}';

    protected $description = 'Seed education tracks/modules/questions from the server-side catalog (ARMS the act-gate) and pre-train seated members';

    public function handle(SeatedMemberTrainingService $pretrain, EducationCatalogService $catalog): int
    {
        $content = config('cga.education.content', []);

        if ($content === []) {
            $this->error('The content catalog is empty — nothing to seed.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Seeding LIVE tracks arms the training act-gate for every untrained role-holder on this box. Proceed?'
        )) {
            return self::FAILURE;
        }

        // Publish through the shared owner so the sim's training phase and this
        // command cannot drift (ruling 10).
        $counts = $catalog->publish();
        $tracks = $counts['tracks'];
        $modules = $counts['modules'];
        $questions = $counts['questions'];

        $this->info("Seeded {$tracks} tracks, {$modules} modules, {$questions} questions. The act-gate is now ARMED for these tracks.");

        if ($this->option('no-pretrain')) {
            $this->warn('Skipped the seated-member pre-train pass (--no-pretrain): untrained seated holders WILL redirect on their next role-act.');

            return self::SUCCESS;
        }

        // Now that the content is live, catch up everyone already seated so a
        // played/demo world is not a wall of redirects (ruling Option A).
        $this->line('Pre-training seated members (a "seated members are trained" pass)…');
        $tally = $pretrain->armSeatedMembers(fn (string $line) => $this->line($line));

        $this->info(sprintf(
            'Pre-train: %d seated holders — %d newly trained, %d already trained, %d unarmed, %d failed.',
            $tally['holders'], $tally['filed'], $tally['already'], $tally['unarmed'], $tally['failed'],
        ));

        return self::SUCCESS;
    }
}

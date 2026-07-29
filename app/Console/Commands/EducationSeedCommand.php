<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * education:seed — write the server-side content catalog
 * (config/cga/education.php 'content') into the education tables.
 *
 * ⚠ ARMING WARNING (plan §5.2, the availability precondition): seeding a
 * LIVE module for a civic track key arms the act-gate on this box — every
 * untrained role-holder's next role-authority act will redirect to Learn.
 * That is the ruled behaviour for a played world; for a box whose test
 * fixtures file role-acts with untrained members, sequence this with
 * fixture training. The command says so before doing it.
 *
 * Idempotent: upserts on the natural keys; re-running after a config edit
 * revises rows in place. Content revision through the LIVE plane is
 * F-EDU-002's job — this command is setup tooling, like the other demo
 * seeders. (Δ4 authorship/dedication columns are lane 14's bridge and not
 * on these tables yet; when they land, seeding dedications goes through
 * CgcIpRegisterService::dedicate() and nothing else.)
 */
class EducationSeedCommand extends Command
{
    protected $signature = 'education:seed {--force : skip the arming confirmation}';

    protected $description = 'Seed education tracks/modules/questions from the server-side catalog (ARMS the act-gate for seeded tracks)';

    public function handle(): int
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

        $tracks = 0;
        $modules = 0;
        $questions = 0;

        foreach ($content as $trackKey => $track) {
            $trackId = DB::table('education_tracks')->where('key', $trackKey)->whereNull('deleted_at')->value('id');

            if ($trackId === null) {
                $trackId = (string) Str::uuid();
                DB::table('education_tracks')->insert([
                    'id' => $trackId, 'key' => $trackKey, 'title' => $track['title'],
                    'unit_ref' => $track['unit_ref'] ?? null, 'status' => 'live',
                    'ordering' => $tracks, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('education_tracks')->where('id', $trackId)
                    ->update(['title' => $track['title'], 'unit_ref' => $track['unit_ref'] ?? null, 'updated_at' => now()]);
            }
            $tracks++;

            foreach ($track['modules'] ?? [] as $mIndex => $module) {
                $moduleId = DB::table('education_modules')
                    ->where('track_id', $trackId)->where('key', $module['key'])->whereNull('deleted_at')->value('id');

                $moduleRow = [
                    'title' => $module['title'], 'surface_id' => $module['surface_id'] ?? null,
                    'minutes' => $module['minutes'] ?? null, 'status' => 'live',
                    'ordering' => $mIndex, 'updated_at' => now(),
                ];

                if ($moduleId === null) {
                    $moduleId = (string) Str::uuid();
                    DB::table('education_modules')->insert($moduleRow + [
                        'id' => $moduleId, 'track_id' => $trackId, 'key' => $module['key'], 'created_at' => now(),
                    ]);
                } else {
                    DB::table('education_modules')->where('id', $moduleId)->update($moduleRow);
                }
                $modules++;

                foreach ($module['questions'] ?? [] as $qIndex => $q) {
                    // The corpus weight rule: max(minutes/5, 3).
                    $weight = max((int) floor(($module['minutes'] ?? 0) / 5), 3);

                    $questionRow = [
                        'prompt' => $q['prompt'],
                        'choices' => json_encode($q['choices']),
                        'correct_keys' => json_encode($q['correct']), // SERVER ONLY — never selected client-ward
                        'weight' => $weight, 'ordering' => $qIndex, 'updated_at' => now(),
                    ];

                    $existing = DB::table('education_questions')
                        ->where('module_id', $moduleId)->where('key', $q['key'])->whereNull('deleted_at')->value('id');

                    if ($existing === null) {
                        DB::table('education_questions')->insert($questionRow + [
                            'id' => (string) Str::uuid(), 'module_id' => $moduleId, 'key' => $q['key'], 'created_at' => now(),
                        ]);
                    } else {
                        DB::table('education_questions')->where('id', $existing)->update($questionRow);
                    }
                    $questions++;
                }

                $this->line("  {$trackKey} / {$module['key']} — seeded");
            }
        }

        $this->info("Seeded {$tracks} tracks, {$modules} modules, {$questions} questions. The act-gate is now ARMED for these tracks.");

        return self::SUCCESS;
    }
}

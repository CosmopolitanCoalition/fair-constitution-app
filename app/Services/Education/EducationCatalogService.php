<?php

namespace App\Services\Education;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PUBLISH the server-side education catalog (config/cga/education.php 'content')
 * into the education tables — the single owner of that upsert, shared by
 * education:seed (the manual door) and the sim's training phase (the automatic
 * door), so the two cannot drift (ruling 10).
 *
 * ⚠ PUBLISHING ARMS THE GATE. A live track with a live module makes
 * TrainingGateService::hasLiveTraining true, so every untrained role-holder's
 * next role-authority act redirects to Learn. The sim publishes only AFTER its
 * governance / judiciary / civics stages have filed (those are gated forms), so
 * arming never blocks them; on a live box education:seed carries the same
 * warning it always has.
 *
 * Idempotent: upserts on the natural keys, so re-publishing after a config edit
 * revises rows in place and never duplicates.
 */
class EducationCatalogService
{
    /** @return array{tracks:int, modules:int, questions:int} */
    public function publish(): array
    {
        $content = config('cga.education.content', []);

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
            }
        }

        return ['tracks' => $tracks, 'modules' => $modules, 'questions' => $questions];
    }
}

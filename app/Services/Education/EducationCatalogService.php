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
                $moduleRow = [
                    'title' => $module['title'], 'surface_id' => $module['surface_id'] ?? null,
                    'minutes' => $module['minutes'] ?? null, 'status' => 'live',
                    'ordering' => $mIndex, 'updated_at' => now(),
                ];

                $moduleId = $this->upsertModule($trackId, $module['key'], $moduleRow)['id'];
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

    /**
     * The MANUAL per-module door (LE-2, F-EDU-002 — operator ruling rubric
     * lesson-publication-shape = A: structural publish). Writes ONE module
     * row through the same natural-key upsert publish() uses, so this class
     * stays the single owner of every education-table write and the two doors
     * cannot drift. Called from inside the ConstitutionalEngine transaction by
     * the F-EDU-002 handler, so the write rolls back with any rejection and,
     * on a demo box, is captured against the demo session by the
     * cga_demo_capture trigger (education_modules is NOT capture-excluded).
     *
     * Lesson PROSE is NEVER written here — it lives in the K-2 source and the
     * generated registry, keyed by surface_id. This writes structure only:
     * title, surface pointer, status, minutes, and the publication stamps.
     *
     *   - publish  first edition, revision_number = 1 (idempotent: a
     *              re-publish of the same key upserts in place at revision 1).
     *   - revise   revision_number = existing + 1; a revise of a key that was
     *              never published is refused (there is nothing to revise).
     *
     * $status 'live' arms the training gate for the track (a live track with a
     * live module makes TrainingGateService::hasLiveTraining true); 'draft'
     * does not. $dedicationRef is the public-domain register reference; it
     * rides the audit record through the handler and is not a module column.
     *
     * @return array<string, mixed> the written module row
     */
    public function publishModule(
        string $moduleKey,
        string $title,
        string $action,
        string $trackKey,
        string $surfaceId,
        ?int $minutes,
        string $status,
        string $actorId,
        ?string $dedicationRef = null,
    ): array {
        $trackId = DB::table('education_tracks')->where('key', $trackKey)->whereNull('deleted_at')->value('id');

        if ($trackId === null) {
            throw new \RuntimeException("Education track '{$trackKey}' does not exist — publish its track before a module.");
        }

        $existing = DB::table('education_modules')
            ->where('track_id', $trackId)->where('key', $moduleKey)->whereNull('deleted_at')
            ->first(['id', 'revision_number']);

        if ($action === 'revise') {
            if ($existing === null) {
                throw new \RuntimeException("Cannot revise '{$moduleKey}': no such published module in track '{$trackKey}'.");
            }
            $revision = (int) ($existing->revision_number ?? 1) + 1;
        } else {
            $revision = 1;
        }

        $fields = [
            'title'           => $title,
            'surface_id'      => $surfaceId,
            'minutes'         => $minutes,
            'status'          => $status,
            'revision_number' => $revision,
            'published_by'    => $actorId,
            'published_at'    => now(),
            'updated_at'      => now(),
        ];

        $moduleId = $this->upsertModule($trackId, $moduleKey, $fields)['id'];

        return (array) DB::table('education_modules')->where('id', $moduleId)->first();
    }

    /**
     * The single module upsert on the (track_id, key) natural key, shared by
     * both doors so no second raw upsert path exists. Insert stamps id/track/
     * key/created_at; update writes exactly the caller's fields.
     *
     * @param  array<string, mixed>  $fields
     * @return array{id: string, created: bool}
     */
    private function upsertModule(string $trackId, string $moduleKey, array $fields): array
    {
        $moduleId = DB::table('education_modules')
            ->where('track_id', $trackId)->where('key', $moduleKey)->whereNull('deleted_at')->value('id');

        if ($moduleId === null) {
            $moduleId = (string) Str::uuid();
            DB::table('education_modules')->insert($fields + [
                'id' => $moduleId, 'track_id' => $trackId, 'key' => $moduleKey, 'created_at' => now(),
            ]);

            return ['id' => $moduleId, 'created' => true];
        }

        DB::table('education_modules')->where('id', $moduleId)->update($fields);

        return ['id' => (string) $moduleId, 'created' => false];
    }
}

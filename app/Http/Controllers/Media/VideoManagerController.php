<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\MediaSurfaceVideo;
use App\Models\MediaVideo;
use App\Models\MediaVideoTrack;
use App\Services\AuditService;
use App\Services\Media\MediaLibraryService;
use App\Support\MediaMeta;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * VideoManagerController (W-0449) — the video manager surface: upload a master,
 * audio and caption tracks, and assign films to surfaces for the Learning
 * Drawer.
 *
 * Reading is OPEN to everyone (read-everywhere): index renders the film list
 * for any viewer. Publication is an OPERATOR tool, temporary during
 * development (operator ruling 2026-09-16): store and assign gate on
 * is_operator ONLY (no role, no demo waiver). A non-operator sees a read-only
 * preview and is refused 403 on a write.
 *
 * A film lives on disk under the library root (the Subjects folder) exactly
 * like the website and E:\Subjects: <root>/<Subject>/<Subject>-Silent.mp4, an
 * audio track <root>/<Subject>/audio/<Subject>-<Name>.m4a, a caption
 * <root>/<Subject>/captions/<Subject>-<Name>.vtt, where <Name> is the language
 * English name (the media filename token). An upload writes those canonical
 * names and upserts media_videos / media_video_tracks so MediaMeta::all()
 * carries the film beside the generated registry (a DB row with the same id
 * wins). Assignment writes media_surface_videos (one film per surface).
 */
class VideoManagerController extends Controller
{
    public function index(Request $request): Response
    {
        $library = app(MediaLibraryService::class);
        $canManage = (bool) $request->user()?->is_operator;

        // The per-surface assignments, video_id -> [surface ids]. Schema-guarded
        // so a fresh box (no media table) renders an empty map, never an error.
        $assignments = [];
        if (Schema::hasTable('media_surface_videos')) {
            foreach (MediaSurfaceVideo::query()->get(['surface_id', 'video_id']) as $row) {
                $assignments[$row->video_id][] = $row->surface_id;
            }
        }

        $videos = array_map(static function (array $v) use ($assignments): array {
            return [
                'id'        => $v['id'],
                'title'     => $v['title'],
                'title_key' => $v['title_key'] ?? null,
                'subject'   => $v['subject'],
                'source'    => $v['source'] ?? 'registry',
                'available' => (bool) ($v['available'] ?? false),
                'audio'     => count($v['audio'] ?? []),
                'captions'  => count($v['captions'] ?? []),
                'assigned'  => $assignments[$v['id']] ?? [],
            ];
        }, MediaMeta::all());

        // Every language row as a flat list for the per-file language selects.
        $languages = [];
        foreach (MediaMeta::languages() as $code => $meta) {
            $languages[] = [
                'code'   => $code,
                'name'   => $meta['name'] ?? $code,
                'native' => $meta['native'] ?? ($meta['name'] ?? $code),
            ];
        }

        // Every registered surface (id + title) for the assignment picker.
        $surfaces = [];
        foreach ((array) config('cga.surfaces', []) as $id => $record) {
            if (! is_array($record)) {
                continue;
            }
            $surfaces[] = ['id' => $id, 'title' => $record['title'] ?? $id];
        }

        return Inertia::render('Learn/VideoManager', [
            'surface'     => SurfaceMeta::for('learn/video-manager'),
            'can'         => ['manage' => $canManage],
            'videos'      => $videos,
            'languages'   => $languages,
            'surfaces'    => $surfaces,
            'baseUrl'     => MediaMeta::baseUrl(),
            'libraryRoot' => $library->root(),
        ]);
    }

    /**
     * Upload a master and/or tracks for a film and, optionally, assign it to
     * surfaces. Operator-only. Files land under the library root with the
     * canonical names; the catalog rows are upserted.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeOperator($request);

        $validated = $request->validate([
            'title'           => ['required', 'string', 'max:160'],
            'subject'         => ['nullable', 'string', 'max:160'],
            'summary'         => ['nullable', 'string', 'max:2000'],
            'master'          => ['nullable', 'file', 'mimetypes:video/mp4'],
            'replace_master'  => ['nullable', 'boolean'],
            'audio'           => ['nullable', 'array'],
            'audio.*'         => ['file', 'mimetypes:audio/mp4,video/mp4,audio/x-m4a'],
            'audio_lang'      => ['nullable', 'array'],
            'audio_lang.*'    => ['nullable', 'string', 'max:32'],
            'captions'        => ['nullable', 'array'],
            'captions.*'      => ['file', 'mimetypes:text/vtt,text/plain'],
            'captions_lang'   => ['nullable', 'array'],
            'captions_lang.*' => ['nullable', 'string', 'max:32'],
            'surfaces'        => ['nullable', 'array'],
            'surfaces.*'      => ['string', Rule::in(SurfaceMeta::ids())],
        ]);

        $library = app(MediaLibraryService::class);
        $langs = MediaMeta::languages();

        $title = trim($validated['title']);
        $subject = $this->deriveSubject((string) ($validated['subject'] ?? ''), $title);
        if ($subject === '') {
            throw ValidationException::withMessages([
                'subject' => 'The subject folder is empty after removing path separators. Enter a name with letters or digits.',
            ]);
        }

        $slug = Str::slug($subject);
        if ($slug === '') {
            throw ValidationException::withMessages([
                'subject' => 'The subject produces an empty slug. Use letters or digits.',
            ]);
        }
        $id = 'v-'.$slug;

        $masterName = $subject.'-Silent.mp4';
        $masterRel = $subject.'/'.$masterName;
        $masterAbs = $library->destAbs($masterRel);
        $masterFile = $request->file('master');
        $replaceMaster = (bool) ($validated['replace_master'] ?? false);
        $masterOnDisk = is_file($masterAbs) && (int) @filesize($masterAbs) > 0;

        // The master is required only when the subject folder has no master yet.
        if ($masterFile === null && ! $masterOnDisk) {
            throw ValidationException::withMessages([
                'master' => 'A master film (.mp4) is required: this subject folder has no master yet.',
            ]);
        }
        if ($masterFile !== null && $masterOnDisk && ! $replaceMaster) {
            throw ValidationException::withMessages([
                'master' => 'A master already exists for this subject. Tick "replace the master" to overwrite it.',
            ]);
        }

        // Resolve every track's language BEFORE writing any file, so an
        // unresolvable filename rejects the whole upload with a clear message
        // and never leaves half the tracks on disk.
        $audioTracks = $this->resolveTracks(
            $this->files($request, 'audio'),
            (array) ($validated['audio_lang'] ?? []),
            $langs,
            'audio',
        );
        $captionTracks = $this->resolveTracks(
            $this->files($request, 'captions'),
            (array) ($validated['captions_lang'] ?? []),
            $langs,
            'captions',
        );

        // Write files. The master first, then the tracks into their subfolders.
        if ($masterFile !== null) {
            $library->ensureDir($library->destAbs($subject));
            $masterFile->move($library->destAbs($subject), $masterName);
        }

        if ($audioTracks !== []) {
            $audioDir = $library->destAbs($subject.'/audio');
            $library->ensureDir($audioDir);
            foreach ($audioTracks as $track) {
                $track['file']->move($audioDir, $subject.'-'.$track['name'].'.m4a');
            }
        }

        if ($captionTracks !== []) {
            $capDir = $library->destAbs($subject.'/captions');
            $library->ensureDir($capDir);
            foreach ($captionTracks as $track) {
                $track['file']->move($capDir, $subject.'-'.$track['name'].'.vtt');
            }
        }

        // Upsert the catalog rows.
        MediaVideo::query()->updateOrCreate(
            ['id' => $id],
            [
                'subject'    => $subject,
                'slug'       => $slug,
                'master'     => $masterName,
                'title'      => $title,
                'summary'    => $validated['summary'] ?? null,
                'poster'     => 'learn',
                'source'     => MediaVideo::SOURCE,
                'created_by' => $request->user()?->id,
            ],
        );

        foreach ($audioTracks as $track) {
            MediaVideoTrack::query()->updateOrCreate(
                ['video_id' => $id, 'kind' => 'audio', 'code' => $track['code']],
                ['name' => $track['name']],
            );
        }
        foreach ($captionTracks as $track) {
            MediaVideoTrack::query()->updateOrCreate(
                ['video_id' => $id, 'kind' => 'captions', 'code' => $track['code']],
                ['name' => $track['name']],
            );
        }

        $surfaces = array_values(array_unique((array) ($validated['surfaces'] ?? [])));
        if ($surfaces !== []) {
            $this->setAssignments($id, $surfaces, $request->user()?->id);
        }

        $this->audit($request, 'video.published', [
            'id'       => $id,
            'subject'  => $subject,
            'master'   => $masterFile !== null,
            'audio'    => count($audioTracks),
            'captions' => count($captionTracks),
            'surfaces' => $surfaces,
        ], $id);

        // A new master changes availability; drop the cached present() probe so
        // the next render reports it honestly.
        Cache::forget('cga:media:present');

        return back()->with('status', 'video-published');
    }

    /**
     * Assign a film (registry or uploaded) to a set of surfaces, or clear its
     * assignments. Operator-only. Replaces the film's full surface set.
     */
    public function assign(Request $request): RedirectResponse
    {
        $this->authorizeOperator($request);

        $validated = $request->validate([
            'video_id'   => ['required', 'string', Rule::in(MediaMeta::ids())],
            'surfaces'   => ['nullable', 'array'],
            'surfaces.*' => ['string', Rule::in(SurfaceMeta::ids())],
            'clear'      => ['nullable', 'boolean'],
        ]);

        $videoId = $validated['video_id'];
        $surfaces = array_values(array_unique((array) ($validated['surfaces'] ?? [])));
        $clear = (bool) ($validated['clear'] ?? false);

        if ($clear && $surfaces === []) {
            MediaSurfaceVideo::query()->where('video_id', $videoId)->delete();
        } else {
            $this->setAssignments($videoId, $surfaces, $request->user()?->id);
        }

        $this->audit($request, 'video.assigned', [
            'video_id' => $videoId,
            'surfaces' => $surfaces,
            'clear'    => $clear,
        ], $videoId);

        return back()->with('status', 'video-assigned');
    }

    // ----------------------------------------------------------------------

    /** Refuse a non-operator with 403 (the operator-only write gate). */
    private function authorizeOperator(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_operator, 403);
    }

    /**
     * The subject folder name. The operator's own value wins; otherwise it is
     * derived from the title. Path separators are stripped and only letters,
     * digits, spaces and hyphens are kept, so the value can never escape the
     * library root.
     */
    private function deriveSubject(string $subject, string $title): string
    {
        $raw = trim($subject) !== '' ? $subject : $title;

        return $this->sanitizeSubject($raw);
    }

    private function sanitizeSubject(string $raw): string
    {
        $s = str_replace(['/', '\\'], ' ', $raw);
        $s = preg_replace('/[^\p{L}\p{N} \-]+/u', '', $s) ?? '';
        $s = preg_replace('/\s+/u', ' ', $s) ?? '';

        return trim($s);
    }

    /**
     * The uploaded files for a field as a flat, index-keyed list (empty when
     * the field is absent). $request->file() returns an array for name[].
     *
     * @return array<int, UploadedFile>
     */
    private function files(Request $request, string $field): array
    {
        $files = $request->file($field);
        if ($files === null) {
            return [];
        }

        return array_values(is_array($files) ? $files : [$files]);
    }

    /**
     * Resolve each file's language to {file, code, name}. An explicit BCP-47
     * code (from <field>_lang[i]) wins; otherwise the language is parsed from
     * the filename suffix -<English name> against the languages table (longest
     * name wins). An unresolvable file rejects the whole upload.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, string|null>  $codes
     * @param  array<string, array<string, mixed>>  $langs
     * @return list<array{file: UploadedFile, code: string, name: string}>
     */
    private function resolveTracks(array $files, array $codes, array $langs, string $kind): array
    {
        $out = [];
        $seen = [];

        foreach ($files as $i => $file) {
            $explicit = isset($codes[$i]) ? trim((string) $codes[$i]) : '';
            $resolved = $this->resolveLang($file, $explicit, $langs);

            if ($resolved === null) {
                throw ValidationException::withMessages([
                    $kind.'.'.$i => sprintf(
                        'The %s language for "%s" could not be resolved. Choose a language, or name the file "<subject>-<English name>%s".',
                        $kind,
                        $file->getClientOriginalName(),
                        $kind === 'audio' ? '.m4a' : '.vtt',
                    ),
                ]);
            }

            // One track per language per kind. A second file for a language
            // already chosen in this upload would silently overwrite the first
            // on disk and collapse to one row, so reject it with a clear message.
            if (isset($seen[$resolved['code']])) {
                throw ValidationException::withMessages([
                    $kind.'.'.$i => sprintf(
                        'The %s file "%s" repeats the %s language already chosen in this upload. Upload one file per language.',
                        $kind,
                        $file->getClientOriginalName(),
                        $resolved['name'],
                    ),
                ]);
            }
            $seen[$resolved['code']] = true;

            $out[] = ['file' => $file, 'code' => $resolved['code'], 'name' => $resolved['name']];
        }

        return $out;
    }

    /**
     * Resolve one file's language.
     *
     * @param  array<string, array<string, mixed>>  $langs
     * @return array{code: string, name: string}|null
     */
    private function resolveLang(UploadedFile $file, string $explicit, array $langs): ?array
    {
        if ($explicit !== '') {
            if (isset($langs[$explicit])) {
                return ['code' => $explicit, 'name' => (string) ($langs[$explicit]['name'] ?? $explicit)];
            }

            return null; // an unknown code is a clear rejection, never a guess
        }

        $base = mb_strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        $best = null;
        $bestLen = -1;
        foreach ($langs as $code => $meta) {
            $name = (string) ($meta['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $lower = mb_strtolower($name);
            $matches = $base === $lower || str_ends_with($base, '-'.$lower);
            if ($matches && mb_strlen($name) > $bestLen) {
                $best = ['code' => (string) $code, 'name' => $name];
                $bestLen = mb_strlen($name);
            }
        }

        return $best;
    }

    /**
     * Set a film's surface assignments to exactly $surfaces (one film per
     * surface; a surface already pointing at a different film is repointed).
     * Rows for this film that are no longer selected are removed.
     *
     * @param  list<string>  $surfaces
     */
    private function setAssignments(string $videoId, array $surfaces, ?string $actorId): void
    {
        DB::transaction(function () use ($videoId, $surfaces, $actorId): void {
            MediaSurfaceVideo::query()
                ->where('video_id', $videoId)
                ->whereNotIn('surface_id', $surfaces === [] ? ['\0none'] : $surfaces)
                ->delete();

            foreach ($surfaces as $surfaceId) {
                MediaSurfaceVideo::query()->updateOrCreate(
                    ['surface_id' => $surfaceId],
                    ['video_id' => $videoId, 'assigned_by' => $actorId],
                );
            }
        });
    }

    /**
     * Record an audit entry when the hash-chained log exists (Postgres). It is
     * best-effort: the sqlite fixture has no audit_log, and the append needs a
     * Postgres advisory lock, so both the table check and a try/catch guard it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function audit(Request $request, string $event, array $payload, ?string $ref): void
    {
        if (! Schema::hasTable('audit_log')) {
            return;
        }

        try {
            app(AuditService::class)->append(
                'education',
                $event,
                $payload,
                $ref,
                $request->user()?->id !== null ? (string) $request->user()->id : null,
            );
        } catch (\Throwable) {
            // The upload succeeded; a non-Postgres audit sink is not a failure.
        }
    }
}

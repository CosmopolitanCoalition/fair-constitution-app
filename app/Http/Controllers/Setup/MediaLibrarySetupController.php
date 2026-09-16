<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Models\MediaPull;
use App\Models\MediaPullItem;
use App\Services\Media\MediaLibraryService;
use App\Services\Media\MediaPullPlanner;
use App\Support\EnvFile;
use App\Support\MediaMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * MediaLibrarySetupController (W-0448) — the Step-2 video library ingestion
 * endpoints: status poll (public read), start a pull (website download or
 * local-folder copy), halt / resume / retry control, and write the local
 * library path to .env.
 *
 * status is a public GET (reading is open, access policy 2026-09-10). start /
 * control / savePath are auth-gated on the route AND operator-only in the
 * handler, the same posture as SetupController::saveArchivePath — the refusal
 * lands before any read. Every control SEIZES (escape-hatch law): halt, resume
 * and retry_failed act on the live run whatever state it is in, never blocked
 * by that state.
 */
class MediaLibrarySetupController extends Controller
{
    /** The website-quality total for all 61 films (video + audio + captions). */
    private const LIBRARY_SIZE_ESTIMATE_BYTES = 58_834_000_000;

    /** The constant container path the library is mounted at (docker-compose). */
    private const MOUNT = '/var/www/html/public/media/Subjects';

    public function status(Request $request, MediaLibraryService $library): JsonResponse
    {
        // The sweep stats every expected file (~9,500 on the full library) and
        // the page polls every 2 s, so the inventory is cached for 10 s. A
        // finished item shows within one cache window.
        $inventory = Cache::remember('cga:media:inventory', 10, static fn (): array => $library->inventory());

        $subjectsTotal = count($inventory);
        $withMaster = 0;
        $audio = 0;
        $captions = 0;
        $expectedAudio = 0;
        $expectedCaptions = 0;
        foreach ($inventory as $row) {
            if ($row['master']) {
                $withMaster++;
            }
            $audio += (int) $row['audio'];
            $captions += (int) $row['captions'];
            $expectedAudio += (int) $row['expected_audio'];
            $expectedCaptions += (int) $row['expected_captions'];
        }

        return response()->json([
            'root'      => $library->root(),
            'mount'     => self::MOUNT,
            'env_path'  => EnvFile::read('MEDIA_DIR'),
            'present'   => $library->present(),
            'base_url'  => MediaMeta::baseUrl(),
            'inventory' => [
                'subjects_total'       => $subjectsTotal,
                'subjects_with_master' => $withMaster,
                'audio_tracks'         => $audio,
                'caption_tracks'       => $captions,
                'expected'             => [
                    'subjects' => $subjectsTotal,
                    'audio'    => $expectedAudio,
                    'captions' => $expectedCaptions,
                ],
            ],
            // The catalog film list for the subject picker: subject folder + title.
            'films' => array_map(
                static fn (array $v): array => [
                    'subject' => (string) ($v['subject'] ?? ''),
                    'title'   => (string) ($v['title'] ?? $v['subject'] ?? ''),
                ],
                MediaMeta::catalog()
            ),
            'library_size_estimate_bytes' => self::LIBRARY_SIZE_ESTIMATE_BYTES,
            'pull' => $this->pullSummary($this->latestPull()),
        ]);
    }

    public function start(Request $request, MediaPullPlanner $planner, MediaLibraryService $library): JsonResponse
    {
        // Operator-only write (the route is auth-gated). Refuse before any read.
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'source'     => ['required', Rule::in(['web', 'folder'])],
            'from'       => ['nullable', 'string', 'max:1024'],
            'subjects'   => ['nullable', 'array'],
            'subjects.*' => ['string', 'max:200'],
        ]);

        if ($data['source'] === 'folder' && empty($data['from'])) {
            return response()->json(['error' => __('A folder pull needs a source folder path.')], 422);
        }

        if ($planner->activePull() !== null) {
            return response()->json(['error' => __('A media pull is already running. Halt it first.')], 409);
        }

        $sourceRef = $data['source'] === 'folder'
            ? EnvFile::normalizePath((string) $data['from'])
            : $library->websiteBase();

        $subjects = ! empty($data['subjects']) ? array_values($data['subjects']) : null;

        $pull = $planner->create(
            source: $data['source'],
            subjects: $subjects,
            kinds: ['master', 'audio', 'captions'],
            sourceRef: $sourceRef,
            initiatorUserId: $request->user()?->id,
            options: ['via' => 'setup'],
        );

        $dispatched = $planner->dispatchPending($pull);

        return response()->json([
            'ok'         => true,
            'dispatched' => $dispatched,
            'pull'       => $this->pullSummary($pull->fresh()),
        ]);
    }

    public function control(Request $request, MediaPullPlanner $planner): JsonResponse
    {
        // Operator-only write (the route is auth-gated). Refuse before any read.
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'action' => ['required', Rule::in(['halt', 'resume', 'retry_failed'])],
        ]);

        // Every control SEIZES (escape-hatch law): it acts on the live run in
        // whatever state it is, never blocked by that state.
        if ($data['action'] === 'halt') {
            $pull = MediaPull::query()->where('status', 'running')->orderByDesc('created_at')->first();
            if ($pull !== null) {
                $pull->forceFill(['status' => 'halted', 'updated_at' => now()])->save();
            }

            return response()->json(['ok' => true, 'pull' => $this->pullSummary($this->latestPull())]);
        }

        if ($data['action'] === 'resume') {
            $pull = MediaPull::query()->where('status', 'halted')->orderByDesc('created_at')->first();
            if ($pull === null) {
                return response()->json(['ok' => true, 'pull' => $this->pullSummary($this->latestPull())]);
            }
            $pull->forceFill(['status' => 'running', 'finished_at' => null, 'updated_at' => now()])->save();
            $dispatched = $planner->dispatchPending($pull);

            return response()->json(['ok' => true, 'dispatched' => $dispatched, 'pull' => $this->pullSummary($pull->fresh())]);
        }

        // retry_failed: reset failed items to pending, clear the counter, re-run.
        $pull = $this->latestPull();
        if ($pull === null) {
            return response()->json(['ok' => true, 'pull' => null]);
        }
        $reset = DB::table('media_pull_items')
            ->where('pull_id', $pull->id)
            ->where('status', 'failed')
            ->update(['status' => 'pending', 'error' => null, 'updated_at' => now()]);
        if ($reset > 0) {
            $pull->forceFill([
                'status'       => 'running',
                'items_failed' => 0,
                'finished_at'  => null,
                'updated_at'   => now(),
            ])->save();
        }
        $dispatched = $planner->dispatchPending($pull);

        return response()->json([
            'ok'         => true,
            'reset'      => $reset,
            'dispatched' => $dispatched,
            'pull'       => $this->pullSummary($pull->fresh()),
        ]);
    }

    public function savePath(Request $request): JsonResponse
    {
        // Operator-only write (the route is auth-gated). Refuse before any read.
        abort_unless((bool) $request->user()?->is_operator, 403);

        $data = $request->validate([
            'media_dir'        => ['nullable', 'string', 'max:1024'],
            'media_source_dir' => ['nullable', 'string', 'max:1024'],
        ]);

        $kv = [];
        if (! empty($data['media_dir'])) {
            $kv['MEDIA_DIR'] = EnvFile::normalizePath($data['media_dir']);
        }
        if (! empty($data['media_source_dir'])) {
            $kv['MEDIA_SOURCE_DIR'] = EnvFile::normalizePath($data['media_source_dir']);
        }
        if ($kv === []) {
            return response()->json(['error' => __('Provide at least one folder path.')], 422);
        }

        try {
            EnvFile::write($kv);
        } catch (\Throwable $e) {
            return response()->json(['error' => __('Could not write .env: :error', ['error' => $e->getMessage()])], 500);
        }

        return response()->json([
            'saved'            => $kv,
            'restart_required' => true,
            'command'          => 'docker compose up -d',
            'message'          => __('Saved. To apply it, re-run the start script from the app folder ("./get-started.sh --reconfigure" or ".\\get-started.ps1 -Reconfigure"), or run "docker compose up -d" directly — both RECREATE the containers so they pick up your folder. A plain stop/start or restart is NOT enough. Then click "Re-check".'),
        ]);
    }

    private function latestPull(): ?MediaPull
    {
        if (! Schema::hasTable('media_pulls')) {
            return null;
        }

        return MediaPull::query()->orderByDesc('created_at')->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pullSummary(?MediaPull $pull): ?array
    {
        if ($pull === null) {
            return null;
        }

        $current = MediaPullItem::query()
            ->where('pull_id', $pull->id)
            ->where('status', 'running')
            ->limit(5)
            ->get(['subject', 'kind', 'track_name', 'bytes_done', 'bytes_expected'])
            ->map(static fn ($i): array => [
                'subject'        => $i->subject,
                'kind'           => $i->kind,
                'track_name'     => $i->track_name,
                'bytes_done'     => (int) $i->bytes_done,
                'bytes_expected' => $i->bytes_expected !== null ? (int) $i->bytes_expected : null,
            ])
            ->all();

        // Live bytes: completed total plus the partial bytes of running items.
        $runningPartial = (int) MediaPullItem::query()
            ->where('pull_id', $pull->id)
            ->where('status', 'running')
            ->sum('bytes_done');

        return [
            'id'           => $pull->id,
            'source'       => $pull->source,
            'status'       => $pull->status,
            'items_total'  => (int) $pull->items_total,
            'items_done'   => (int) $pull->items_done,
            'items_failed' => (int) $pull->items_failed,
            'bytes_total'  => (int) $pull->bytes_total,
            'bytes_done'   => (int) $pull->bytes_done + $runningPartial,
            'started_at'   => optional($pull->started_at)->toIso8601String(),
            'finished_at'  => optional($pull->finished_at)->toIso8601String(),
            'error'        => $pull->error,
            'current'      => $current,
        ];
    }
}

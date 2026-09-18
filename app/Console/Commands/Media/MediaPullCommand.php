<?php

namespace App\Console\Commands\Media;

use App\Models\MediaPull;
use App\Services\Media\MediaLibraryService;
use App\Services\Media\MediaPullPlanner;
use App\Services\Media\MediaTransfer;
use Illuminate\Console\Command;

/**
 * media:pull (W-0448) — fill the local video library from the website or a
 * local folder. Same run/item engine the Step-2 dashboard drives, so a
 * headless box and the wizard produce identical runs (MediaPullPlanner is the
 * single owner).
 *
 * ETL paradigm: items are inserted in bounded chunks and dispatched two-ended
 * (a master and a caption both start at once); --sync runs them inline with a
 * per-item progress line for a box with no Horizon; a kill mid-run costs one
 * item; --halt seizes a live run at its next committed boundary; --status
 * prints totals.
 *
 *   php artisan media:pull                         all 61 films, website download, queued
 *   php artisan media:pull --sync                  inline (no Horizon)
 *   php artisan media:pull --source=folder --from=/media-source   the E-box local copy
 *   php artisan media:pull --subjects="A,B"        only these subject folders
 *   php artisan media:pull --status                print the latest run's totals
 *   php artisan media:pull --halt                  halt the live run
 *   php artisan media:pull --resume [--sync]       continue the halted run (its pending items); on a
 *                                                  running run, return its stalled items to the pool
 *   php artisan media:pull --requeue-running       return EVERY running item to the pool and re-run it
 *                                                  (the lanes are known dead: a Horizon restart or a kill)
 *   php artisan media:pull --retry-failed [--sync] reset the latest run's failed items and re-run
 *
 * A halted run is never refused silently (WoS 2026-09-17): without --resume
 * the command names it and says how to continue.
 *
 * A lane killed mid-item cannot report, so its item stays `running` (WoS demo
 * box 2026-09-17: seven masters, the run never closed). --resume and
 * --retry-failed return such stale claims to the pool first
 * (MediaPullPlanner::reclaimStale, window = MediaTransfer::staleAfterSeconds);
 * --status names them; --requeue-running seizes every running item at once.
 */
class MediaPullCommand extends Command
{
    protected $signature = 'media:pull
        {--source=web : web | folder}
        {--from= : the folder root when --source=folder (e.g. /media-source)}
        {--subjects= : comma-separated subject folders (default: all)}
        {--kinds=master,audio,captions : which file kinds to fetch}
        {--sync : run items inline instead of queueing (no Horizon)}
        {--halt : halt the live run and exit}
        {--resume : continue the latest halted run, or heal the running one: stalled items return to the pool (with --sync: inline)}
        {--requeue-running : return EVERY running item of the live run to the pool and re-run it (use when the lanes are dead)}
        {--retry-failed : reset the failed items of the latest run to pending and re-run (with --sync: inline)}
        {--status : print the latest run status and exit}';

    protected $description = 'Fill the local video library from the website or a local folder (W-0448)';

    public function handle(MediaLibraryService $library, MediaPullPlanner $planner, MediaTransfer $transfer): int
    {
        if ($this->option('status')) {
            return $this->printStatus();
        }

        if ($this->option('halt')) {
            return $this->haltRun();
        }

        if ($this->option('resume') || $this->option('requeue-running')) {
            return $this->resumeRun($planner, $transfer, force: (bool) $this->option('requeue-running'));
        }

        if ($this->option('retry-failed')) {
            return $this->retryFailedRun($planner, $transfer);
        }

        $source = (string) $this->option('source');
        if (! in_array($source, ['web', 'folder'], true)) {
            $this->error('--source must be web or folder.');

            return self::INVALID;
        }

        $from = $this->option('from') !== null ? (string) $this->option('from') : null;
        if ($source === 'folder' && ($from === null || $from === '')) {
            $this->error('--source=folder needs --from=<folder root>.');

            return self::INVALID;
        }

        if (($active = $planner->activePull()) !== null) {
            $this->error($active->status === 'halted'
                ? 'Run '.substr($active->id, 0, 8).' is halted. Continue it with media:pull --resume (add --sync to run inline), or inspect it with --status.'
                : 'A media pull is already running. Use --status, or --halt first.');

            return self::FAILURE;
        }

        $subjects = $this->parseList((string) ($this->option('subjects') ?? ''));
        $kinds = $this->parseList((string) $this->option('kinds')) ?: ['master', 'audio', 'captions'];

        $sourceRef = $source === 'folder' ? $from : $library->websiteBase();

        $pull = $planner->create(
            source: $source,
            subjects: $subjects,
            kinds: $kinds,
            sourceRef: $sourceRef,
            options: ['cli' => true],
        );

        $pending = $pull->items_total - $pull->items_done;
        $this->info(sprintf(
            'Run %s: %d item(s), %d already present, %d to fetch.',
            substr($pull->id, 0, 8), $pull->items_total, $pull->items_done, $pending
        ));

        if ($pending === 0) {
            $this->info('Nothing to do — the library is complete for this selection.');

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $planner->runSync($pull, $transfer, function ($item, array $result, int $i, int $total): void {
                $this->line(sprintf(
                    '[%d/%d] %s %s/%s -> %s',
                    $i, $total, str_pad($result['status'], 7),
                    $item->subject, $item->kind,
                    isset($result['error']) ? $result['error'] : $this->humanBytes((int) ($result['bytes'] ?? 0))
                ));
            });
            $fresh = MediaPull::query()->find($pull->id);
            $this->info(sprintf(
                'Done: %d fetched, %d failed, status %s.',
                (int) $fresh->items_done, (int) $fresh->items_failed, (string) $fresh->status
            ));

            return ($fresh->items_failed ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
        }

        $n = $planner->dispatchPending($pull);
        $this->info(sprintf('Dispatched %d job(s) to the long-running queue. Poll with media:pull --status.', $n));

        return self::SUCCESS;
    }

    private function printStatus(): int
    {
        $pull = MediaPull::query()->orderByDesc('created_at')->first();
        if ($pull === null) {
            $this->line('No media pull has run.');

            return self::SUCCESS;
        }

        $this->line('Run:     '.$pull->id);
        $this->line('Source:  '.$pull->source.($pull->source_ref ? ' ('.$pull->source_ref.')' : ''));
        $this->line('Status:  '.$pull->status);
        $this->line(sprintf('Items:   %d done, %d failed of %d', $pull->items_done, $pull->items_failed, $pull->items_total));
        $running = \App\Models\MediaPullItem::query()->where('pull_id', $pull->id)->where('status', 'running')->count();
        $stale = app(MediaPullPlanner::class)->staleCount($pull);
        if ($running > 0) {
            $this->line(sprintf('Running: %d item(s)', $running));
        }
        if ($stale > 0) {
            $this->line(sprintf(
                'STALLED: %d running item(s) with no progress for over %d s (the lane was killed). Return them to the pool with media:pull --resume.',
                $stale, MediaTransfer::staleAfterSeconds()
            ));
        }
        $this->line('Bytes:   '.$this->humanBytes((int) $pull->bytes_done).' of '.$this->humanBytes((int) $pull->bytes_total));
        if ($pull->error) {
            $this->line('Error:   '.$pull->error);
        }

        return self::SUCCESS;
    }

    /**
     * --resume: continue the latest halted run, or heal the running one (its
     * stale claims return to the pool), queued or inline. $force is
     * --requeue-running: every running item returns, whatever its age.
     */
    private function resumeRun(MediaPullPlanner $planner, MediaTransfer $transfer, bool $force = false): int
    {
        $pull = $planner->activePull();
        if ($pull === null) {
            $this->line('No halted media pull to resume. Use --status.');

            return self::SUCCESS;
        }

        $wasRunning = $pull->status === 'running';
        $ids = $planner->reclaimStale($pull, $force);
        $reclaimed = count($ids);
        if ($reclaimed > 0) {
            $this->info(sprintf('Run %s: %d stalled item(s) returned to the pool.', substr($pull->id, 0, 8), $reclaimed));
        } elseif ($wasRunning) {
            $this->line(sprintf(
                'Run %s is running and has no stalled item (window %d s). Nothing to resume. Use --status, or --requeue-running when the lanes are known dead.',
                substr($pull->id, 0, 8), MediaTransfer::staleAfterSeconds()
            ));

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            $planner->resume($pull, dispatch: false);
            $this->info('Resuming run '.substr($pull->id, 0, 8).' inline.');
            $this->runInline($planner, $transfer, $pull->fresh());

            return $this->finishStatus($pull->id);
        }

        // A halted run dispatches every pending item; a running run only the
        // items the reclaim returned (its other pending items hold a queued job).
        $n = $wasRunning ? $planner->dispatchItems($pull, $ids) : $planner->resume($pull);
        $this->info(sprintf('Resumed run %s: dispatched %d pending item(s) to the long-running queue. Poll with media:pull --status.', substr($pull->id, 0, 8), $n));

        return self::SUCCESS;
    }

    /** --retry-failed: reset the latest run's failed items and re-run them, queued or inline. */
    private function retryFailedRun(MediaPullPlanner $planner, MediaTransfer $transfer): int
    {
        $pull = $planner->latestPull();
        if ($pull === null) {
            $this->line('No media pull has run.');

            return self::SUCCESS;
        }

        if ($this->option('sync')) {
            [$reset] = $planner->retryFailed($pull, dispatch: false);
            $this->info(sprintf('Run %s: %d failed item(s) reset; running inline.', substr($pull->id, 0, 8), $reset));
            $this->runInline($planner, $transfer, $pull->fresh());

            return $this->finishStatus($pull->id);
        }

        [$reset, $n] = $planner->retryFailed($pull);
        $this->info(sprintf('Run %s: %d failed item(s) reset, %d dispatched. Poll with media:pull --status.', substr($pull->id, 0, 8), $reset, $n));

        return self::SUCCESS;
    }

    private function runInline(MediaPullPlanner $planner, MediaTransfer $transfer, MediaPull $pull): void
    {
        $planner->runSync($pull, $transfer, function ($item, array $result, int $i, int $total): void {
            $this->line(sprintf(
                '[%d/%d] %s %s/%s -> %s',
                $i, $total, str_pad($result['status'], 7),
                $item->subject, $item->kind,
                isset($result['error']) ? $result['error'] : $this->humanBytes((int) ($result['bytes'] ?? 0))
            ));
        });
    }

    private function finishStatus(string $pullId): int
    {
        $fresh = MediaPull::query()->find($pullId);
        $this->info(sprintf(
            'Done: %d fetched, %d failed, status %s.',
            (int) $fresh->items_done, (int) $fresh->items_failed, (string) $fresh->status
        ));

        return ($fresh->items_failed ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function haltRun(): int
    {
        $pull = MediaPull::query()->where('status', 'running')->orderByDesc('created_at')->first();
        if ($pull === null) {
            $this->line('No running media pull to halt.');

            return self::SUCCESS;
        }
        $pull->forceFill(['status' => 'halted', 'updated_at' => now()])->save();
        $this->info('Halted run '.substr($pull->id, 0, 8).'. In-flight items stop at their next tick; continue with media:pull --resume (add --sync to run inline) or from the wizard.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function parseList(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $csv)), fn ($s) => $s !== ''));
    }

    private function humanBytes(int $b): string
    {
        if ($b <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($b, 1024));
        $i = max(0, min($i, count($units) - 1));

        return sprintf('%.1f %s', $b / (1024 ** $i), $units[$i]);
    }
}

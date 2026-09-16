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

        if ($planner->activePull() !== null) {
            $this->error('A media pull is already running or halted. Use --status, or --halt first.');

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
        $this->line('Bytes:   '.$this->humanBytes((int) $pull->bytes_done).' of '.$this->humanBytes((int) $pull->bytes_total));
        if ($pull->error) {
            $this->line('Error:   '.$pull->error);
        }

        return self::SUCCESS;
    }

    private function haltRun(): int
    {
        $pull = MediaPull::query()->where('status', 'running')->orderByDesc('created_at')->first();
        if ($pull === null) {
            $this->line('No running media pull to halt.');

            return self::SUCCESS;
        }
        $pull->forceFill(['status' => 'halted', 'updated_at' => now()])->save();
        $this->info('Halted run '.substr($pull->id, 0, 8).'. In-flight items stop at their next tick; resume from the wizard or re-run media:pull after --status shows halted.');

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

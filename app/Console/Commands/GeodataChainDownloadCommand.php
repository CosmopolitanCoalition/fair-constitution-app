<?php

namespace App\Console\Commands;

use App\Models\GeodataItem;
use App\Models\GeodataRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * THE MULTITHREADED CHAIN (operator ruling 2026-08-29): "the legacy seeder
 * should never be used ever again — it is always supposed to be
 * multithreaded, always."
 *
 * When a wizard "Download from official sources" run completes, the etl
 * supervisor writes control/chain_pull.json instead of invoking the retired
 * single-threaded seeder. This command (scheduled every minute) consumes the
 * marker and starts the MULTITHREADED pull run against the downloaded /data,
 * so download bars flow straight into the pull dashboard with no operator
 * step between.
 *
 * Guards mirror SetupController::startGeodataPull (the canonical run
 * creator): an active supervisor run, an unfinished pull run, or pending
 * migrations each leave the marker in place for the next tick rather than
 * dropping the handoff. No FRESH purge here — a chained ingest is the
 * virgin-box path; wiping an existing planet stays an explicit operator act.
 */
class GeodataChainDownloadCommand extends Command
{
    protected $signature   = 'geodata:chain-download';
    protected $description = 'Start the multithreaded pull run for a completed official-source download (consumes control/chain_pull.json).';

    public function handle(): int
    {
        $controlDir = base_path('scripts/etl/control');
        $marker     = $controlDir.'/chain_pull.json';
        if (! is_file($marker)) {
            return self::SUCCESS;   // nothing to chain
        }

        // An active supervisor run (the download itself, or anything else)
        // holds the handoff; the marker persists for the next tick.
        if (is_file($controlDir.'/running.json')) {
            return self::SUCCESS;
        }
        if (GeodataRun::unfinished() !== null) {
            return self::SUCCESS;
        }

        $payload  = json_decode((string) @file_get_contents($marker), true) ?: [];
        $reqOpts  = (array) data_get($payload, 'request.options', []);
        $dataRoot = (string) ($payload['data_root'] ?? '/data');

        $options = [
            'countries'  => array_values((array) ($reqOpts['countries'] ?? [])),
            'adm_levels' => array_values((array) ($reqOpts['adm_levels'] ?? [])),
            // Provenance: this planet came from the official-source download.
            'source'     => 'download',
            'dry_run'    => false,
            // The acceptance scan is optional (operator ruling 2026-08-29);
            // default on, flippable mid-run via the Step 2 checkbox.
            'auto_scan'  => (bool) ($reqOpts['auto_scan'] ?? true),
        ];

        $run = GeodataRun::create([
            'status'            => 'running',
            'phase'             => 'enumerating',
            'data_root'         => $dataRoot,
            'options'           => $options,
            'initiator_user_id' => data_get($payload, 'request.initiator_user_id'),
        ]);
        GeodataItem::create([
            'run_id'   => $run->id,
            'kind'     => 'manifest',
            'status'   => 'pending',
            'position' => 0,
        ]);

        file_put_contents(
            $controlDir.'/request.json',
            json_encode([
                'submitted_at' => now()->toIso8601String(),
                'mode'         => 'pull',
                'source'       => 'download',
                'run_id'       => $run->id,
                'options'      => $options,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        @unlink($marker);

        Log::info('geodata:chain-download — multithreaded pull run started for the completed download', [
            'run_id'    => $run->id,
            'data_root' => $dataRoot,
        ]);
        $this->info("Chained pull run {$run->id} against {$dataRoot}.");

        // Kick the pump now so the run advances without waiting a full minute.
        Artisan::call('geodata:pump');

        return self::SUCCESS;
    }
}

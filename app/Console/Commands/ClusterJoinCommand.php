<?php

namespace App\Console\Commands;

use App\Jobs\Federation\ClusterJoinJob;
use App\Models\ClusterMembership;
use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Phase G (G2) — join a cluster as a read-only mirror.
 *
 * The admit step (POST /adopt with the join key) runs SYNCHRONOUSLY so a bad or exhausted key
 * fails fast, in-band, before any transfer is queued. The long, resumable foundation seed +
 * audit drain is then DISPATCHED to the long-running Horizon queue by DEFAULT (ClusterJoinJob,
 * timeout=0): the command returns at once, the node keeps serving its UI while the transfer
 * runs in the worker, and a client/SSH disconnect never aborts it. --sync runs the seed +
 * drain inline for a foreground box that wants to block (parity with federation:resume-join).
 *
 * A RERUN on a box that is ALREADY a mirror of this host RESUMES the existing membership: it
 * dispatches (or, with --sync, runs) the resumable job WITHOUT re-adopting and WITHOUT
 * consuming a second join-key use. seeded_at short-circuits the seed and the cold cursor
 * resumes the drain, so no completed page is replayed.
 *
 *   php artisan cluster:join https://host --key handle.secret         # admit + dispatch (recommended)
 *   php artisan cluster:join https://host --key handle.secret --sync  # admit + drain inline (blocks)
 */
class ClusterJoinCommand extends Command
{
    protected $signature = 'cluster:join
        {host_url : the host base URL, e.g. https://host.example}
        {--key= : the join key plaintext (handle.secret)}
        {--sync : run the seed + drain inline instead of dispatching to the long-running queue}';

    protected $description = 'Join a cluster as a read-only mirror — admit synchronously, then dispatch the resumable seed + drain to the long-running queue (--sync to block).';

    public function handle(MirrorService $mirror): int
    {
        $sync = (bool) $this->option('sync');

        try {
            if ($mirror->isMirror()) {
                // Already a mirror of a host — RESUME, never re-adopt. No /adopt POST, no second
                // join-key use. The job (or --sync) picks up the SYNCING membership; seeded_at
                // short-circuits the seed and the cold cursor resumes the drain (no page replay).
                $membership = $mirror->activeMirrorMembership();
                if ($membership === null) {
                    $this->error('This box is a mirror but has no active membership to resume — re-join from the host with a fresh key.');

                    return self::FAILURE;
                }

                if ($membership->state === ClusterMembership::STATE_LIVE) {
                    $this->info('Already a live read-only mirror — nothing to resume.');

                    return self::SUCCESS;
                }

                $this->runTail($mirror, $membership, $sync, 'Resuming the existing mirror membership');
            } else {
                $key = (string) $this->option('key');
                if ($key === '') {
                    $this->error('A join key is required: --key=handle.secret');

                    return self::FAILURE;
                }

                // Admit synchronously (sync:false defers the seed + drain to the tail): a bad or
                // exhausted key fails fast HERE, before any transfer is queued.
                $membership = $mirror->joinHost((string) $this->argument('host_url'), $key, [], sync: false);

                $this->runTail($mirror, $membership, $sync, 'Adoption accepted');
            }
        } catch (Throwable $e) {
            $this->error('Join failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Run (--sync) or dispatch (default) the resumable seed + drain for an admitted or existing
     * mirror membership, then tell the operator where live progress is visible.
     */
    private function runTail(MirrorService $mirror, ClusterMembership $membership, bool $sync, string $context): void
    {
        if ($sync) {
            $this->info($context.' — draining inline for membership '.$membership->id.' (this blocks until the seed + drain finish)…');
            $mirror->syncMembership($membership);
            $this->info('Done — '.$membership->refresh()->state.'.');

            return;
        }

        ClusterJoinJob::dispatch((string) $membership->id);

        $this->info($context.' — dispatched the seed + drain to the long-running queue for membership '.$membership->id.'.');
        $this->line('  • It runs in Horizon with no HTTP/client timeout, and is resumable on re-run (no completed page is replayed).');
        $this->line('  • Ensure the Horizon worker is up (the long-running supervisor), or the job sits queued.');
        $this->line('  • Watch progress: the setup join page (/setup/join) or the mesh console (/operator/federation); the live endpoint is GET /federation/cluster/sync-progress.');
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\Federation\ClusterJoinJob;
use App\Models\ClusterMembership;
use App\Services\Mirror\MirrorService;
use Illuminate\Console\Command;

/**
 * federation:resume-join — resume an in-progress mirror join (seed + drain) for the
 * active mirror membership (Phase G). The seed import + audit drain of a large corpus
 * can run far longer than any HTTP request or client timeout, so by DEFAULT this
 * DISPATCHES the work to the long-running Horizon queue (timeout=0): the command
 * returns immediately, the job runs to completion in the worker regardless of any
 * client disconnect, and it is resumable (seeded_at short-circuits the seed; the cold
 * cursor resumes the drain). Watch progress at GET /federation/cluster/sync-progress.
 *
 *   php artisan federation:resume-join            # dispatch to the long-running queue (recommended)
 *   php artisan federation:resume-join --sync     # run inline (blocks until done — for a foreground box)
 *
 * EXIT CODES (a caller — deploy.sh / deploy.ps1 — branches on these):
 *   0  resumed (dispatched or, with --sync, drained inline)
 *   3  NO mirror membership at all — this box was NEVER a mirror. This is a DISTINCT
 *      code so the installer can tell "nothing to resume, fall through to a keyed
 *      cluster:join" apart from a genuine failure. It NEVER means "adopt again" by
 *      itself — the caller decides. This command consumes no join key and posts no /adopt.
 *   4  A mirror membership EXISTS but is DEPARTED/REJECTED (this box left the cluster,
 *      or the host rejected it). The operator ruling forbids a silent re-adopt here:
 *      the caller must FAIL LOUD and instruct the operator to mint a fresh key on the
 *      host. Kept DISTINCT from 3 precisely so a departed node does not fall through to
 *      a keyed cluster:join. This command consumes no join key and posts no /adopt.
 *   1  generic failure (reserved; not currently emitted by these paths).
 */
class FederationResumeJoinCommand extends Command
{
    /** No mirror membership at all — this box was never a mirror (see class docblock). */
    public const EXIT_NO_MEMBERSHIP = 3;

    /** A mirror membership exists but is DEPARTED/REJECTED — never silently re-adopt (see class docblock). */
    public const EXIT_DEPARTED = 4;

    protected $signature = 'federation:resume-join
                            {--sync : run the seed + drain inline instead of dispatching to the queue}';

    protected $description = 'Resume an in-progress mirror join (seed + drain) — dispatched to the long-running queue by default so it survives client/HTTP timeouts';

    public function handle(MirrorService $mirror): int
    {
        $membership = ClusterMembership::query()
            ->where('role', ClusterMembership::ROLE_MIRROR)
            ->whereNotIn('state', [ClusterMembership::STATE_DEPARTED, ClusterMembership::STATE_REJECTED])
            ->latest('updated_at')
            ->first();

        if ($membership === null || $membership->peer === null) {
            // No ACTIVE membership. The operator ruling separates two cases here and the
            // caller must NOT treat them alike: a box that was NEVER a mirror falls through
            // to a keyed cluster:join (exit 3); a box whose membership is DEPARTED/REJECTED
            // must fail LOUD and be told to mint a fresh key on the host (exit 4). Folding
            // both into 3 would let a departed node silently re-adopt with a still-valid key.
            $hasDepartedMembership = ClusterMembership::query()
                ->where('role', ClusterMembership::ROLE_MIRROR)
                ->whereIn('state', [ClusterMembership::STATE_DEPARTED, ClusterMembership::STATE_REJECTED])
                ->exists();

            if ($hasDepartedMembership) {
                // This box HELD a mirror membership that is now departed/rejected. Never a
                // silent re-adopt: distinct code 4 so the caller fails loud. No key is
                // consumed and no /adopt is posted here.
                $this->error('This box has a DEPARTED/REJECTED mirror membership; it will not silently re-adopt (exit '.self::EXIT_DEPARTED.').');

                return self::EXIT_DEPARTED;
            }

            // Distinct code 3 (not FAILURE): "no membership at all". The caller falls through
            // to a keyed cluster:join. No key is consumed and no /adopt is posted here.
            $this->error('No mirror membership to resume (exit '.self::EXIT_NO_MEMBERSHIP.').');

            return self::EXIT_NO_MEMBERSHIP;
        }

        if ($this->option('sync')) {
            $this->info("Resuming inline for membership {$membership->id} (this blocks until the seed + drain finish)…");
            $mirror->syncMembership($membership);
            $this->info('Done — '.$membership->refresh()->state.'.');

            return self::SUCCESS;
        }

        ClusterJoinJob::dispatch((string) $membership->id);

        $this->info("Dispatched the seed + drain to the long-running queue for membership {$membership->id}.");
        $this->line('  • It runs in Horizon with no HTTP/client timeout, and is resumable on re-run.');
        $this->line('  • Ensure the Horizon worker is up (the long-running supervisor).');
        $this->line('  • Watch progress: GET /federation/cluster/sync-progress (or poll the jurisdictions count + seeded_at).');

        return self::SUCCESS;
    }
}

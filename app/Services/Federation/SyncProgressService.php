<?php

namespace App\Services\Federation;

use App\Models\ClusterMembership;
use App\Models\SyncCursor;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sync-progress instrumentation for a mirror's seed + drain (Phase G). The long
 * join runs server-side — a queued ClusterJoinJob (the browser path) or the
 * synchronous CLI path — so the operator's page can't watch a held-open request.
 * This writes per-PHASE markers (which phase, when it started, the lifecycle) to
 * an OUT-OF-BAND cache record a SEPARATE poll request reads: the federation
 * counterpart to the ETL setup wizard's heartbeat files.
 *
 * The live CURRENT/TOTAL values are NOT stored here — they are read straight off
 * the already-committed DB columns at read time (`seed_cursor_bytes` /
 * `seed_total_bytes` for the download; the cold `SyncCursor` for the drain), so
 * this never has to reach into the tight download / page loops, and a progress
 * hiccup can never stall a drain.
 *
 * Every WRITE swallows failures — progress is a cosmetic mirror, never load-bearing.
 */
class SyncProgressService
{
    private const TTL_SECONDS = 3 * 3600;

    public const PHASE_DOWNLOAD = 'seed_download';

    public const PHASE_IMPORT = 'seed_import';

    public const PHASE_DRAIN = 'audit_drain';

    /** Reset the record and mark the sync running (called at the top of a sync run). */
    public function begin(ClusterMembership $m): void
    {
        $this->put($m, [
            'lifecycle' => 'running',
            'error' => null,
            'phases' => [
                self::PHASE_DOWNLOAD => $this->phase('pending'),
                self::PHASE_IMPORT => $this->phase('pending'),
                self::PHASE_DRAIN => $this->phase('pending'),
            ],
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    public function startDownload(ClusterMembership $m): void
    {
        $this->mark($m, self::PHASE_DOWNLOAD, 'running');
    }

    public function completeDownload(ClusterMembership $m): void
    {
        $this->mark($m, self::PHASE_DOWNLOAD, 'done');
    }

    public function startImport(ClusterMembership $m): void
    {
        $this->mark($m, self::PHASE_IMPORT, 'running');
    }

    public function completeImport(ClusterMembership $m): void
    {
        $this->mark($m, self::PHASE_IMPORT, 'done');
    }

    public function startDrain(ClusterMembership $m): void
    {
        $this->mark($m, self::PHASE_DRAIN, 'running');
    }

    public function completeDrain(ClusterMembership $m): void
    {
        $this->mark($m, self::PHASE_DRAIN, 'done');
    }

    /** The whole sync caught up — flip the record to done. */
    public function finish(ClusterMembership $m): void
    {
        $rec = $this->read($m);
        $rec['lifecycle'] = 'done';
        $rec['updated_at'] = now()->toIso8601String();
        $this->put($m, $rec);
    }

    /** A phase threw — flip the record to failed and carry the reason. */
    public function fail(ClusterMembership $m, string $message): void
    {
        $rec = $this->read($m);
        $rec['lifecycle'] = 'failed';
        $rec['error'] = $message;
        $rec['updated_at'] = now()->toIso8601String();
        $this->put($m, $rec);
    }

    public function forget(ClusterMembership $m): void
    {
        try {
            Cache::forget($this->key($m));
        } catch (Throwable) {
            // best-effort
        }
    }

    /**
     * The READ API the poll endpoint + tests call: merge the cache phase markers
     * (status + per-phase started_at, for client-side ETA) with the LIVE,
     * already-committed DB values (download bytes, drain records) into the bar
     * contract the Vue component renders. Mirrors the setup wizard's bars[] shape.
     *
     * @return array{membership_id:string,membership_state:?string,lifecycle:string,error:?string,bars:array<int,array<string,mixed>>,updated_at:string,host_server_id:?string}
     */
    public function progressFor(ClusterMembership $m): array
    {
        $rec = $this->read($m);
        $phaseOf = fn (string $k): array => $rec['phases'][$k] ?? $this->phase('pending');

        // ── Seed download — the one phase with a knowable total (manifest.size_bytes),
        // so it carries a real %/ETA. current/total are live committed columns.
        $dl = $phaseOf(self::PHASE_DOWNLOAD);
        $dlStatus = $dl['status'];
        if ($dlStatus === 'pending' && (int) $m->seed_cursor_bytes > 0) {
            $dlStatus = 'running';
        }
        if ($m->seeded_at !== null) {
            $dlStatus = 'done';
        }

        // ── Seed import — opaque (tar + pg_restore); honest indeterminate bar.
        $im = $phaseOf(self::PHASE_IMPORT);
        $imStatus = $m->seeded_at !== null ? 'done' : $im['status'];

        // ── Audit drain — no reliable a-priori target (the cold cursor pulls forward
        // until a short page); show a live record count, not a faked %.
        $cursor = $m->peer_id !== null ? SyncCursor::query()
            ->where('peer_id', $m->peer_id)
            ->where('direction', SyncCursor::DIRECTION_INBOUND)
            ->where('mode', SyncCursor::MODE_COLD)
            ->latest('updated_at')
            ->first() : null;
        $dr = $phaseOf(self::PHASE_DRAIN);
        $drStatus = $dr['status'];
        $records = $cursor !== null ? (int) $cursor->records_applied : 0;
        $pages = $cursor !== null ? (int) $cursor->pages_applied : 0;
        if ($cursor !== null && $cursor->status === SyncCursor::STATUS_COMPLETE) {
            $drStatus = 'done';
        } elseif ($cursor !== null && $cursor->status === SyncCursor::STATUS_ABORTED) {
            $drStatus = 'failed';
        } elseif ($records > 0 && $drStatus === 'pending') {
            $drStatus = 'running';
        }

        $bars = [
            [
                'key' => self::PHASE_DOWNLOAD,
                'label' => 'Seed download',
                'unit' => 'bytes',
                'current' => (int) $m->seed_cursor_bytes,
                'total' => $m->seed_total_bytes !== null ? (int) $m->seed_total_bytes : null,
                'status' => $dlStatus,
                'started_at' => $dl['started_at'],
                'completed_at' => $dl['completed_at'],
                'indeterminate' => false,
            ],
            [
                'key' => self::PHASE_IMPORT,
                'label' => 'Import into the database',
                'unit' => null,
                'current' => null,
                'total' => null,
                'status' => $imStatus,
                'started_at' => $im['started_at'],
                'completed_at' => $im['completed_at'],
                'indeterminate' => true,
            ],
            [
                'key' => self::PHASE_DRAIN,
                'label' => 'Audit history',
                'unit' => 'records',
                'current' => $records,
                'pages' => $pages,
                'total' => null,
                'status' => $drStatus,
                'started_at' => $dr['started_at'],
                'completed_at' => $dr['completed_at'],
                'indeterminate' => true,
            ],
        ];

        // Lifecycle — prefer the explicit cache record, else derive from membership state.
        $lifecycle = $rec['lifecycle'];
        if ($lifecycle === null) {
            $lifecycle = match ($m->state) {
                ClusterMembership::STATE_LIVE => 'done',
                ClusterMembership::STATE_SYNCING => 'running',
                default => 'idle',
            };
        }
        // A live membership is unambiguously caught up — never show "running" once it flipped.
        if ($m->state === ClusterMembership::STATE_LIVE && $lifecycle !== 'failed') {
            $lifecycle = 'done';
        }

        return [
            'membership_id' => (string) $m->id,
            'membership_state' => $m->state,
            'lifecycle' => $lifecycle,
            'error' => $rec['error'] ?? null,
            'bars' => $bars,
            'updated_at' => $rec['updated_at'] ?? now()->toIso8601String(),
            'host_server_id' => $m->peer?->server_id,
        ];
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return array{status:string,started_at:?string,completed_at:?string} */
    private function phase(string $status): array
    {
        return ['status' => $status, 'started_at' => null, 'completed_at' => null];
    }

    private function mark(ClusterMembership $m, string $phase, string $status): void
    {
        $rec = $this->read($m);
        $p = $rec['phases'][$phase] ?? $this->phase('pending');

        if ($status === 'running' && empty($p['started_at'])) {
            $p['started_at'] = now()->toIso8601String();
        }
        if ($status === 'done') {
            $p['completed_at'] = now()->toIso8601String();
            $p['started_at'] = $p['started_at'] ?? $p['completed_at'];
        }
        $p['status'] = $status;

        $rec['phases'][$phase] = $p;
        $rec['lifecycle'] = $rec['lifecycle'] ?? 'running';
        $rec['updated_at'] = now()->toIso8601String();
        $this->put($m, $rec);
    }

    /** @return array<string,mixed> */
    private function read(ClusterMembership $m): array
    {
        try {
            $rec = Cache::get($this->key($m));
            if (is_array($rec)) {
                return $rec;
            }
        } catch (Throwable) {
            // cache unreachable — fall through to the empty skeleton (DB still drives current/total)
        }

        return ['lifecycle' => null, 'error' => null, 'phases' => []];
    }

    /** @param  array<string,mixed>  $rec */
    private function put(ClusterMembership $m, array $rec): void
    {
        try {
            Cache::put($this->key($m), $rec, self::TTL_SECONDS);
        } catch (Throwable) {
            // best-effort: never let a progress write break a drain
        }
    }

    private function key(ClusterMembership $m): string
    {
        return 'federation:sync:'.$m->id;
    }
}

<?php

namespace App\Jobs;

use App\Models\Legislature;
use App\Models\SocialSpace;
use App\Services\Matrix\SocialTopologyReconcilerService;
use App\Services\Social\SubforumReconciler;
use App\Support\HostCapacity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Phase K-1 — the social-structure sweep. For each civically-active jurisdiction it ensures the
 * public_square + halls spaces exist (firstOrCreate, idempotent) and runs the SubforumReconciler
 * to bind halls subforums to live governance objects. Structural plumbing — no engine filing.
 *
 * K-1 "civically active" = has a seated (active) legislature. The CivicPopulation/activation-tier
 * gate that toggles flat→structured growth is a Phase-I seam. Runnable on demand (social:demo
 * dispatches it inline); a scheduled nightly sweep is a documented follow-up (no CLK code).
 *
 * ── THE ETL RULE (W-0165) ────────────────────────────────────────────────
 * The no-argument full sweep walks the active legislatures with a KEYSET cursor
 * over jurisdiction id, in bounded chunks. Each chunk commits its cursor to a
 * durable store before the next chunk starts, so a kill mid-run costs one chunk
 * and the next dispatch resumes from the committed cursor. Progress is logged
 * per chunk with elapsed and remaining. The chunk size derives from the host
 * (HostCapacity::enumerationChunk, CGA_ENUM_CHUNK overrides). The SPACES have a
 * separate planet-scale path (InstitutionProvisionService); this is the halls
 * binding path, the missing bounded walk. Passing one jurisdiction id runs that
 * one binding directly and skips the cursor (the demo and CLK-06 seam paths).
 */
class EvaluateSocialStructureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** The durable resume cursor for the full sweep. One sweep at a time. */
    private const CURSOR_CACHE_KEY = 'social:evaluate:sweep_cursor';

    /** The keyset start. Every real jurisdiction id sorts after it, and it is a valid uuid for the pgsql cast. */
    private const ZERO_UUID = '00000000-0000-0000-0000-000000000000';

    public function __construct(public readonly ?string $jurisdictionId = null) {}

    public function handle(SubforumReconciler $reconciler, SocialTopologyReconcilerService $topology): void
    {
        if ($this->jurisdictionId !== null) {
            $this->bindJurisdiction($this->jurisdictionId, $reconciler, $topology);

            return;
        }

        $this->sweep($reconciler, $topology);
    }

    /**
     * The full sweep. A resumable keyset walk over the active legislatures'
     * jurisdiction ids, in bounded committed chunks.
     */
    private function sweep(SubforumReconciler $reconciler, SocialTopologyReconcilerService $topology): void
    {
        $chunkSize = $this->chunkSize();
        $after     = (string) Cache::get(self::CURSOR_CACHE_KEY, self::ZERO_UUID);
        $total     = $this->activeJurisdictionTotal();
        $done      = $this->completedBehind($after);
        $startedAt = microtime(true);
        $seeded    = $done;

        while (true) {
            $ids = $this->activeJurisdictionChunk($after, $chunkSize);

            if ($ids === []) {
                break;
            }

            foreach ($ids as $jurisdictionId) {
                $this->bindJurisdiction($jurisdictionId, $reconciler, $topology);
            }

            // Commit the cursor AFTER the chunk is bound. A kill now costs this chunk only.
            $after = (string) end($ids);
            Cache::put(self::CURSOR_CACHE_KEY, $after, now()->addDay());

            $done += count($ids);
            $this->logProgress($done, $total, $startedAt, $done - $seeded);

            if (count($ids) < $chunkSize) {
                break;
            }
        }

        // The walk reached the end. Clear the cursor so the next dispatch scans fresh.
        Cache::forget(self::CURSOR_CACHE_KEY);
    }

    /**
     * Bind one jurisdiction: ensure its public_square + halls, reconcile the
     * halls subforums, and mirror the topology into Matrix (best-effort).
     */
    private function bindJurisdiction(string $jurisdictionId, SubforumReconciler $reconciler, SocialTopologyReconcilerService $topology): void
    {
        SocialSpace::query()->firstOrCreate(
            ['jurisdiction_id' => $jurisdictionId, 'space_type' => SocialSpace::TYPE_PUBLIC_SQUARE, 'is_private' => false],
            ['title' => 'Public Square', 'status' => SocialSpace::STATUS_OPEN],
        );

        $halls = SocialSpace::query()->firstOrCreate(
            ['jurisdiction_id' => $jurisdictionId, 'space_type' => SocialSpace::TYPE_HALLS, 'is_private' => false],
            ['title' => 'Halls of Governance', 'status' => SocialSpace::STATUS_OPEN],
        );

        $reconciler->reconcile($halls, $reconciler->gatherLiveObjects($jurisdictionId));

        // Phase K-3 — mirror the jurisdiction topology into Matrix (Plane B). Bridged, NOT merged:
        // a down/unreachable homeserver must NEVER fail the Plane A sweep above, so this is
        // best-effort. #halls is gated on a seated legislature (the FLIP-ON-SEATEDNESS gate).
        try {
            $isSeated = Legislature::query()
                ->where('jurisdiction_id', $jurisdictionId)
                ->where('status', Legislature::STATUS_ACTIVE)
                ->exists();
            $topology->reconcileJurisdiction($jurisdictionId, $isSeated);
        } catch (\Throwable $e) {
            Log::warning('Matrix topology reconcile skipped for jurisdiction '.$jurisdictionId.': '.$e->getMessage());
        }
    }

    /** Rows per committed chunk, derived from the host. CGA_ENUM_CHUNK overrides. */
    protected function chunkSize(): int
    {
        return HostCapacity::enumerationChunk();
    }

    /**
     * The next page of distinct active-legislature jurisdiction ids after the
     * cursor, ordered by id. One level of keyset pagination.
     *
     * @return array<int,string>
     */
    private function activeJurisdictionChunk(string $after, int $limit): array
    {
        return Legislature::query()
            ->where('status', Legislature::STATUS_ACTIVE)
            ->where('jurisdiction_id', '>', $after)
            ->orderBy('jurisdiction_id')
            ->distinct()
            ->limit($limit)
            ->pluck('jurisdiction_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /** How many distinct active jurisdictions the sweep must bind in all. */
    private function activeJurisdictionTotal(): int
    {
        return Legislature::query()
            ->where('status', Legislature::STATUS_ACTIVE)
            ->distinct()
            ->count('jurisdiction_id');
    }

    /** How many distinct active jurisdictions sit at or behind the cursor (already bound). */
    private function completedBehind(string $after): int
    {
        if ($after === self::ZERO_UUID) {
            return 0;
        }

        return Legislature::query()
            ->where('status', Legislature::STATUS_ACTIVE)
            ->where('jurisdiction_id', '<=', $after)
            ->distinct()
            ->count('jurisdiction_id');
    }

    /** Per-chunk progress with elapsed and remaining. Never fabricated. */
    private function logProgress(int $done, int $total, float $startedAt, int $processedThisRun): void
    {
        $elapsed   = max(0.0, microtime(true) - $startedAt);
        $remaining = max(0, $total - $done);
        $rate      = $processedThisRun > 0 && $elapsed > 0 ? $processedThisRun / $elapsed : 0.0;
        $etaSeconds = $rate > 0 ? (int) round($remaining / $rate) : null;

        Log::info('EvaluateSocialStructureJob sweep progress', [
            'done'         => $done,
            'total'        => $total,
            'remaining'    => $remaining,
            'elapsed_s'    => round($elapsed, 1),
            'eta_s'        => $etaSeconds,
        ]);
    }
}

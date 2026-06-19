<?php

namespace App\Jobs;

use App\Models\Legislature;
use App\Models\SocialSpace;
use App\Services\Social\SubforumReconciler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase K-1 — the social-structure sweep. For each civically-active jurisdiction it ensures the
 * public_square + halls spaces exist (firstOrCreate, idempotent) and runs the SubforumReconciler
 * to bind halls subforums to live governance objects. Structural plumbing — no engine filing.
 *
 * K-1 "civically active" = has a seated (active) legislature. The CivicPopulation/activation-tier
 * gate that toggles flat→structured growth is a Phase-I seam. Runnable on demand (social:demo
 * dispatches it inline); a scheduled nightly sweep is a documented follow-up (no CLK code).
 */
class EvaluateSocialStructureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ?string $jurisdictionId = null) {}

    public function handle(SubforumReconciler $reconciler): void
    {
        $jurisdictionIds = $this->jurisdictionId !== null
            ? [$this->jurisdictionId]
            : $this->civicallyActiveJurisdictions();

        foreach ($jurisdictionIds as $jurisdictionId) {
            SocialSpace::query()->firstOrCreate(
                ['jurisdiction_id' => $jurisdictionId, 'space_type' => SocialSpace::TYPE_PUBLIC_SQUARE, 'is_private' => false],
                ['title' => 'Public Square', 'status' => SocialSpace::STATUS_OPEN],
            );

            $halls = SocialSpace::query()->firstOrCreate(
                ['jurisdiction_id' => $jurisdictionId, 'space_type' => SocialSpace::TYPE_HALLS, 'is_private' => false],
                ['title' => 'Halls of Governance', 'status' => SocialSpace::STATUS_OPEN],
            );

            $reconciler->reconcile($halls, $reconciler->gatherLiveObjects($jurisdictionId));
        }
    }

    /** @return array<int,string> */
    private function civicallyActiveJurisdictions(): array
    {
        return Legislature::query()
            ->where('status', Legislature::STATUS_ACTIVE)
            ->pluck('jurisdiction_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}

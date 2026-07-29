<?php

namespace App\Http\Controllers\Social;

use App\Domain\Achievements\AchievementCatalog;
use App\Http\Controllers\Controller;
use App\Services\JourneyService;
use App\Support\SurfaceMeta;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * THE ACHIEVEMENTS PAGE — the full 141-entry catalog over the sealed
 * append-only ledger (v3 contract social/achievements.html; K-2).
 *
 * READ-ONLY by design, like /reach: achievements are decorative, never
 * power (CI-1), so this surface offers no action at all. The catalog is
 * serialized from AchievementCatalog (its first and only trip to the
 * client); earned state is the viewer's own ledger rows.
 *
 * PI-6, structurally: NO total, NO percentage, NO "N of M" leaves this
 * controller — the props carry lists and per-entry earned marks only.
 * A completion figure is a per-person composite score wearing a different
 * hat; the absence of one is the rail (AchievementService docblock).
 * PI-2: an unearned entry renders "not yet earned" — never a reason.
 */
class AchievementsController extends Controller
{
    public function __construct(private readonly JourneyService $journeys)
    {
    }

    public function index(Request $request): Response
    {
        $viewer = $request->user();

        // The viewer's own earned rows, keyed by award_key. A guest browses
        // the same catalog with no earned overlay — the catalog is public
        // knowledge; the ledger rows are the person's own.
        $earned = [];

        if ($viewer !== null) {
            foreach ($this->journeys->achievementsFor($viewer) as $row) {
                $earned[$row['award_key']] = ['earned_at' => $row['earned_at']];
            }
        }

        return Inertia::render('Social/Achievements', [
            'surface' => SurfaceMeta::for('social/achievements'),
            'signedIn' => $viewer !== null,
            'catalog' => [
                'personal' => $this->entries(AchievementCatalog::inScope(AchievementCatalog::SCOPE_PERSONAL)),
                'jurisdiction' => $this->entries(AchievementCatalog::inScope(AchievementCatalog::SCOPE_JURISDICTION)),
                'system' => $this->entries(AchievementCatalog::inScope(AchievementCatalog::SCOPE_SYSTEM)),
            ],
            'earned' => $earned,
        ]);
    }

    /**
     * @param list<string> $keys
     * @return list<array{key: string, title_key: string, tier: string, earner: string, trigger: string, awaiting_ui: bool, is_arc: bool}>
     */
    private function entries(array $keys): array
    {
        return array_map(function (string $key): array {
            $meta = AchievementCatalog::get($key);

            return [
                'key' => $key,
                'title_key' => $meta['title_key'],
                'tier' => $meta['tier'],
                'earner' => $meta['earner'],
                // The proving source, verbatim from the catalog — honest
                // provenance ("written by the system when the permanent
                // record shows the act"). A cite-style token, never translated.
                'trigger' => $meta['trigger'],
                'awaiting_ui' => (bool) $meta['awaiting_ui'],
                'is_arc' => $meta['tier'] === AchievementCatalog::TIER_TOUR,
            ];
        }, $keys);
    }
}

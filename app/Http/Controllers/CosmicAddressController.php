<?php

namespace App\Http\Controllers;

use App\Models\CosmicAddress;
use Illuminate\Http\JsonResponse;

/**
 * Serves the cosmic-address cascader in the setup wizard.
 *
 * The cascader pre-fills each dropdown from the default path (the ancestors of
 * the single enabled leaf world — Earth for v1), then fetches siblings one
 * level at a time as the user changes selections.
 */
class CosmicAddressController extends Controller
{
    /**
     * Full path from "Observable Universe" down to the single enabled leaf world.
     * Used to pre-fill the cascader on first mount.
     *
     * Returns: [{id, parent_id, label, slug, type, subtype, enabled}, ...]
     * in order from shallowest to deepest. The Multiverse root is omitted —
     * it's an implicit header in the UI, not a selectable level.
     */
    public function defaultPath(): JsonResponse
    {
        $leaf = CosmicAddress::where('type', 'world')
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->first();

        if (!$leaf) {
            return response()->json(['path' => []]);
        }

        $path = collect($leaf->pathFromRoot())
            ->reject(fn ($row) => ($row['type'] ?? null) === 'multiverse')
            ->values()
            ->all();

        return response()->json(['path' => $path]);
    }

    /**
     * Direct children of the given cosmic-address node, for the next dropdown.
     * Includes disabled rows so the UI can grey them out with a "coming soon" tooltip.
     */
    public function children(string $id): JsonResponse
    {
        $parent = CosmicAddress::findOrFail($id);

        $children = $parent->children()->get([
            'id', 'parent_id', 'label', 'slug', 'type', 'subtype', 'enabled', 'sort_order',
        ]);

        return response()->json(['children' => $children]);
    }
}

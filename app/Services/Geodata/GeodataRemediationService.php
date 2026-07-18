<?php

namespace App\Services\Geodata;

use App\Models\GeodataFlag;
use App\Models\GeodataRepair;
use App\Models\InstanceSettings;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GeodataRemediationService — the WRITE side of the geodata repair plane.
 *
 * GeodataFlagService detects (scan → geodata_flags); this service repairs.
 * Every repair:
 *
 *   1. Is only legal inside the REPAIR WINDOW — instance setup incomplete
 *      (setup_completed_at NULL) AND map data not yet accepted
 *      (map_accepted_at NULL). Outside the window every method throws.
 *      This is a principled context, not a dev flag: once the operator
 *      accepts the dataset it is the constitutional substrate and only
 *      reopening the gate (while setup is still incomplete) unlocks it.
 *
 *   2. Runs in a single DB transaction — a half-applied merge chain or
 *      cascade prune must never survive an error.
 *
 *   3. Writes a geodata_repairs ledger row whose `params` captures the FULL
 *      prior state of every touched row, so revert() can restore it, and
 *      whose inputs (slugs, not ids) make the row replayable on another
 *      box via geodata:repairs-apply.
 *
 *   4. Resolves the linked flag (status='resolved' + resolution payload)
 *      when one is given, and appends a hash-chained audit entry.
 *
 * Repair semantics follow the operator rulings — notably merge_chain is
 * TOPMOST-OWNS: the first slug survives, lower members are soft-deleted
 * with merged_into_id pointing at the survivor, and the deepest member's
 * children become the survivor's direct children.
 */
class GeodataRemediationService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    // ─── Repairs ─────────────────────────────────────────────────────────────

    /**
     * Re-anchor a jurisdiction under a new parent (parent_assigned_via='manual').
     */
    public function reparent(?GeodataFlag $flag, string $targetSlug, string $newParentSlug, ?string $note, ?string $actorId): GeodataRepair
    {
        return DB::transaction(function () use ($flag, $targetSlug, $newParentSlug, $note, $actorId) {
            $this->assertRepairWindowOpen();
            $target    = $this->liveRowBySlug($targetSlug);
            $newParent = $this->liveRowBySlug($newParentSlug);

            if ($target->id === $newParent->id) {
                throw new \InvalidArgumentException('A jurisdiction cannot be its own parent.');
            }
            if ($target->parent_id === $newParent->id) {
                throw new \InvalidArgumentException("[{$targetSlug}] is already parented to [{$newParentSlug}].");
            }
            // Cycle guard: the new parent must not sit anywhere below the target.
            if (in_array($target->id, $this->ancestorIds($newParent->id), true)) {
                throw new \InvalidArgumentException(
                    "Reparenting [{$targetSlug}] under [{$newParentSlug}] would create a cycle — the new parent is a descendant of the target."
                );
            }

            $oldParent = $target->parent_id
                ? DB::table('jurisdictions')->where('id', $target->parent_id)->first()
                : null;

            DB::table('jurisdictions')->where('id', $target->id)->update([
                'parent_id'           => $newParent->id,
                'parent_assigned_via' => 'manual',
                'updated_at'          => now(),
            ]);

            $repair = $this->recordRepair($flag, 'reparent', $target, [
                'target_id'               => $target->id,
                'new_parent_slug'         => $newParentSlug,
                'new_parent_id'           => $newParent->id,
                'old_parent_id'           => $target->parent_id,
                'old_parent_slug'         => $oldParent?->slug,
                'old_parent_assigned_via' => $target->parent_assigned_via,
                'note'                    => $note,
            ], [
                'target_id'     => $target->id,
                'old_parent_id' => $target->parent_id,
                'new_parent_id' => $newParent->id,
            ], $actorId, $target->id);

            return $repair;
        });
    }

    /**
     * Synthesize a missing intermediate anchor row (e.g. a mis-anchored ADM2
     * cluster with no ADM1 above it): a new jurisdictions row directly under
     * $parentSlug whose geometry/population are the union/sum of the given
     * children, which are then reparented onto it. Mirrors the ETL's
     * synthetic-country pattern (source tag + 1:1 constitutional_settings row).
     */
    public function synthesizeAnchor(?GeodataFlag $flag, string $parentSlug, string $name, array $childSlugs, ?string $note, ?string $actorId): GeodataRepair
    {
        $childSlugs = array_values(array_unique(array_map('strval', $childSlugs)));
        if ($childSlugs === []) {
            throw new \InvalidArgumentException('synthesizeAnchor needs at least one child slug.');
        }
        if (trim($name) === '') {
            throw new \InvalidArgumentException('The anchor needs a name.');
        }

        return DB::transaction(function () use ($flag, $parentSlug, $name, $childSlugs, $note, $actorId) {
            $this->assertRepairWindowOpen();
            $parent   = $this->liveRowBySlug($parentSlug);
            $children = $this->liveRowsBySlugs($childSlugs);

            $parentAncestry = array_merge([$parent->id], $this->ancestorIds($parent->id));
            $isos = [];
            $priorChildren = [];
            foreach ($children as $child) {
                if ($child->id === $parent->id) {
                    throw new \InvalidArgumentException("[{$child->slug}] cannot be both the parent and a child of the anchor.");
                }
                // Cycle guard: a child that sits ABOVE the new parent would
                // loop the tree once reparented under the anchor.
                if (in_array($child->id, $parentAncestry, true)) {
                    throw new \InvalidArgumentException("[{$child->slug}] is an ancestor of [{$parentSlug}] — anchoring it underneath would create a cycle.");
                }
                if ($child->iso_code !== null) {
                    $isos[$child->iso_code] = true;
                }
                $priorChildren[] = [
                    'id'                      => $child->id,
                    'slug'                    => $child->slug,
                    'old_parent_id'           => $child->parent_id,
                    'old_parent_assigned_via' => $child->parent_assigned_via,
                ];
            }
            if (count($isos) !== 1) {
                throw new \InvalidArgumentException(
                    'The children must share a single iso_code (got: ' . (implode(', ', array_keys($isos)) ?: 'none') . ') — the anchor inherits it.'
                );
            }
            $iso      = array_key_first($isos);
            $admLevel = (int) $parent->adm_level + 1;
            $slug     = $this->uniqueSlug(strtolower($iso) . '-' . $admLevel . '-' . (Str::slug($name) ?: 'anchor'));
            $id       = (string) Str::uuid();

            // One statement builds the row FROM the children: MultiPolygon
            // union geometry, centroid, summed population. source =
            // 'synthetic_repair' distinguishes operator-era anchors from the
            // ETL's 'synthetic' country rows. official_languages / timezone
            // ride their DB defaults, same as every geoBoundaries import.
            $placeholders = implode(',', array_fill(0, count($childSlugs), '?'));
            $inserted = DB::affectingStatement("
                WITH u AS (
                    SELECT ST_Multi(ST_Union(c.geom))     AS geom,
                           COALESCE(SUM(c.population), 0) AS pop
                    FROM jurisdictions c
                    WHERE c.slug IN ({$placeholders}) AND c.deleted_at IS NULL
                )
                INSERT INTO jurisdictions (
                    id, name, slug, iso_code, adm_level, parent_id,
                    source, parent_assigned_via,
                    population, population_assigned_via,
                    geom, centroid, created_at, updated_at
                )
                SELECT ?, ?, ?, ?, ?, ?,
                       'synthetic_repair', 'manual',
                       u.pop, 'manual_repair',
                       u.geom, ST_Centroid(u.geom), NOW(), NOW()
                FROM u
                WHERE u.geom IS NOT NULL
            ", [...$childSlugs, $id, $name, $slug, $iso, $admLevel, $parent->id]);
            if (! $inserted) {
                throw new \InvalidArgumentException('The children carry no geometry — cannot synthesize an anchor from them.');
            }

            // Mirror the ETL's 1:1 constitutional_settings creation
            // (scripts/etl/db.py bulk_insert_constitutional_settings): all
            // values ride the DB-side defaults; idempotent on conflict.
            DB::statement('
                INSERT INTO constitutional_settings (jurisdiction_id, created_at, updated_at)
                VALUES (?, NOW(), NOW())
                ON CONFLICT (jurisdiction_id) DO NOTHING
            ', [$id]);

            DB::table('jurisdictions')
                ->whereIn('slug', $childSlugs)
                ->whereNull('deleted_at')
                ->where('id', '!=', $id)
                ->update([
                    'parent_id'           => $id,
                    'parent_assigned_via' => 'manual',
                    'updated_at'          => now(),
                ]);

            $anchor = DB::table('jurisdictions')->where('id', $id)->first();

            return $this->recordRepair($flag, 'synthesize_anchor', $anchor, [
                'anchor_id'   => $id,
                'slug'        => $slug,
                'name'        => $name,
                'iso_code'    => $iso,
                'adm_level'   => $admLevel,
                'parent_slug' => $parentSlug,
                'parent_id'   => $parent->id,
                'child_slugs' => $childSlugs,
                'children'    => $priorChildren,
                'note'        => $note,
            ], [
                'anchor_id'         => $id,
                'anchor_slug'       => $slug,
                'population'        => (int) $anchor->population,
                'children_anchored' => count($priorChildren),
            ], $actorId, $id);
        });
    }

    /**
     * Collapse a single-child same-space chain — TOPMOST-OWNS (operator
     * ruling): the first slug survives with its own geometry/population;
     * every lower member has its non-chain children re-anchored onto the
     * survivor, gets merged_into_id = survivor, and is soft-deleted. Result:
     * no live row points at a deleted parent, and the survivor's direct
     * children are the deepest member's former children (plus anything the
     * middles held outside the chain).
     *
     * @param  list<string>  $chainSlugs  topmost first
     */
    public function mergeChain(?GeodataFlag $flag, array $chainSlugs, ?string $note, ?string $actorId): GeodataRepair
    {
        $chainSlugs = array_values(array_map('strval', $chainSlugs));
        if (count($chainSlugs) < 2) {
            throw new \InvalidArgumentException('mergeChain needs at least two slugs (topmost first).');
        }
        if (count($chainSlugs) !== count(array_unique($chainSlugs))) {
            throw new \InvalidArgumentException('The chain contains a duplicate slug.');
        }

        return DB::transaction(function () use ($flag, $chainSlugs, $note, $actorId) {
            $this->assertRepairWindowOpen();
            $members = $this->liveRowsBySlugs($chainSlugs);
            $bySlug  = [];
            foreach ($members as $m) {
                $bySlug[$m->slug] = $m;
            }
            $ordered = array_map(fn (string $s) => $bySlug[$s], $chainSlugs);

            // Validate the chain really is single-child + same-space,
            // top → bottom, BEFORE touching anything.
            for ($i = 0; $i < count($ordered) - 1; $i++) {
                $upper = $ordered[$i];
                $lower = $ordered[$i + 1];

                if ($lower->parent_id !== $upper->id) {
                    throw new \InvalidArgumentException("[{$lower->slug}] is not a child of [{$upper->slug}] — the chain must be listed topmost first.");
                }
                $liveChildren = (int) DB::table('jurisdictions')
                    ->where('parent_id', $upper->id)
                    ->whereNull('deleted_at')
                    ->count();
                if ($liveChildren !== 1) {
                    throw new \InvalidArgumentException("[{$upper->slug}] has {$liveChildren} live children — a mergeable chain member must have exactly one (the next member).");
                }
                if (! $this->sameSpace($upper->id, $lower->id)) {
                    throw new \InvalidArgumentException("[{$upper->slug}] and [{$lower->slug}] do not share the same footprint — not a same-space chain.");
                }
            }

            $survivor = $ordered[0];
            $memberStates  = [];
            $movedChildren = [];

            foreach (array_slice($ordered, 1) as $member) {
                // Re-anchor the member's live children that are NOT chain
                // members onto the survivor (the deepest member's children —
                // and any out-of-chain children a middle held).
                $outsideChildren = DB::table('jurisdictions')
                    ->where('parent_id', $member->id)
                    ->whereNull('deleted_at')
                    ->whereNotIn('slug', $chainSlugs)
                    ->get(['id', 'slug', 'parent_id', 'parent_assigned_via']);

                foreach ($outsideChildren as $child) {
                    $movedChildren[] = [
                        'id'                      => $child->id,
                        'slug'                    => $child->slug,
                        'old_parent_id'           => $child->parent_id,
                        'old_parent_assigned_via' => $child->parent_assigned_via,
                    ];
                }
                if ($outsideChildren->isNotEmpty()) {
                    $this->updateByIdChunks($outsideChildren->pluck('id')->all(), [
                        'parent_id'           => $survivor->id,
                        'parent_assigned_via' => 'manual',
                        'updated_at'          => now(),
                    ]);
                }

                $memberStates[] = [
                    'id'                  => $member->id,
                    'slug'                => $member->slug,
                    'parent_id'           => $member->parent_id,
                    'parent_assigned_via' => $member->parent_assigned_via,
                ];
                DB::table('jurisdictions')->where('id', $member->id)->update([
                    'merged_into_id' => $survivor->id,
                    'deleted_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            return $this->recordRepair($flag, 'merge_chain', $survivor, [
                'chain_slugs'    => $chainSlugs,
                'survivor_id'    => $survivor->id,
                'survivor_slug'  => $survivor->slug,
                'merged_members' => $memberStates,
                'moved_children' => $movedChildren,
                'note'           => $note,
            ], [
                'survivor_id'     => $survivor->id,
                'members_merged'  => count($memberStates),
                'children_moved'  => count($movedChildren),
            ], $actorId, $survivor->id);
        });
    }

    /**
     * Soft-delete a jurisdiction — with cascade=true, its whole live subtree
     * (every affected slug is recorded in params so revert can restore it).
     * Refuses a non-cascade prune of a row that still has live children:
     * that would strand them as orphans.
     */
    public function softPrune(?GeodataFlag $flag, string $targetSlug, bool $cascade, ?string $note, ?string $actorId): GeodataRepair
    {
        return DB::transaction(function () use ($flag, $targetSlug, $cascade, $note, $actorId) {
            $this->assertRepairWindowOpen();
            $target = $this->liveRowBySlug($targetSlug);

            $liveChildren = (int) DB::table('jurisdictions')
                ->where('parent_id', $target->id)
                ->whereNull('deleted_at')
                ->count();
            if ($liveChildren > 0 && ! $cascade) {
                throw new \InvalidArgumentException(
                    "[{$targetSlug}] has {$liveChildren} live children — pass cascade=true to prune the whole subtree, or reparent them first."
                );
            }

            if ($cascade) {
                // Depth-capped like ancestorIds (a corrupt parent loop must
                // not hang the statement; real depth is far under 32) and
                // DISTINCT (a traversed cycle must not duplicate ledger rows).
                $subtree = DB::select('
                    WITH RECURSIVE sub AS (
                        SELECT id, slug, 1 AS depth FROM jurisdictions WHERE id = ? AND deleted_at IS NULL
                        UNION ALL
                        SELECT j.id, j.slug, sub.depth + 1 FROM jurisdictions j
                        JOIN sub ON j.parent_id = sub.id
                        WHERE j.deleted_at IS NULL AND sub.depth < 32
                    )
                    SELECT DISTINCT id::text, slug FROM sub
                ', [$target->id]);
            } else {
                $subtree = [(object) ['id' => $target->id, 'slug' => $target->slug]];
            }

            $pruned = array_map(fn ($r) => ['id' => $r->id, 'slug' => $r->slug], $subtree);
            $this->updateByIdChunks(array_column($pruned, 'id'), ['deleted_at' => now(), 'updated_at' => now()]);

            return $this->recordRepair($flag, 'prune', $target, [
                'target_id' => $target->id,
                'cascade'   => $cascade,
                'pruned'    => $pruned,
                'note'      => $note,
            ], [
                'target_id'   => $target->id,
                'rows_pruned' => count($pruned),
            ], $actorId, $target->id);
        });
    }

    /**
     * Recompute a jurisdiction's population — children_sum (live direct
     * children) or raster_total (the WorldPop raster total for its iso).
     * Stamps population_assigned_via='manual_repair'.
     */
    public function recomputePopulation(?GeodataFlag $flag, string $targetSlug, string $method, ?string $note, ?string $actorId): GeodataRepair
    {
        if (! in_array($method, ['children_sum', 'raster_total'], true)) {
            throw new \InvalidArgumentException("Unknown recompute method [{$method}] — use children_sum or raster_total.");
        }

        return DB::transaction(function () use ($flag, $targetSlug, $method, $note, $actorId) {
            $this->assertRepairWindowOpen();
            $target = $this->liveRowBySlug($targetSlug);
            $new    = $this->computePopulation($target, $method);

            DB::table('jurisdictions')->where('id', $target->id)->update([
                'population'              => $new,
                'population_assigned_via' => 'manual_repair',
                'updated_at'              => now(),
            ]);

            return $this->recordRepair($flag, 'recompute_population', $target, [
                'target_id'                   => $target->id,
                'method'                      => $method,
                'old_population'              => $target->population !== null ? (int) $target->population : null,
                'old_population_assigned_via' => $target->population_assigned_via,
                'note'                        => $note,
            ], [
                'target_id'      => $target->id,
                'method'         => $method,
                'new_population' => $new,
            ], $actorId, $target->id);
        });
    }

    /**
     * Accept a flag as-is — "this is fine" (e.g. a genuine dual-footprint
     * territory). No jurisdictions row changes; the flag leaves the open
     * queue and its fingerprint survives future rescans.
     */
    public function acceptFlag(GeodataFlag $flag, ?string $note, ?string $actorId): GeodataFlag
    {
        if ($flag->status !== 'open') {
            throw new \InvalidArgumentException("Flag [{$flag->id}] is {$flag->status} — only open flags can be accepted.");
        }

        return DB::transaction(function () use ($flag, $note, $actorId) {
            $this->assertRepairWindowOpen();
            $flag->forceFill([
                'status'      => 'accepted',
                'resolution'  => ['action' => 'accept_flag', 'note' => $note],
                'resolved_at' => now(),
                'resolved_by' => $actorId,
            ])->save();

            $this->audit->append('geodata', 'flag.accepted', [
                'flag_id'  => (string) $flag->id,
                'category' => $flag->category,
                'severity' => $flag->severity,
                'note'     => $note,
            ], 'GEODATA-REPAIR', $actorId, $flag->jurisdiction_id);

            return $flag->refresh();
        });
    }

    /**
     * Revert an applied repair from the prior state captured in params.
     * Supported: reparent, recompute_population, prune, merge_chain.
     * synthesize_anchor is not revertible (prune the anchor instead —
     * the children's prior parents are usually the very orphan state the
     * anchor fixed).
     */
    /**
     * Rename a jurisdiction's display label (2026-07-18: country anchors
     * that inherited a constituent's name — India as "Puducherry", Italy as
     * "Nord-Ovest"). Label-only: slug, lineage, geometry, population are
     * untouched, so districting math cannot move.
     */
    public function rename(?GeodataFlag $flag, string $targetSlug, string $newName, ?string $note, ?string $actorId): GeodataRepair
    {
        return DB::transaction(function () use ($flag, $targetSlug, $newName, $note, $actorId) {
            $this->assertRepairWindowOpen();
            $target = $this->liveRowBySlug($targetSlug);

            if ($target->name === $newName) {
                throw new \InvalidArgumentException("[{$targetSlug}] is already named \"{$newName}\".");
            }

            DB::table('jurisdictions')->where('id', $target->id)->update([
                'name'       => $newName,
                'updated_at' => now(),
            ]);

            return $this->recordRepair($flag, 'rename', $target, [
                'target_id' => $target->id,
                'old_name'  => $target->name,
                'new_name'  => $newName,
                'note'      => $note,
            ], [
                'target_id' => $target->id,
                'new_name'  => $newName,
            ], $actorId, $target->id);
        });
    }

    /**
     * Synthesize the REMAINDER child for one coverage-gapped parent
     * (geometry = parent − children-union, population = parent −
     * children-sum) — the per-parent replay form of
     * geodata:synthesize-remainders (which bulk-derives the same repair;
     * keep the CTE and thresholds in lockstep with that command). Refuses
     * the settled population-noise class: children that tile the territory
     * leave no geometric remainder to hold.
     */
    public function synthesizeRemainder(?GeodataFlag $flag, string $parentSlug, ?string $note, ?string $actorId, float $minAreaFrac = 0.02): GeodataRepair
    {
        return DB::transaction(function () use ($flag, $parentSlug, $note, $actorId, $minAreaFrac) {
            $this->assertRepairWindowOpen();
            $parent = $this->liveRowBySlug($parentSlug);

            $existing = DB::table('jurisdictions')
                ->where('parent_id', $parent->id)
                ->where('source', 'synthesized-remainder')
                ->whereNull('deleted_at')
                ->exists();
            if ($existing) {
                throw new \InvalidArgumentException("[{$parentSlug}] already has a synthesized remainder child.");
            }

            $childSum = (int) DB::table('jurisdictions')
                ->where('parent_id', $parent->id)->whereNull('deleted_at')->sum('population');
            $remainderPop = max(0, (int) $parent->population - $childSum);

            $id = (string) \Illuminate\Support\Str::uuid();
            $inserted = DB::insert('
                WITH kids AS (
                    SELECT ST_MakeValid(ST_Union(geom)) AS g
                      FROM jurisdictions
                     WHERE parent_id = ? AND deleted_at IS NULL AND geom IS NOT NULL
                ),
                diff AS (
                    SELECT ST_Multi(ST_CollectionExtract(
                               ST_MakeValid(ST_Difference((SELECT geom FROM jurisdictions WHERE id = ?), kids.g)), 3
                           )) AS g
                      FROM kids
                )
                INSERT INTO jurisdictions
                    (id, name, slug, iso_code, adm_level, parent_id, population,
                     is_active, is_civic_active, source, parent_assigned_via,
                     official_languages, timezone, geom, centroid, created_at, updated_at)
                SELECT ?, ?, ?, ?, ?, ?, ?, true, true, \'synthesized-remainder\', \'remainder_synthesis\',
                       \'[]\', \'UTC\', d.g, ST_PointOnSurface(d.g), now(), now()
                  FROM diff d
                 WHERE d.g IS NOT NULL AND NOT ST_IsEmpty(d.g)
                   AND ST_Area(d.g) >= ? * ST_Area((SELECT geom FROM jurisdictions WHERE id = ?))
            ', [
                $parent->id, $parent->id,
                $id,
                mb_substr($parent->name, 0, 200).' (Remainder)',
                \Illuminate\Support\Str::slug($parent->slug.'-remainder-'.substr($id, 0, 8)),
                $parent->iso_code,
                (int) $parent->adm_level + 1,
                $parent->id,
                $remainderPop,
                $minAreaFrac, $parent->id,
            ]);

            $created = DB::table('jurisdictions')->where('id', $id)->exists();
            if (! $created) {
                throw new \InvalidArgumentException(
                    "[{$parentSlug}] has no material geometric remainder — its children tile the territory "
                    .'(the settled population-noise class is never synthesized).'
                );
            }

            return $this->recordRepair($flag, 'synthesize_remainder', $parent, [
                'target_id'     => $parent->id,
                'parent_id'     => $parent->id,
                'created_id'    => $id,
                'remainder_pop' => $remainderPop,
                'children_sum'  => $childSum,
                'parent_pop'    => (int) $parent->population,
                'revert'        => 'soft-delete created_id',
                'note'          => $note,
            ], [
                'created_id' => $id,
            ], $actorId, $parent->id);
        });
    }

    public function revert(GeodataRepair $repair, ?string $actorId): GeodataRepair
    {
        if ($repair->reverted_at !== null) {
            throw new \InvalidArgumentException("Repair [{$repair->id}] was already reverted.");
        }

        return DB::transaction(function () use ($repair, $actorId) {
            $this->assertRepairWindowOpen();
            $params = $repair->params ?? [];

            // Each branch first PROVES the tree still looks the way this
            // repair left it — a blind restore of captured prior state after
            // LATER repairs touched the same rows would clobber those repairs
            // (and can resurrect rows under since-pruned parents). Revert in
            // reverse order of application when stacking repairs.
            switch ($repair->action) {
                case 'reparent':
                    $current = DB::table('jurisdictions')->where('id', $params['target_id'])->first();
                    if (! $current || $current->parent_id !== $params['new_parent_id']) {
                        throw new \InvalidArgumentException(
                            "[{$repair->target_slug}] no longer sits under the parent this repair assigned — a later change touched it; revert that change first."
                        );
                    }
                    if ($params['old_parent_id'] !== null) {
                        $oldLive = DB::table('jurisdictions')->where('id', $params['old_parent_id'])->whereNull('deleted_at')->exists();
                        if (! $oldLive) {
                            throw new \InvalidArgumentException(
                                "The original parent of [{$repair->target_slug}] is no longer live — reverting would strand it under a deleted row."
                            );
                        }
                    }
                    DB::table('jurisdictions')->where('id', $params['target_id'])->update([
                        'parent_id'           => $params['old_parent_id'],
                        'parent_assigned_via' => $params['old_parent_assigned_via'],
                        'updated_at'          => now(),
                    ]);
                    break;

                case 'recompute_population':
                    $via = DB::table('jurisdictions')->where('id', $params['target_id'])->value('population_assigned_via');
                    if ($via !== 'manual_repair') {
                        throw new \InvalidArgumentException(
                            "[{$repair->target_slug}]'s population was changed again after this repair — nothing safe to revert to."
                        );
                    }
                    DB::table('jurisdictions')->where('id', $params['target_id'])->update([
                        'population'              => $params['old_population'],
                        'population_assigned_via' => $params['old_population_assigned_via'],
                        'updated_at'              => now(),
                    ]);
                    break;

                case 'prune':
                    $prunedIds = array_column($params['pruned'] ?? [], 'id');
                    foreach (array_chunk($prunedIds, 5000) as $chunk) {
                        $restored = DB::table('jurisdictions')->whereIn('id', $chunk)
                            ->whereNull('deleted_at')->count();
                        if ($restored > 0) {
                            throw new \InvalidArgumentException(
                                'Some pruned rows were since restored by another action — the prune is no longer cleanly revertible.'
                            );
                        }
                    }
                    $this->updateByIdChunks($prunedIds, ['deleted_at' => null, 'updated_at' => now()]);
                    break;

                case 'merge_chain':
                    $memberIds = array_column($params['merged_members'] ?? [], 'id');
                    $notMerged = DB::table('jurisdictions')->whereIn('id', $memberIds)
                        ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('merged_into_id', '!=', $params['survivor_id']))
                        ->count();
                    if ($notMerged > 0) {
                        throw new \InvalidArgumentException(
                            'The merged members no longer carry this merge\'s state — a later change touched them; revert it first.'
                        );
                    }
                    foreach ($params['moved_children'] ?? [] as $child) {
                        $cur = DB::table('jurisdictions')->where('id', $child['id'])->value('parent_id');
                        if ($cur !== $params['survivor_id']) {
                            throw new \InvalidArgumentException(
                                "Moved child [{$child['slug']}] was re-parented again after the merge — revert that change first."
                            );
                        }
                    }
                    // Un-delete the members and clear the survivor pointer…
                    $this->updateByIdChunks($memberIds, ['deleted_at' => null, 'merged_into_id' => null, 'updated_at' => now()]);
                    // …then hand the moved children back to their original parents.
                    foreach ($params['moved_children'] ?? [] as $child) {
                        DB::table('jurisdictions')->where('id', $child['id'])->update([
                            'parent_id'           => $child['old_parent_id'],
                            'parent_assigned_via' => $child['old_parent_assigned_via'],
                            'updated_at'          => now(),
                        ]);
                    }
                    break;

                case 'rename':
                    $currentName = DB::table('jurisdictions')->where('id', $params['target_id'])->value('name');
                    if ($currentName !== ($params['new_name'] ?? null)) {
                        throw new \InvalidArgumentException(
                            "[{$repair->target_slug}] was renamed again after this repair — revert that change first."
                        );
                    }
                    DB::table('jurisdictions')->where('id', $params['target_id'])->update([
                        'name'       => $params['old_name'],
                        'updated_at' => now(),
                    ]);
                    break;

                case 'synthesize_remainder':
                    $live = DB::table('jurisdictions')
                        ->where('id', $params['created_id'])->whereNull('deleted_at')->exists();
                    if (! $live) {
                        throw new \InvalidArgumentException(
                            "The synthesized remainder of [{$repair->target_slug}] is no longer live — nothing to revert."
                        );
                    }
                    $hasChildren = DB::table('jurisdictions')
                        ->where('parent_id', $params['created_id'])->whereNull('deleted_at')->exists();
                    if ($hasChildren) {
                        throw new \InvalidArgumentException(
                            'The synthesized remainder has since acquired children — reverting would strand them.'
                        );
                    }
                    DB::table('jurisdictions')->where('id', $params['created_id'])->update([
                        'deleted_at' => now(), 'updated_at' => now(),
                    ]);
                    break;

                default:
                    throw new \InvalidArgumentException("Repair action [{$repair->action}] is not revertible.");
            }

            // Reopen the flag this repair resolved (never an operator-ACCEPTED
            // one): the defect exists in the data again, and a resolved
            // fingerprint would otherwise suppress its re-detection on every
            // future scan — permanently invisible to the queue AND to the
            // acceptMaps acknowledgment gate.
            if ($repair->flag_id !== null) {
                DB::table('geodata_flags')
                    ->where('id', $repair->flag_id)
                    ->where('status', 'resolved')
                    ->update([
                        'status'      => 'open',
                        'resolution'  => null,
                        'resolved_at' => null,
                        'resolved_by' => null,
                        'updated_at'  => now(),
                    ]);
            }

            $repair->forceFill(['reverted_at' => now()])->save();

            $this->audit->append('geodata', 'repair.reverted', [
                'repair_id'   => (string) $repair->id,
                'action'      => $repair->action,
                'target_slug' => $repair->target_slug,
            ], 'GEODATA-REPAIR', $actorId);

            return $repair->refresh();
        });
    }

    // ─── Shared internals ────────────────────────────────────────────────────

    /**
     * The repair window: setup incomplete AND map data not yet accepted.
     * Once the operator accepts the dataset (or completes setup) the plane
     * locks; POST /api/jurisdictions/reopen-maps can clear map_accepted_at
     * while setup is still incomplete.
     */
    private function assertRepairWindowOpen(): void
    {
        // Locked read, called as the FIRST statement inside each repair's
        // DB::transaction: serializes against acceptMaps/reopenMaps flipping
        // the gate mid-repair (they update this same singleton row inside
        // their own transactions), closing the check-then-write race.
        $instance = DB::table('instance_settings')->whereNull('deleted_at')->lockForUpdate()->first();
        if ($instance === null || $instance->setup_completed_at !== null || $instance->map_accepted_at !== null) {
            throw new \RuntimeException('repair window closed');
        }
    }

    /**
     * Chunked bulk update — whereIn binds one parameter per id and PostgreSQL
     * caps a statement at 65,535 binds; a large-country cascade (L6 subtrees
     * run to tens of thousands of rows) exceeds that in one statement.
     * Callers are already inside DB::transaction, so chunking stays atomic.
     *
     * @param  list<string>  $ids
     */
    private function updateByIdChunks(array $ids, array $values): void
    {
        foreach (array_chunk($ids, 5000) as $chunk) {
            DB::table('jurisdictions')->whereIn('id', $chunk)->update($values);
        }
    }

    /**
     * Ledger + flag resolution + audit — the shared tail of every repair.
     */
    private function recordRepair(
        ?GeodataFlag $flag,
        string $action,
        object $target,
        array $params,
        array $result,
        ?string $actorId,
        ?string $jurisdictionId,
    ): GeodataRepair {
        if ($flag !== null && $flag->status !== 'open') {
            // A repair may only resolve an OPEN flag — silently overwriting an
            // accepted or already-resolved flag's resolution would erase the
            // operator's earlier judgment.
            throw new \InvalidArgumentException(
                "Flag [{$flag->id}] is {$flag->status} — only open flags can be resolved by a repair."
            );
        }

        $repair = new GeodataRepair;
        $repair->forceFill([
            'flag_id'                 => $flag?->id,
            'action'                  => $action,
            'target_slug'             => $target->slug,
            'target_geoboundaries_id' => $target->geoboundaries_id ?? null,
            'params'                  => $params,
            'result'                  => $result,
            'applied_by'              => $actorId,
            'applied_at'              => now(),
        ])->save();

        if ($flag !== null) {
            $flag->forceFill([
                'status'      => 'resolved',
                'resolution'  => [
                    'action'    => $action,
                    'repair_id' => (string) $repair->id,
                    'note'      => $params['note'] ?? null,
                ],
                'resolved_at' => now(),
                'resolved_by' => $actorId,
            ])->save();
        }

        $this->audit->append('geodata', 'repair.' . $action, [
            'repair_id'   => (string) $repair->id,
            'flag_id'     => $flag ? (string) $flag->id : null,
            'target_slug' => $target->slug,
            'result'      => $result,
            'note'        => $params['note'] ?? null,
        ], 'GEODATA-REPAIR', $actorId, $jurisdictionId);

        return $repair;
    }

    private function liveRowBySlug(string $slug): object
    {
        $row = DB::table('jurisdictions')->where('slug', $slug)->whereNull('deleted_at')->first();
        if (! $row) {
            throw new \InvalidArgumentException("Unknown or deleted jurisdiction slug [{$slug}].");
        }

        return $row;
    }

    /**
     * @param  list<string>  $slugs
     * @return list<object>
     */
    private function liveRowsBySlugs(array $slugs): array
    {
        $rows = DB::table('jurisdictions')
            ->whereIn('slug', $slugs)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('slug');

        $missing = array_values(array_diff($slugs, $rows->keys()->all()));
        if ($missing !== []) {
            throw new \InvalidArgumentException('Unknown or deleted jurisdiction slug(s): ' . implode(', ', $missing) . '.');
        }

        return $rows->values()->all();
    }

    /**
     * Ancestor id chain (parent, grandparent, …) — cycle guards. Bounded by
     * a depth cap so a pre-existing corrupt loop cannot hang the request.
     *
     * @return list<string>
     */
    private function ancestorIds(string $id): array
    {
        $rows = DB::select('
            WITH RECURSIVE anc AS (
                SELECT id, parent_id, 1 AS depth FROM jurisdictions WHERE id = ?
                UNION ALL
                SELECT j.id, j.parent_id, anc.depth + 1
                FROM jurisdictions j
                JOIN anc ON j.id = anc.parent_id
                WHERE anc.depth < 32
            )
            SELECT id::text FROM anc WHERE id != ?
        ', [$id, $id]);

        return array_map(fn ($r) => $r->id, $rows);
    }

    /**
     * Same-space test for chain members: exact ST_Equals, or a symmetric
     * difference under 1% of the larger footprint (geoBoundaries carries
     * per-level digitization drift on genuinely identical shapes).
     */
    private function sameSpace(string $idA, string $idB): bool
    {
        return (bool) DB::scalar('
            SELECT (a.geom IS NULL AND b.geom IS NULL)
                OR ST_Equals(a.geom, b.geom)
                OR (ST_Area(ST_SymDifference(a.geom, b.geom))
                    <= 0.01 * NULLIF(GREATEST(ST_Area(a.geom), ST_Area(b.geom)), 0))
            FROM jurisdictions a, jurisdictions b
            WHERE a.id = ? AND b.id = ?
        ', [$idA, $idB]);
    }

    private function computePopulation(object $target, string $method): int
    {
        if ($method === 'children_sum') {
            return (int) DB::scalar(
                'SELECT COALESCE(SUM(population), 0) FROM jurisdictions WHERE parent_id = ? AND deleted_at IS NULL',
                [$target->id]
            );
        }

        // raster_total — the WorldPop raster sum for the target's iso.
        if ($target->iso_code === null) {
            throw new \InvalidArgumentException("[{$target->slug}] has no iso_code — raster_total needs one to find its WorldPop tiles.");
        }
        if ((int) $target->adm_level > 1) {
            // The iso raster covers the ENTIRE nation — applying its total to
            // a sub-national row would write the whole country's population
            // into a province/county.
            throw new \InvalidArgumentException(
                "[{$target->slug}] is adm_level {$target->adm_level} — raster_total is a whole-country method; use children_sum instead."
            );
        }

        // Latest vintage only (mirrors the canonical population_within
        // functions): summing across years would double the population the
        // moment a second WorldPop year is imported. NULL sum = no tiles for
        // this iso — fail loudly instead of silently writing 0, which is the
        // exact broken state the raster_coverage flag steers away from.
        $sum = DB::scalar(
            'SELECT SUM((ST_SummaryStats(rast)).sum)
             FROM worldpop_rasters
             WHERE iso_code = ?
               AND year = (SELECT MAX(year) FROM worldpop_rasters WHERE iso_code = ?)',
            [$target->iso_code, $target->iso_code]
        );
        if ($sum === null) {
            throw new \InvalidArgumentException(
                "[{$target->slug}] has no WorldPop raster tiles for iso [{$target->iso_code}] — raster_total cannot be computed; use children_sum instead."
            );
        }

        return (int) round((float) $sum);
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $n    = 1;
        // Includes soft-deleted rows — the unique index on slug covers all.
        while (DB::table('jurisdictions')->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }
}

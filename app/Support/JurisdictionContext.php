<?php

namespace App\Support;

use App\Models\Jurisdiction;
use App\Models\CosmicAddress;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * THE VIEWED PLACE — the shell's persistent jurisdiction context (operator
 * 2026-09-10: "breadcrumbs for the jurisdiction I'm viewing to persist").
 *
 * One shape, one owner. A jurisdiction-scoped page passes
 * `'jurisdictionContext' => JurisdictionContext::for($jurisdiction)` and the
 * shell (AppShellV2 / AppShell) renders the JurisdictionSwitcher from it: the
 * chain root → the viewed place, every chip a link to that place's own page.
 * Pages that are not about a place pass nothing, and the shell falls back to
 * the viewer's HOME chain (HandleInertiaRequests::jurisdictionProps), so the
 * bar never disappears. The page prop `jurisdiction` (the model) no longer
 * shadows anything: the shell reads `homeJurisdiction` for the fallback.
 *
 * Chain item shape = the switcher's prop contract: { id, name, slug, admLevel }.
 */
final class JurisdictionContext
{
    /** Explicit observation scope. This never establishes residency or action standing. */
    public static function requested(Request $request): ?Jurisdiction
    {
        $request->validate(['jurisdiction' => ['nullable', 'string', 'max:255']]);
        if (! $request->filled('jurisdiction')) {
            return null;
        }
        $key = trim($request->string('jurisdiction')->toString());

        return Jurisdiction::query()->where(Str::isUuid($key) ? 'id' : 'slug', $key)
            ->firstOrFail(['id', 'name', 'slug', 'parent_id', 'adm_level']);
    }

    /**
     * @return array{current: array{id:string,name:string,slug:string,admLevel:int}, chain: list<array{id:string,name:string,slug:string,admLevel:int}>, cosmicPrefix: string}
     */
    public static function for(Jurisdiction $jurisdiction): array
    {
        $current = self::chip($jurisdiction);
        $chain   = [];
        foreach (($jurisdiction->ancestors ?? []) as $a) {
            $chip = self::chip($a);
            if ($chip !== null) {
                $chain[] = $chip;
            }
        }
        // ancestors are root-first (Jurisdiction::getAncestorsAttribute); the
        // viewed place closes the chain.
        $chain[] = $current;

        return [
            'current'      => $current,
            'chain'        => $chain,
            'cosmicPrefix' => self::cosmicPrefix(),
        ];
    }

    /**
     * The general jurisdiction tools for the rail (operator 2026-09-10: "the
     * side bar like the mapper to have general jurisdiction tools"). Pure:
     * the caller passes the gates it already resolved (the place page and
     * the map viewer share viewerProps). A tool whose institution exists is
     * a link; one that does not is a muted row with the reason in plain
     * words — present, never hidden (complete lists).
     *
     * @param  array{slug:string, id?:string, legislature_id?:?string, executive_id?:?string, judiciary_id?:?string,
     *               has_district_map?:bool, chamber_seated?:bool, current_election?:?array,
     *               childCount?:int, parent_name?:?string}  $g
     * @return list<array{key:string, group:string, label:string, href:?string, state:string, hint:?string, icon:?string}>
     */
    public static function tools(array $g): array
    {
        $slug   = (string) ($g['slug'] ?? '');
        $legId  = $g['legislature_id'] ?? null;
        $isLeaf = (int) ($g['childCount'] ?? 0) === 0;
        $parent = $g['parent_name'] ?? null;
        $leafNote = $parent ? "a leaf place; represented in {$parent}" : 'a leaf place';

        $link  = fn (string $key, string $group, string $label, string $href, ?string $hint = null, ?string $icon = null) =>
            ['key' => $key, 'group' => $group, 'label' => $label, 'href' => $href, 'state' => 'link', 'hint' => $hint, 'icon' => $icon];
        $muted = fn (string $key, string $group, string $label, string $hint, ?string $icon = null) =>
            ['key' => $key, 'group' => $group, 'label' => $label, 'href' => null, 'state' => 'muted', 'hint' => $hint, 'icon' => $icon];

        $tools = [
            $link('overview', 'This place', 'Overview', "/jurisdictions/{$slug}", null, 'landmark'),
            $link('map', 'This place', 'Boundary map', "/jurisdictions/{$slug}/map", $isLeaf ? 'the boundary' : 'the places inside', 'map-pin'),
            $link('places', 'This place', 'Places inside', '/jurisdictions?parent='.rawurlencode($slug), null, 'globe'),
            $link('world', 'This place', 'Browse the world', '/jurisdictions', null, 'globe'),
        ];

        // Its government
        if ($legId !== null) {
            $tools[] = $link('chamber', 'Its government', 'Legislature', "/legislatures/{$legId}/chamber", ! empty($g['chamber_seated']) ? 'seated' : 'forming', 'landmark');
        } else {
            $tools[] = $muted('chamber', 'Its government', 'Legislature', $isLeaf ? $leafNote : 'none yet', 'landmark');
        }
        $tools[] = $legId !== null
            ? $link('districts', 'Its government', 'Legislative maps', "/legislatures/{$legId}/districts", ! empty($g['has_district_map']) ? null : 'not yet drawn', 'map')
            : $muted('districts', 'Its government', 'Legislative maps', $isLeaf ? $leafNote : 'none yet', 'map');
        if (! $isLeaf) {
            $tools[] = $legId !== null
                ? $link('panels', 'Its government', 'Panels', "/legislatures/{$legId}/panels", 'equal seats per constituent', 'building')
                : $muted('panels', 'Its government', 'Panels', 'none yet', 'building');
        }
        $tools[] = ! empty($g['executive_id'])
            ? $link('executive', 'Its government', 'Executive', '/executives/'.$g['executive_id'], null, 'briefcase')
            : $muted('executive', 'Its government', 'Executive', 'none yet', 'briefcase');
        $tools[] = ! empty($g['judiciary_id'])
            ? $link('courts', 'Its government', 'Courts', '/judiciaries/'.$g['judiciary_id'], null, 'scale')
            : $muted('courts', 'Its government', 'Courts', 'none yet', 'scale');
        $el = $g['current_election'] ?? null;
        $tools[] = is_array($el) && ! empty($el['id'])
            ? $link('election', 'Its government', 'Elections', '/elections/'.$el['id'], ! empty($el['status']) && ! in_array($el['status'], ['final', 'cancelled'], true) ? 'under way' : 'the last election', 'vote')
            : $muted('election', 'Its government', 'Elections', 'none scheduled', 'vote');

        // Take part (open to everyone; filing needs residency)
        $tools[] = $link('roles', 'Take part', 'Explore civic roles', '/explore?jurisdiction='.rawurlencode($slug), null, 'users');
        $tools[] = $link('square', 'Take part', 'The public square', '/civic/square?jurisdiction='.rawurlencode($slug), null, 'message-square');
        $tools[] = $link('petitions', 'Take part', 'Petitions', '/civic/petitions?jurisdiction='.rawurlencode($slug), null, 'file-text');
        $tools[] = $link('rooms', 'Take part', 'Live rooms', '/civic/commons/square'.(! empty($g['id']) ? '?jurisdiction='.rawurlencode($g['id']) : ''), null, 'users');

        return $tools;
    }

    /** Normalise a model, stdClass or array row to the switcher's chip shape. */
    public static function chip(mixed $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $get = static fn (string $k) => is_array($row) ? ($row[$k] ?? null) : ($row->{$k} ?? null);
        $id = $get('id');
        if ($id === null) {
            return null;
        }
        $level = $get('admLevel') ?? $get('adm_level') ?? 0;

        return [
            'id'       => (string) $id,
            'name'     => (string) ($get('name') ?? ''),
            'slug'     => (string) ($get('slug') ?? ''),
            'admLevel' => (int) $level,
        ];
    }

    /**
     * The instance's cosmic address line above the chain ("… · Solar System ·
     * Earth"): the enabled leaf world's path, the Multiverse root dropped (a
     * UI header, not a path segment). One owner for the middleware and the
     * page context; cached per request.
     */
    public static function cosmicPrefix(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = '';
        try {
            if (Schema::hasTable('cosmic_addresses')) {
                $leaf = CosmicAddress::query()->where('type', 'world')->where('enabled', true)->orderBy('sort_order')->first();
                if ($leaf !== null) {
                    $cached = collect($leaf->pathFromRoot())
                        ->reject(fn ($row) => ($row['type'] ?? null) === 'multiverse')
                        ->pluck('label')->filter()->implode(' · ');
                }
            }
        } catch (\Throwable) {
            $cached = '';
        }

        return $cached;
    }
}

<?php

namespace App\Services\Node;

use App\Models\InstanceSettings;
use InvalidArgumentException;

/**
 * W-0490 — the node profile: which kits this box runs and the scope of each.
 * The Individual kit is always on. Community runs as a simulation or as a real community.
 * Scope text is free-form (operator ruling 2026-11-02); it is stored, not interpreted here.
 */
class NodeProfileService
{
    public const KITS = ['individual', 'household', 'organization', 'community'];

    public const COMMUNITY_MODES = ['simulation', 'real'];

    /** @return array{hosted: list<string>, scopes: array<string, array<string, string>>} */
    public function read(): array
    {
        $s = InstanceSettings::current();

        return $this->normalize($s->hosted_node_types ?? null, $s->node_scopes ?? null);
    }

    /**
     * @param  array<int, mixed>|null  $hosted
     * @param  array<string, mixed>|null  $scopes
     * @return array{hosted: list<string>, scopes: array<string, array<string, string>>}
     */
    public function normalize(?array $hosted, ?array $scopes): array
    {
        $hosted = array_values(array_unique(array_filter((array) $hosted, fn ($k) => in_array($k, self::KITS, true))));
        if (! in_array('individual', $hosted, true)) {
            array_unshift($hosted, 'individual');
        }
        $hosted = array_values(array_intersect(self::KITS, $hosted));

        $out = [];
        foreach ($hosted as $kit) {
            $row = (array) (($scopes ?? [])[$kit] ?? []);
            $entry = ['scope' => trim((string) ($row['scope'] ?? ''))];
            if ($kit === 'community') {
                $entry['mode'] = in_array($row['mode'] ?? null, self::COMMUNITY_MODES, true) ? $row['mode'] : 'simulation';
            }
            $out[$kit] = $entry;
        }

        return ['hosted' => $hosted, 'scopes' => $out];
    }

    /**
     * @param  array<int, mixed>  $hosted
     * @param  array<string, mixed>  $scopes
     * @return array{hosted: list<string>, scopes: array<string, array<string, string>>}
     */
    public function save(array $hosted, array $scopes, ?string $actorId = null): array
    {
        foreach ($hosted as $kit) {
            if (! in_array($kit, self::KITS, true)) {
                throw new InvalidArgumentException("Unknown node kit: {$kit}");
            }
        }
        foreach ($scopes as $kit => $row) {
            if (isset($row['scope']) && mb_strlen((string) $row['scope']) > 500) {
                throw new InvalidArgumentException("Scope text for {$kit} is longer than 500 characters.");
            }
            if (isset($row['mode']) && ! in_array($row['mode'], self::COMMUNITY_MODES, true)) {
                throw new InvalidArgumentException('Community mode must be simulation or real.');
            }
        }

        $before = $this->read();
        $after = $this->normalize($hosted, $scopes);

        $s = InstanceSettings::current();
        $s->hosted_node_types = $after['hosted'];
        $s->node_scopes = $after['scopes'];
        $s->save();

        try {
            app(\App\Services\AuditService::class)->append(
                'operator',
                'node_profile.changed',
                ['before' => $before, 'after' => $after],
                'instance_settings',
                $actorId,
            );
        } catch (\Throwable) {
            // The profile is saved; a missing audit sink is not fatal on a fixture connection.
        }

        return $after;
    }
}

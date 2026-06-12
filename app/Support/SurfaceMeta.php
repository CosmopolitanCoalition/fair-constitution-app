<?php

namespace App\Support;

use App\Domain\Forms\FormRegistry;
use InvalidArgumentException;

/**
 * SurfaceMeta — assembles the per-page `surface` prop (the production
 * successor of the mockups' window.CGA_PAGE; DESIGN_frontend_port.md §D1).
 *
 * Usage in a controller:
 *
 *     Inertia::render('Civic/Residency', [
 *         'surface' => SurfaceMeta::for('civic/residency'),
 *         ...$pageData,
 *     ]);
 *
 * The registry lives in config/cga/surfaces.php; form names and drifted
 * catalog aliases are resolved from App\Domain\Forms\FormRegistry so the UI
 * and the constitutional-engine validation share ONE canonical form table.
 * Unknown surface ids throw — the production analog of shell.js's
 * crossCheckManifest() warning, enforced instead of logged.
 */
final class SurfaceMeta
{
    /**
     * @return array{
     *     id: string, title: string, module: string, nav: string|null,
     *     roles: list<string>, workflows: list<string>,
     *     forms: list<array{id: string, name: string, alias: string|null, availableTo: list<string>, citation: string|null}>,
     *     clocks: list<string>, citation: string|null
     * }
     */
    public static function for(string $id): array
    {
        $record = config("cga.surfaces.{$id}");

        if (! is_array($record)) {
            throw new InvalidArgumentException(
                "Unknown CGA surface [{$id}] — add it to config/cga/surfaces.php."
            );
        }

        return [
            'id'        => $id,
            'title'     => $record['title'] ?? $id,
            'module'    => $record['module'] ?? '',
            'nav'       => $record['nav'] ?? null,
            'roles'     => $record['roles'] ?? [],
            'workflows' => $record['workflows'] ?? [],
            'forms'     => array_map(self::form(...), $record['forms'] ?? []),
            'clocks'    => $record['clocks'] ?? [],
            'citation'  => $record['citation'] ?? null,
        ];
    }

    /** All registered surface ids (for the registry cross-check test). */
    public static function ids(): array
    {
        return array_keys(config('cga.surfaces', []));
    }

    /**
     * Resolve a registry form entry: name from the canonical FormRegistry,
     * alias = the drifted Workflows-Catalog id when one collides with this
     * canonical form (FormChip renders it as "· catalog: F-XXX-0xx").
     *
     * @param  array{id: string, availableTo?: list<string>, citation?: string|null}  $entry
     */
    private static function form(array $entry): array
    {
        $meta = FormRegistry::meta($entry['id']);

        $drift = array_keys($meta['catalog_drift']);

        return [
            'id'          => $meta['id'],
            'name'        => $meta['name'],
            'alias'       => $drift[0] ?? null,
            'availableTo' => $entry['availableTo'] ?? $meta['roles'],
            'citation'    => $entry['citation'] ?? null,
        ];
    }
}

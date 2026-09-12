<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\OrgConversion;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

/** Public directory: page the indexed roster before reading related records. */
final class OrganizationDirectory
{
    // A presentation page size, not an ETL/worker resource budget.
    public const PAGE_SIZE = 25;

    public const TYPES = ['political_party', 'business', 'nonprofit', 'informal', 'common_good_corp'];

    public static function filters(Request $request): array
    {
        $values = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'structure' => ['nullable', Rule::in(Organization::STRUCTURES)],
            'jurisdiction' => ['nullable', 'string', 'max:255'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);

        return [
            'q' => trim($values['q'] ?? ''),
            'type' => $values['type'] ?? '',
            'structure' => $values['structure'] ?? '',
            'jurisdiction' => $values['jurisdiction'] ?? '',
        ];
    }

    public function page(Request $request, array $filters, ?string $jurisdictionId): array
    {
        $nameKey = DB::getDriverName() === 'pgsql' ? 'lower(name) COLLATE "C"' : 'lower(name)';
        $cursor = null;
        if ($request->filled('cursor')) {
            try {
                $cursor = Cursor::fromEncoded($request->query('cursor'));
                if ($cursor === null || ! is_string($cursor->parameter('directory_name'))
                    || ! Str::isUuid($cursor->parameter('id'))) throw new \InvalidArgumentException;
            } catch (\Throwable) {
                throw ValidationException::withMessages(['cursor' => 'This page link is invalid. Start the search again.']);
            }
        }
        $query = Organization::query()
            ->select(['id', 'name', 'type', 'structure', 'jurisdiction_id', 'worker_count', 'board_id', 'is_cgc', 'status'])
            ->selectRaw($nameKey.' as directory_name')
            ->where('status', '<>', Organization::STATUS_DISSOLVED);

        if ($jurisdictionId !== null) $query->where('jurisdiction_id', $jurisdictionId);
        if ($filters['type'] !== '') $query->where('type', $filters['type']);
        if ($filters['structure'] !== '') $query->where('structure', $filters['structure']);
        if ($filters['q'] !== '') {
            // Prefix matching uses the directory's name index. Treat user
            // wildcards literally; a leading wildcard would scan the planet.
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q']));
            $query->whereRaw($nameKey." LIKE ? ESCAPE '!'", [$prefix.'%']);
        }

        if ($cursor !== null) {
            // Give PostgreSQL a direct composite-index seek. Laravel also
            // emits its portable OR predicate; that alone can filter a long
            // prefix of the index when the reader reaches a late page.
            $query->whereRaw('('.$nameKey.', id) '.($cursor->pointsToNextItems() ? '>' : '<').' (?, ?)', [
                $cursor->parameter('directory_name'), $cursor->parameter('id'),
            ]);
        }

        $page = $query->orderBy('directory_name')->orderBy('id')
            ->cursorPaginate(self::PAGE_SIZE, cursor: $cursor)
            ->withPath('/organizations')->appends(array_filter($filters, fn ($v) => $v !== ''));
        $orgs = $page->getCollection();

        // All enrichment is restricted to the roster already selected above.
        // No full-register counts, per-row queries, or geometry payloads.
        $orgs->load('jurisdiction:id,name,adm_level', 'board:id,worker_seats,owner_seats,composition_valid');
        $ids = $orgs->modelKeys();
        $endorsements = $ids === [] ? collect() : DB::table('endorsements')
            ->where('endorser_type', 'organization')->whereIn('endorser_id', $ids)
            ->where('is_active', true)->groupBy('endorser_id')
            ->selectRaw('endorser_id, count(*) as total')->pluck('total', 'endorser_id');
        $pending = $ids === [] ? [] : DB::table('org_conversions')
            ->whereIn('organization_id', $ids)->whereNull('deleted_at')
            ->where('via', OrgConversion::VIA_MONOPOLY_ACQUISITION)
            ->whereNotIn('status', [OrgConversion::STATUS_COMPLETED, OrgConversion::STATUS_ABANDONED])
            ->distinct()->pluck('organization_id')->all();

        return [
            'organizations' => $orgs->map(fn (Organization $org) => [
                'id' => (string) $org->id,
                'name' => $org->name,
                'type' => $org->type,
                'structure' => $org->structure,
                'jurisdiction' => $org->jurisdiction ? ['name' => $org->jurisdiction->name, 'adm_level' => $org->jurisdiction->adm_level] : null,
                'workers' => (int) $org->worker_count,
                'endorsement_count' => (int) ($endorsements[$org->id] ?? 0),
                'board' => $org->board ? [
                    'worker_seats' => (int) $org->board->worker_seats,
                    'owner_seats' => (int) $org->board->owner_seats,
                    'composition_valid' => (bool) $org->board->composition_valid,
                ] : null,
                'is_cgc' => (bool) $org->is_cgc,
                'status' => $org->status,
                'monopoly_pending' => in_array($org->id, $pending, true),
                'href' => '/organizations/'.$org->id,
            ])->all(),
            'previous' => $page->previousPageUrl(),
            'next' => $page->nextPageUrl(),
            'pageSize' => self::PAGE_SIZE,
        ];
    }
}

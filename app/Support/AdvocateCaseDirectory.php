<?php

namespace App\Support;

use App\Models\CourtCase;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Actor-scoped case browsing; composer reads only its requested page. */
final class AdvocateCaseDirectory
{
    public const PAGE_SIZE = 20;

    public function page(Request $request, ?string $advocateId, string $kind): array
    {
        if (! in_array($kind, ['roster', 'composer'], true)) throw new \InvalidArgumentException('Unknown case directory.');
        $prefix = $kind === 'roster' ? 'case_' : 'compose_case_';
        $input = $request->validate([
            $prefix.'q' => ['nullable', 'string', 'max:160'],
            $prefix.'by' => ['nullable', Rule::in(['title', 'docket'])],
            $prefix.'cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $search = trim($input[$prefix.'q'] ?? '');
        $field = $input[$prefix.'by'] ?? 'title';
        $scope = hash('sha256', $advocateId."\n".$kind."\n".$field."\n".mb_strtolower($search));
        $cursor = $this->cursor($input[$prefix.'cursor'] ?? null, $scope, $prefix.'cursor');
        $params = [$prefix.'q' => $search, $prefix.'by' => $field];
        $path = '/judiciary/advocate';
        $first = $path.'?'.http_build_query($params);
        $metadata = ['query' => $search, 'by' => $field, 'loaded' => true, 'previous' => null, 'next' => null, 'first' => $first];
        if ($advocateId === null) return ['cases' => collect(), 'pagination' => $metadata];

        $column = $field === 'docket' ? 'docket_no' : 'title';
        $name = 'lower(COALESCE('.$column.", ''))".(DB::getDriverName() === 'pgsql' ? ' COLLATE "C"' : '');
        $query = CourtCase::query()->where('advocate_id', $advocateId)->where('filed_via_form', 'F-ADV-001');
        if ($search !== '') {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
            $query->whereRaw($name." LIKE ? ESCAPE '!'", [$literal.'%']);
        }
        if ($cursor !== null) {
            $query->whereRaw('('.$name.', id) '.($cursor->pointsToNextItems() ? '>' : '<').' (?, ?)', [$cursor->parameter('directory_name'), $cursor->parameter('id')]);
        }
        $columns = ['id', 'title', 'docket_no', 'status'];
        if ($kind === 'roster') {
            $columns = [...$columns, 'kind', 'judiciary_id', 'jury_entitled'];
            $query->with(['judiciary:id,court_name', 'panel:id,case_id,size,is_en_banc,status']);
        }
        $page = $query->select($columns)->selectRaw($name.' as directory_name')->orderBy('directory_name')->orderBy('id')
            ->cursorPaginate(self::PAGE_SIZE, cursorName: $prefix.'cursor', cursor: $cursor);
        $url = fn (?Cursor $position) => $position === null ? null : $path.'?'.http_build_query($params + [
            $prefix.'cursor' => (new Cursor(['directory_name' => $position->parameter('directory_name'), 'id' => $position->parameter('id'), 'scope' => $scope], $position->pointsToNextItems()))->encode(),
        ]);

        return ['cases' => $page->getCollection(), 'pagination' => array_replace($metadata, ['previous' => $url($page->previousCursor()), 'next' => $url($page->nextCursor())])];
    }

    private function cursor(?string $encoded, string $scope, string $key): ?Cursor
    {
        if ($encoded === null || $encoded === '') return null;
        try {
            $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || count($data) !== 4 || ($data['scope'] ?? null) !== $scope
                || ! is_string($data['directory_name'] ?? null) || mb_strlen($data['directory_name']) > 500
                || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])
                || ! is_bool($data['_pointsToNextItems'] ?? null)) throw new \InvalidArgumentException;
            return new Cursor(['directory_name' => $data['directory_name'], 'id' => $data['id']], $data['_pointsToNextItems']);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$key => 'This case page link is invalid. Search again or open the first page.']);
        }
    }
}

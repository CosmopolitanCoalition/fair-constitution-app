<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Bounded 25-row cursor paging for the four jurisdiction-lifecycle histories
 * (S2 — union, disintermediation, border, restoration). Each listing carries
 * its OWN cursor parameter name AND a scope-bound token: the encoded seek
 * embeds a scope hash tied to the kind, so a Next link generated on one
 * surface cannot be replayed against another (a cross-scope token is rejected
 * with a validation error, never silently applied — the CivicHistoryDirectory
 * pattern). The seek keys on the compound (created_at, id) so the order stays
 * chronological AND the seek is stable when several rows share a created_at.
 */
final class LifecycleHistoryPager
{
    /** kind => cursor parameter name (distinct per adjacent surface). */
    public const CURSORS = [
        'union' => 'union_cursor',
        'disinter' => 'disinter_cursor',
        'border' => 'border_cursor',
        'restoration' => 'restoration_cursor',
    ];

    /**
     * Page a lifecycle listing. Returns the 25-row page plus the pagination
     * envelope {previous, next, first} whose Next/Previous carry the scoped
     * token and whose First is the bare path.
     *
     * @return array{page: CursorPaginator, name: string, pagination: array{previous: ?string, next: ?string, first: string}}
     */
    public function page(Request $request, string $kind, string $path, Builder $query): array
    {
        if (! array_key_exists($kind, self::CURSORS)) {
            throw new \InvalidArgumentException("Unknown lifecycle history [{$kind}].");
        }

        $name = self::CURSORS[$kind];
        $scope = hash('sha256', 'lifecycle:'.$kind);

        $encoded = $request->validate([$name => ['nullable', 'string', 'max:1024']])[$name] ?? null;
        $cursor = null;
        if ($encoded !== null && $encoded !== '') {
            try {
                $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data)
                    || count($data) !== 4
                    || ($data['scope'] ?? null) !== $scope
                    || ! is_bool($data['_pointsToNextItems'] ?? null)
                    || ! is_string($data['created_at'] ?? null)
                    || ! is_string($data['id'] ?? null)
                    || ! Str::isUuid($data['id'])) {
                    throw new \InvalidArgumentException;
                }
                // The scope stays validated but is NOT handed to the paginator
                // (it is not an order column) — only the seek keys are.
                $cursor = new Cursor(['created_at' => $data['created_at'], 'id' => $data['id']], $data['_pointsToNextItems']);
            } catch (\Throwable) {
                throw ValidationException::withMessages([$name => 'This history page link is invalid. Open the first page again.']);
            }
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate(25, ['*'], $name, $cursor);

        // created_at is cast to its DB string form ('Y-m-d H:i:s' via Carbon's
        // __toString) so the seek value round-trips in the SAME format the
        // column is stored in — an ISO/Carbon object in the token would make
        // the keyset boundary compare across formats and drop or duplicate a row.
        $url = fn (?Cursor $position): ?string => $position === null ? null : $path.'?'.http_build_query([
            $name => (new Cursor([
                'created_at' => (string) $position->parameter('created_at'),
                'id' => (string) $position->parameter('id'),
                'scope' => $scope,
            ], $position->pointsToNextItems()))->encode(),
        ]);

        return [
            'page' => $page,
            'name' => $name,
            'pagination' => [
                'previous' => $url($page->previousCursor()),
                'next' => $url($page->nextCursor()),
                'first' => $path,
            ],
        ];
    }
}

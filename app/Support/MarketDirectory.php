<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Read only the selected market section, before enriching its small page. */
final class MarketDirectory
{
    public const PAGE_SIZE = 25;
    public const DATE_KEY = "COALESCE(created_at, '1970-01-01 00:00:00+00')";

    public function page(Request $request): array
    {
        $values = $request->validate([
            'tab' => ['nullable', Rule::in(['offers', 'work', 'assistance'])],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);
        $tab = $values['tab'] ?? 'offers';
        $cursor = $this->cursor($values['cursor'] ?? null);
        $table = match ($tab) {
            'work' => 'work_postings',
            'assistance' => 'assistance_requests',
            default => 'marketplace_listings',
        };
        $columns = match ($tab) {
            'work' => ['id', 'title', 'terms', 'rate', 'status', 'organization_id'],
            'assistance' => ['id', 'title', 'need', 'privacy', 'status'],
            default => ['id'],
        };
        $query = DB::table($table)->where('status', 'open')->whereNull('deleted_at')
            ->select($columns)->selectRaw(self::DATE_KEY.' as directory_created_at');
        if ($tab === 'assistance') {
            // Preserve the existing privacy boundary before selecting any page.
            $query->where('privacy', '<>', 'private');
        }
        if ($cursor !== null) {
            $query->whereRaw('('.self::DATE_KEY.', id) '.($cursor->pointsToNextItems() ? '<' : '>').' (?, ?)', [
                $cursor->parameter('directory_created_at'), $cursor->parameter('id'),
            ]);
        }
        $page = $query->orderByDesc('directory_created_at')->orderByDesc('id')
            ->cursorPaginate(self::PAGE_SIZE, cursor: $cursor)
            ->withPath('/economy/market')->appends(['tab' => $tab]);
        $rows = $page->getCollection();
        $counts = $tab !== 'work' || $rows->isEmpty() ? collect() : DB::table('work_applications')
            ->whereIn('posting_id', $rows->pluck('id'))->groupBy('posting_id')
            ->selectRaw('posting_id, count(*) as total')->pluck('total', 'posting_id');

        return [
            'tab' => $tab,
            'offer_ids' => $tab === 'offers' ? $rows->pluck('id')->all() : [],
            'work' => $tab !== 'work' ? [] : $rows->map(fn ($p) => [
                'id' => (string) $p->id, 'title' => (string) $p->title,
                'terms' => (string) $p->terms, 'rate' => $p->rate === null ? null : (string) $p->rate,
                'status' => (string) $p->status, 'organization_id' => (string) $p->organization_id,
                'applications' => (int) ($counts[$p->id] ?? 0),
            ])->all(),
            'assistance' => $tab !== 'assistance' ? [] : $rows->map(fn ($a) => [
                'id' => (string) $a->id, 'title' => (string) $a->title,
                'need' => (string) $a->need, 'privacy' => (string) $a->privacy, 'status' => (string) $a->status,
            ])->all(),
            'pagination' => ['previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl(), 'pageSize' => self::PAGE_SIZE],
        ];
    }

    private function cursor(?string $encoded): ?Cursor
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }
        try {
            $json = base64_decode(strtr($encoded, '-_', '+/'), true);
            $data = $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data) || count($data) !== 3 || ! is_bool($data['_pointsToNextItems'] ?? null)
                || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])) {
                throw new \InvalidArgumentException;
            }
            $date = $data['directory_created_at'] ?? null;
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:[+-]\d{2}(?::?\d{2})?|Z)?$/D', $date)
                || substr($date, 0, 4) === '0000') {
                throw new \InvalidArgumentException;
            }
            new \DateTimeImmutable($date);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                throw new \InvalidArgumentException;
            }

            return new Cursor(['directory_created_at' => $date, 'id' => $data['id']], $data['_pointsToNextItems']);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cursor' => 'This page link is invalid. Open the market section again.']);
        }
    }
}

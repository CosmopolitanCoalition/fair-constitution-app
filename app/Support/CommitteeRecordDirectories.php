<?php

namespace App\Support;

use App\Models\Bill;
use App\Models\Committee;
use App\Models\CommitteeReport;
use App\Models\PublicRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Independent read-only directories for one committee; never hydrate its entire bill/report history. */
final class CommitteeRecordDirectories
{
    public const PAGE_SIZE = 20;

    public const CREATED = "COALESCE(created_at, '1970-01-01 00:00:00+00')";

    public function cursors(Request $request, string $committee, ?string $meeting): array
    {
        $values = $request->validate([
            'bills_cursor' => ['nullable', 'string', 'max:2048'],
            'reports_cursor' => ['nullable', 'string', 'max:2048'],
            'report' => ['nullable', 'uuid'],
        ]);
        $result = [];
        foreach (['bills_cursor', 'reports_cursor'] as $name) {
            $encoded = $values[$name] ?? null;
            if ($encoded === null || $encoded === '') {
                $result[$name] = null;

                continue;
            }
            try {
                $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data) || count($data) !== 6 || ($data['directory'] ?? null) !== $name
                    || ($data['committee'] ?? null) !== $committee || ! array_key_exists('meeting', $data) || $data['meeting'] !== $meeting
                    || ! is_bool($data['_pointsToNextItems'] ?? null) || ! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])) {
                    throw new \InvalidArgumentException;
                }
                $date = $data['directory_created_at'] ?? null;
                if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)?\z/', $date)) {
                    throw new \InvalidArgumentException;
                }
                $parsed = date_parse($date);
                if ($parsed['warning_count'] || $parsed['error_count']) {
                    throw new \InvalidArgumentException;
                }
                $result[$name] = new Cursor(['directory_created_at' => $date, 'id' => $data['id']], $data['_pointsToNextItems']);
            } catch (\Throwable) {
                throw ValidationException::withMessages([$name => __('This committee page link is invalid. Open the committee or hearing again.')]);
            }
        }

        return $result;
    }

    public function bills(Request $request, Committee $committee, ?string $meeting, ?Cursor $cursor): array
    {
        $page = $this->page(Bill::query()->where('committee_id', $committee->id)->where('legislature_id', $committee->legislature_id),
            ['id', 'title', 'status', 'created_at'], $cursor, 'bills_cursor');

        return ['rows' => $page->getCollection(), 'pages' => $this->links($page, $request, $committee, $meeting, 'bills_cursor')];
    }

    public function reports(Request $request, Committee $committee, ?string $meeting, ?Cursor $cursor): array
    {
        $page = $this->page(CommitteeReport::query()->where('committee_id', $committee->id),
            ['id', 'bill_id', 'report_record_id', 'created_at'], $cursor, 'reports_cursor');

        return ['rows' => $this->reportRows($page->getCollection(), $request, $committee),
            'pages' => $this->links($page, $request, $committee, $meeting, 'reports_cursor')];
    }

    /** At most one indexed report seek per visible bill, not a whereIn fetch of every old report. */
    public function latestReports(Collection $bills, Request $request, Committee $committee): Collection
    {
        $reports = collect();
        foreach ($bills as $bill) {
            $report = CommitteeReport::query()->where('committee_id', $committee->id)->where('bill_id', $bill->id)
                ->orderByRaw(self::CREATED.' DESC')->orderByDesc('id')->first(['id', 'bill_id', 'report_record_id', 'created_at']);
            if ($report !== null) {
                $reports->push($report);
            }
        }

        return collect($this->reportRows($reports, $request, $committee))->keyBy('bill_id');
    }

    public function selectedReport(Request $request, Committee $committee): ?array
    {
        if (! $request->filled('report')) {
            return null;
        }
        $report = CommitteeReport::query()->where('committee_id', $committee->id)->whereKey($request->query('report'))
            ->firstOrFail(['id', 'bill_id', 'report_record_id', 'created_at']);

        return $this->reportRows(collect([$report]), $request, $committee, true)[0];
    }

    public function billHref(Request $request, string $id): string
    {
        $context = $request->only(['jurisdiction', 'place', 'meeting', 'return_to']);

        return '/bills/'.$id.($context === [] ? '' : '?'.http_build_query($context));
    }

    private function reportRows(Collection $reports, Request $request, Committee $committee, bool $full = false): array
    {
        if ($reports->isEmpty()) {
            return [];
        }
        $records = PublicRecord::query()->whereIn('id', $reports->pluck('report_record_id')->filter()->all())
            ->where('subject_type', 'committees')->where('subject_id', $committee->id)->where('via_form', 'F-CHR-004')
            ->select(['id', 'title', 'actor_display', 'published_at', 'audit_seq', 'seq'])
            ->selectRaw($full ? 'body' : 'substr(body, 1, 351) as body')->get()->keyBy('id');
        // A report's bill may have moved to another committee in this legislature.
        $bills = Bill::query()->where('legislature_id', $committee->legislature_id)
            ->whereIn('id', $reports->pluck('bill_id')->filter()->all())->get(['id', 'title'])->keyBy('id');

        return $reports->map(function ($report) use ($records, $bills, $request, $committee, $full) {
            $record = $records->get($report->report_record_id);
            $bill = $bills->get($report->bill_id);

            return ['id' => (string) $report->id, 'bill_id' => $bill?->id,
                'title' => $record?->title ?? __('Committee report — publication unavailable'),
                'body' => $full ? $record?->body : null,
                'excerpt' => $record?->body === null ? null : Str::limit($record->body, 350),
                'filed_at' => $report->created_at?->toIso8601String(),
                'published_at' => $record?->published_at?->toIso8601String(),
                'actor' => $record?->actor_display, 'publication_available' => $record !== null,
                'seq' => $record === null ? null : (int) $record->seq,
                'audit_seq' => $record?->audit_seq,
                'bill' => $bill === null ? null : ['id' => (string) $bill->id, 'title' => $bill->title, 'href' => $this->billHref($request, $bill->id)],
                'href' => '/committees/'.$committee->id.'?'.http_build_query([...$request->except('report'), 'report' => $report->id]).'#committee-report-detail',
                'close_href' => '/committees/'.$committee->id.($request->except('report') === [] ? '' : '?'.http_build_query($request->except('report'))).'#committee-reports',
                'record_href' => $record?->audit_seq === null ? null : '/system/audit-chain?seq='.(int) $record->audit_seq];
        })->values()->all();
    }

    private function page(Builder $query, array $columns, ?Cursor $cursor, string $name): CursorPaginator
    {
        $previous = $cursor?->pointsToPreviousItems() ?? false;
        if ($cursor) {
            $query->whereRaw('('.self::CREATED.', id) '.($previous ? '>' : '<').' (?, ?)', [$cursor->parameter('directory_created_at'), $cursor->parameter('id')]);
        }
        $direction = $previous ? 'ASC' : 'DESC';
        $rows = $query->select($columns)->selectRaw(self::CREATED.' as directory_created_at')
            ->orderByRaw(self::CREATED.' '.$direction)->orderBy('id', $direction)->limit(self::PAGE_SIZE + 1)->get();

        return new CursorPaginator($rows, self::PAGE_SIZE, $cursor, ['cursorName' => $name, 'parameters' => ['directory_created_at', 'id']]);
    }

    private function links(CursorPaginator $page, Request $request, Committee $committee, ?string $meeting, string $name): array
    {
        $url = function (?Cursor $cursor) use ($request, $committee, $meeting, $name): ?string {
            if ($cursor === null) {
                return null;
            }
            $scoped = new Cursor(['directory_created_at' => $cursor->parameter('directory_created_at'), 'id' => $cursor->parameter('id'),
                'directory' => $name, 'committee' => (string) $committee->id, 'meeting' => $meeting], $cursor->pointsToNextItems());

            return '/committees/'.$committee->id.'?'.http_build_query([...$request->except($name), $name => $scoped->encode()]);
        };
        $context = $request->except($name);

        return ['previous' => $url($page->previousCursor()), 'next' => $url($page->nextCursor()),
            'first' => '/committees/'.$committee->id.($context === [] ? '' : '?'.http_build_query($context))];
    }
}

<?php

namespace App\Support;

use App\Models\CaseFiling;
use App\Models\SettingChange;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Published civic receipts, with a seek token tied to its actual institution/actor. */
final class CivicHistoryDirectory
{
    public function page(Request $request, string $kind, ?string $scopeId, string $path): array
    {
        if (! in_array($kind, ['changes', 'filings'], true)) throw new \InvalidArgumentException('Unknown history');
        $key = $kind === 'changes' ? 'id' : 'seq';
        $name = $kind.'_cursor'; $scope = hash('sha256', $kind.':'.$scopeId);
        $encoded = $request->validate([$name => ['nullable', 'string', 'max:1024']])[$name] ?? null;
        $cursor = null;
        if ($encoded !== null && $encoded !== '') {
            try {
                $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($data) || count($data) !== 3 || ($data['scope'] ?? null) !== $scope || ! is_bool($data['_pointsToNextItems'] ?? null)) throw new \InvalidArgumentException;
                $value = $data[$key] ?? null;
                if ($key === 'seq' ? ! is_int($value) || $value < 1 : ! is_string($value) || ! Str::isUuid($value)) throw new \InvalidArgumentException;
                $cursor = new Cursor([$key => $value], $data['_pointsToNextItems']);
            } catch (\Throwable) {
                throw ValidationException::withMessages([$name => 'This history page link is invalid. Open the first page again.']);
            }
        }
        $context = $request->validate(['jurisdiction' => ['nullable', 'string', 'max:255']]);
        $first = $path.($context ? '?'.http_build_query($context) : '');
        if ($scopeId === null) return ['records' => [], 'pagination' => ['previous' => null, 'next' => null, 'first' => $first]];
        $query = $kind === 'changes'
            ? SettingChange::query()->where('jurisdiction_id', $scopeId)->with('law:id,act_number,enacting_bill_id')
            : CaseFiling::query()->where('advocate_id', $scopeId)->with('case:id,title,docket_no');
        $columns = $kind === 'changes' ? ['id', 'setting_key', 'old_value', 'new_value', 'law_id', 'applied_at']
            : ['seq', 'case_id', 'filing_form', 'filing_kind', 'title', 'body', 'created_at'];
        $page = $query->orderByDesc($key)->cursorPaginate(50, $columns, $name, $cursor);
        $url = fn (?Cursor $position) => $position === null ? null : $path.'?'.http_build_query($context + [$name => (new Cursor([$key => $position->parameter($key), 'scope' => $scope], $position->pointsToNextItems()))->encode()]);
        $records = $page->getCollection()->map(fn ($row) => $kind === 'changes' ? [
            'id' => (string) $row->id, 'setting_key' => $row->setting_key, 'old_value' => $row->old_value, 'new_value' => $row->new_value,
            'act_number' => $row->law?->act_number, 'bill_href' => $row->law?->enacting_bill_id === null ? null : '/bills/'.$row->law->enacting_bill_id,
            'applied_at' => $row->applied_at?->toIso8601String(),
        ] : [
            'seq' => (int) $row->seq, 'form' => $row->filing_form, 'kind' => $row->filing_kind,
            'case' => $row->case === null ? null : ['id' => (string) $row->case->id, 'title' => $row->case->title, 'href' => '/cases/'.$row->case->id],
            'text' => $row->title ?? $row->body ?? $row->filing_kind, 'when' => $row->created_at?->toIso8601String(), 'status' => 'docketed',
        ])->all();
        return ['records' => $records, 'pagination' => ['previous' => $url($page->previousCursor()), 'next' => $url($page->nextCursor()), 'first' => $first]];
    }
}

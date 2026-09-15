<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Untrusted page links carry a seek position, never an account or permission. */
final class FinancialHistoryCursor
{
    public static function read(Request $request, string $name, string $mode = 'id'): ?Cursor
    {
        $input = $request->validate([$name => ['nullable', 'string', 'max:1024']]);
        $encoded = $input[$name] ?? null;
        if ($encoded === null || $encoded === '') return null;
        try {
            $data = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true, flags: JSON_THROW_ON_ERROR);
            $keys = $mode === 'transaction' ? ['id', 'created_at'] : [$mode];
            if (! is_array($data) || count($data) !== count($keys) + 1 || ! is_bool($data['_pointsToNextItems'] ?? null)) throw new \InvalidArgumentException;
            if ($mode === 'seq') {
                if (! is_int($data['seq'] ?? null) || $data['seq'] < 1) throw new \InvalidArgumentException;
            } elseif (! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])) throw new \InvalidArgumentException;
            if ($mode === 'transaction') {
                $date = $data['created_at'] ?? null;
                if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)?\z/', $date)) throw new \InvalidArgumentException;
                $parsed = date_parse($date);
                if ($parsed['error_count'] || $parsed['warning_count']) throw new \InvalidArgumentException;
            }
            return new Cursor(array_intersect_key($data, array_flip($keys)), $data['_pointsToNextItems']);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$name => __('This history page link is invalid. Open the first page again.')]);
        }
    }

    public static function links($page): array
    {
        return ['previous' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()];
    }
}

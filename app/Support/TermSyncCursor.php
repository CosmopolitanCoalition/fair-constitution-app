<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Strictly validate keyset links before any term-directory database read. */
final class TermSyncCursor
{
    public static function fromRequest(Request $request): array
    {
        $out = [];
        foreach (['legislatures_cursor', 'terms_cursor', 'appointments_cursor', 'refusals_cursor'] as $name) {
            $request->validate([$name => ['nullable', 'string', 'max:1024']]);
            $encoded = $request->input($name);
            $out[$name] = null;
            if ($encoded === null || $encoded === '') continue;
            try {
                $json = base64_decode(strtr($encoded, '-_', '+/'), true);
                $data = $json === false ? null : json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                $key = $name === 'refusals_cursor' ? 'seq' : 'id';
                if (! is_array($data) || count($data) !== 2 || ! is_bool($data['_pointsToNextItems'] ?? null)) {
                    throw new \InvalidArgumentException;
                }
                $value = $data[$key] ?? null;
                if ($key === 'id' ? ! is_string($value) || ! Str::isUuid($value)
                    : ! is_int($value) || $value < 1) {
                    throw new \InvalidArgumentException;
                }
                $out[$name] = new Cursor([$key => $value], $data['_pointsToNextItems']);
            } catch (\Throwable) {
                throw ValidationException::withMessages([$name => 'This page link is invalid. Open term records again.']);
            }
        }

        return $out;
    }
}

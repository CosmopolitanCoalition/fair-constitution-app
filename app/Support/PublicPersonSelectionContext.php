<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Enrich only the already-bounded selected page; never read residence or wallets. */
final class PublicPersonSelectionContext
{
    public static function forIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $handles = DB::table('social_profiles')->whereIn('user_id', $ids)
            ->where('visibility', 'public')->whereNull('deleted_at')
            ->pluck('handle', 'user_id');

        $context = [];
        foreach ($ids as $id) {
            $handle = trim((string) ($handles[$id] ?? ''));
            $context[(string) $id] = [
                'profile_href' => '/people?who='.rawurlencode((string) $id),
                'public_handle' => $handle !== '' ? '@'.ltrim($handle, '@') : null,
            ];
        }

        return $context;
    }
}

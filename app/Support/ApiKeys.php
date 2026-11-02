<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Per-user API keys for outside automation. The key is shown once at creation.
 * Only its SHA-256 hash is stored, so a database copy cannot reveal a usable key.
 */
class ApiKeys
{
    public const SCOPES = [
        'calendar:read', 'calendar:write', 'tasks:read', 'tasks:write',
        'crm:read', 'crm:write', 'mail:read', 'mail:write', 'mail:send',
    ];

    /** @return array{id:string,key:string} */
    public static function issue(string $userId, string $name, array $scopes, ?\DateTimeInterface $expires = null): array
    {
        $scopes = array_values(array_intersect(self::SCOPES, $scopes));
        $key = 'cga_'.Str::random(40);
        $id = (string) Str::uuid();
        DB::table('api_keys')->insert([
            'id' => $id, 'user_id' => $userId, 'name' => $name,
            'key_hash' => hash('sha256', $key), 'key_prefix' => substr($key, 0, 8),
            'scopes' => json_encode($scopes), 'expires_at' => $expires,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'key' => $key];
    }

    /** The live key row for a presented key, or null. Touches last_used_at at most once a minute. */
    public static function find(string $presented): ?object
    {
        if (! str_starts_with($presented, 'cga_') || strlen($presented) !== 44) {
            return null;
        }
        $row = DB::table('api_keys')->where('key_hash', hash('sha256', $presented))->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
        if ($row && (! $row->last_used_at || now()->diffInSeconds($row->last_used_at, true) > 60)) {
            DB::table('api_keys')->where('id', $row->id)->update(['last_used_at' => now()]);
        }

        return $row;
    }
}

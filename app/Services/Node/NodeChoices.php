<?php

namespace App\Services\Node;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * W-0521 - read and write one person's My node choices. The keys are fixed. A value is
 * a JSON object no larger than 64 KB. Other services (the assistant permission check,
 * the bundle installer) read these rows instead of the browser.
 */
class NodeChoices
{
    public const KEYS = ['kits', 'reach', 'stores', 'sharing', 'bundles', 'assistant', 'tools', 'surfaces'];

    public const MAX_BYTES = 65536;

    /** @return array<string, array<string, mixed>> */
    public function all(string $userId): array
    {
        $out = [];
        foreach (DB::table('node_choices')->where('user_id', $userId)->get(['key', 'value']) as $row) {
            $v = json_decode((string) $row->value, true);
            if (is_array($v)) {
                $out[$row->key] = $v;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function get(string $userId, string $key): ?array
    {
        return $this->all($userId)[$key] ?? null;
    }

    /** @param array<string, mixed> $value */
    public function put(string $userId, string $key, array $value): void
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown choice key: {$key}");
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Choice value is too large.');
        }
        $now = now();
        $exists = DB::table('node_choices')->where(['user_id' => $userId, 'key' => $key])->exists();
        if ($exists) {
            DB::table('node_choices')->where(['user_id' => $userId, 'key' => $key])->update(['value' => $json, 'updated_at' => $now]);
        } else {
            DB::table('node_choices')->insert(['id' => (string) Str::uuid(), 'user_id' => $userId, 'key' => $key, 'value' => $json, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}

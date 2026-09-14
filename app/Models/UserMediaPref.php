<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * W-0432 — one video player preferences document per signed-in viewer.
 *
 * The primary key is the user id (one row per user). `prefs` is a small jsonb
 * map of the player's remembered choices: audio, cap, linked, captionsOn,
 * volume, muted. The array cast reads and writes jsonb on Postgres and text on
 * the SQLite test fixtures without a driver branch. Not audit-chained.
 */
class UserMediaPref extends Model
{
    protected $table = 'user_media_prefs';

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['user_id', 'prefs'];

    protected $casts = [
        'prefs' => 'array',
    ];

    /**
     * The saved prefs document for a user, or null when the user has none.
     *
     * @return array<string, mixed>|null
     */
    public static function documentFor(string $userId): ?array
    {
        return static::query()->whereKey($userId)->value('prefs');
    }
}

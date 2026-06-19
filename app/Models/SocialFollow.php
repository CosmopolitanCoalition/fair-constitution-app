<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-1 — a follow edge (user/space/subforum). LOCAL-ONLY: never federates, never reaches
 * the public register or the audit chain. A plain insert that never calls publish().
 */
class SocialFollow extends Model
{
    use HasUuids, SoftDeletes;

    public const TARGET_USER     = 'user';
    public const TARGET_SPACE    = 'space';
    public const TARGET_SUBFORUM = 'subforum';

    protected $fillable = [
        'id',
        'follower_user_id',
        'target_type',
        'target_id',
    ];
}

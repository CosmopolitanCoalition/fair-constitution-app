<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Article III §3 — member of an executive.
 *
 * For type='committee' executives: rank=0 only, all members are principals
 * with equal voting weight. For type='individual': rank=0 is the elected
 * primary; rank=1..4 are the auto-seated runners-up acting as advisors.
 */
class ExecutiveMember extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'executive_id',
        'user_id',
        'role',
        'rank',
        'joined_at',
        'left_at',
    ];

    protected $casts = [
        'rank'      => 'integer',
        'joined_at' => 'date',
        'left_at'   => 'date',
    ];

    public function executive(): BelongsTo
    {
        return $this->belongsTo(Executive::class, 'executive_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

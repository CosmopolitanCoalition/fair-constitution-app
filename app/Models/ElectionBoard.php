<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * I-ELB Election Board (B-2). At most one ACTIVE board per jurisdiction
 * (partial unique `election_boards_one_active`).
 *
 * `is_bootstrap = true` = the system-as-board row constituted by
 * ActivationService step 3.5 — carries the "temporary · replacement
 * queued" banner until WF-ELE-10 (Phase C) retires it. Its single
 * synthetic member row has user_id NULL (the system itself).
 */
class ElectionBoard extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_FORMING = 'forming';
    public const STATUS_ACTIVE  = 'active';
    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'legislature_id',
        'created_by_act_id',
        'is_bootstrap',
        'status',
        'retired_at',
    ];

    protected $casts = [
        'is_bootstrap' => 'boolean',
        'retired_at'   => 'datetime',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ElectionBoardMember::class, 'election_board_id');
    }

    public function elections(): HasMany
    {
        return $this->hasMany(Election::class, 'election_board_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}

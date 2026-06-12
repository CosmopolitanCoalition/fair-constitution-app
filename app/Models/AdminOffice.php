<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * I-ADM Administrative Office (F-LEG-013, ordinary majority — unstated
 * threshold rule: majority of all serving). One live office per
 * legislature (partial unique `admin_offices_one_live`). Staffed through
 * the Phase B appointments pipeline (`appointable_type='admin_offices'`,
 * civil-appointment terms, CLK-09) — R-29 derives from the seated
 * appointment.
 */
class AdminOffice extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_CREATED   = 'created';
    public const STATUS_STAFFED   = 'staffed';
    public const STATUS_DISSOLVED = 'dissolved';

    protected $fillable = [
        'id',
        'legislature_id',
        'created_by_vote_id',
        'created_by_law_id',
        'status',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function investigations(): HasMany
    {
        return $this->hasMany(MisconductInvestigation::class, 'admin_office_id');
    }
}

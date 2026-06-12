<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-LEG-031 ledger (C-7): one row per applied amendable-setting change,
 * linked to the enacting law. The constitutional_settings row mutates
 * ONLY alongside one of these (EnactmentService — bill lifecycle →
 * peg-quorum vote → enactment).
 */
class SettingChange extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'legislature_id',
        'setting_key',
        'old_value',
        'new_value',
        'law_id',
        'applied_at',
        'created_at',
    ];

    protected $casts = [
        'old_value'  => 'json',
        'new_value'  => 'json',
        'applied_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function law(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'law_id');
    }
}

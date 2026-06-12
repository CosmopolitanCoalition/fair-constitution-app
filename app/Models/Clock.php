<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registry row for one constitutional clock (CLK-01…CLK-21). Seeded by
 * ClockRegistrySeeder from the canonical scheduler spec — application code
 * treats the registry as read-only reference data (definitions change via
 * the seeder, never at runtime).
 *
 * `default_value.setting_key` names the constitutional_settings column an
 * amendable clock resolves from, per jurisdiction, at EVALUATION time
 * (ClockService::resolvedInt). Hardened/structural/per-case clocks carry
 * amendable=false; default_value.mode keeps the finer distinction.
 */
class Clock extends Model
{
    public const TYPE_RECURRING = 'recurring';
    public const TYPE_COUNTDOWN = 'countdown';
    public const TYPE_WINDOW    = 'window';
    public const TYPE_THRESHOLD = 'threshold';
    public const TYPE_DERIVED   = 'derived';
    public const TYPE_FLAG      = 'flag';

    protected $table = 'clocks';

    /** String registry PK ('CLK-05'), not a UUID. */
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'type',
        'default_value',
        'amendable',
        'fires_workflow',
        'basis',
    ];

    protected $casts = [
        'default_value' => 'array',
        'amendable'     => 'boolean',
    ];

    public function timers(): HasMany
    {
        return $this->hasMany(ClockTimer::class, 'clock_id');
    }

    /** The constitutional_settings column this clock resolves from, if any. */
    public function settingKey(): ?string
    {
        $key = $this->default_value['setting_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceSettings extends Model
{
    use SoftDeletes;

    protected $table = 'instance_settings';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'instance_name',
        'cosmic_address_id',
        'map_mode',
        'time_mode',
        'time_scale_seconds_per_year',
        'setup_step_completed',
        'setup_completed_at',
        'setup_completion_notes',
        'pending_constitutional_defaults',
        'map_accepted_at',                // P.6 — operator confirmed map data
        'apportionment_completed_at',
        'apportionment_log',
        'setup_districts_confirmed_at',
    ];

    protected $casts = [
        'setup_step_completed'            => 'integer',
        'time_scale_seconds_per_year'     => 'integer',
        'setup_completed_at'              => 'datetime',
        'setup_completion_notes'          => 'array',
        'pending_constitutional_defaults' => 'array',
        'map_accepted_at'                 => 'datetime',
        'apportionment_completed_at'      => 'datetime',
        'setup_districts_confirmed_at'    => 'datetime',
    ];

    public function cosmicAddress(): BelongsTo
    {
        return $this->belongsTo(CosmicAddress::class, 'cosmic_address_id');
    }

    /**
     * Singleton accessor — first-or-create the one instance_settings row.
     */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'instance_name'        => 'Unnamed Instance',
            'map_mode'             => 'physical_earth',
            'time_mode'            => 'real',
            'setup_step_completed' => 0,
        ]);
    }

    public function isSetupComplete(): bool
    {
        return $this->setup_completed_at !== null;
    }
}

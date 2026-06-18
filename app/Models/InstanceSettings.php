<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        // Phase F — federation identity (federation:init mints these).
        'server_id',
        'public_key',
        'private_key_encrypted',
        'signing_key_generated_at',
        'federation_enabled',
        // Phase G — read-only mirror mode (G1).
        'mirror_of_server_id',
        'mirror_adopted_at',
        // Phase G — G-ID attestation authority (ships dark).
        'attestation_authority_enabled',
        // Phase G — co-member cluster home.
        'home_cluster_id',
        // Phase G (G3c) — geodata posture chosen at adoption (already_have|pull_from_origin|skip).
        'geodata_posture',
        // Phase G (G-VER) — the three version axes.
        'constitutional_version',
        'app_release',
        'version_pinned_at',
    ];

    protected $casts = [
        'setup_step_completed' => 'integer',
        'time_scale_seconds_per_year' => 'integer',
        'setup_completed_at' => 'datetime',
        'setup_completion_notes' => 'array',
        'pending_constitutional_defaults' => 'array',
        'map_accepted_at' => 'datetime',
        'apportionment_completed_at' => 'datetime',
        'setup_districts_confirmed_at' => 'datetime',
        'signing_key_generated_at' => 'datetime',
        'federation_enabled' => 'boolean',
        'mirror_adopted_at' => 'datetime',
        'attestation_authority_enabled' => 'boolean',
        'version_pinned_at' => 'datetime',
    ];

    /**
     * The private key is encrypted at rest and must never serialize into an
     * Inertia prop, an export bundle, or a log line.
     */
    protected $hidden = [
        'private_key_encrypted',
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
            'instance_name' => 'Unnamed Instance',
            'map_mode' => 'physical_earth',
            'time_mode' => 'real',
            'setup_step_completed' => 0,
        ]);
    }

    public function isSetupComplete(): bool
    {
        return $this->setup_completed_at !== null;
    }

    /**
     * Phase G — is this instance a read-only mirror of a host? A mirror is
     * authoritative for nothing; the ConstitutionalEngine write-guard (G2)
     * refuses every constitutional write while this is true.
     */
    public function isMirror(): bool
    {
        return $this->mirror_of_server_id !== null;
    }

    /**
     * G-VER — the constitutional_version this instance operates under: the AGREED
     * (pinned) value, or the DERIVED hardened-compute hash when none is pinned yet
     * (genesis / a fresh instance). The derived value is what the code actually is.
     */
    public function constitutionalVersion(): string
    {
        return $this->constitutional_version
            ?: app(\App\Services\ConstitutionalVersionService::class)->derive();
    }

    /** Pin the agreed constitutional_version (defaults to the derived current code). */
    public function pinConstitutionalVersion(?string $version = null): void
    {
        $this->constitutional_version = $version ?? app(\App\Services\ConstitutionalVersionService::class)->derive();
        $this->version_pinned_at = now();
        $this->save();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Jurisdiction extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'name',
        'slug',
        'iso_code',
        'adm_level',
        'parent_id',
        'population',
        'population_year',
        'is_active',
        'is_bootstrapping',
        'authoritative_server_id',
        'authoritative_server_url',
        'last_synced_at',
        'source',
        'geoboundaries_id',
        'osm_relation_id',
        'official_languages',
        'timezone',
    ];

    protected $casts = [
        'official_languages' => 'array',
        'is_active'          => 'boolean',
        'is_bootstrapping'   => 'boolean',
        'adm_level'          => 'integer',
        'population'         => 'integer',
        'population_year'    => 'integer',
        'last_synced_at'     => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Jurisdiction::class, 'parent_id');
    }

    public function constitutionalSettings(): HasOne
    {
        return $this->hasOne(ConstitutionalSettings::class, 'jurisdiction_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAtLevel($query, int $level)
    {
        return $query->where('adm_level', $level);
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    /**
     * Returns ordered ancestor chain from ADM0 down to (but not including) self.
     * Used for breadcrumb navigation.
     */
    public function getAncestorsAttribute(): array
    {
        $ancestors = [];
        $current = $this->parent;

        while ($current !== null) {
            array_unshift($ancestors, [
                'id'        => $current->id,
                'name'      => $current->name,
                'adm_level' => $current->adm_level,
                'slug'      => $current->slug,
            ]);
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * Human-readable label for this jurisdiction's ADM level.
     */
    public function getAdmLabelAttribute(): string
    {
        return match ($this->adm_level) {
            0 => 'World',
            1 => 'Country / Territory',
            2 => 'State / Province / Region',
            3 => 'County / District',
            4 => 'Municipality / City',
            5 => 'Borough / Township',
            6 => 'Neighbourhood / Ward',
            default => 'Jurisdiction (Level ' . $this->adm_level . ')',
        };
    }
}

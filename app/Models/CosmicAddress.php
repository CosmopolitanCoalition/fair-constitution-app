<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CosmicAddress extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'parent_id',
        'label',
        'slug',
        'type',
        'subtype',
        'enabled',
        'source',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'enabled'    => 'boolean',
        'sort_order' => 'integer',
        'metadata'   => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Returns ordered ancestor chain from root down to (but not including) self.
     */
    public function getAncestorsAttribute(): array
    {
        $ancestors = [];
        $current = $this->parent;

        while ($current !== null) {
            array_unshift($ancestors, $current->toArray());
            $current = $current->parent;
        }

        return $ancestors;
    }

    /**
     * Returns the full path from root down to self, inclusive.
     * Used by the cosmic-address cascader to preload its dropdowns.
     */
    public function pathFromRoot(): array
    {
        return [...$this->ancestors, $this->toArray()];
    }
}

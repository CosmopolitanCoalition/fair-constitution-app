<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Article III — Executive of a jurisdiction.
 *
 * Stub model wired with relationships only. Business logic (election
 * triggers, member seating, conversion votes) lands with the elections
 * engine in Phase 2 of the master roadmap.
 */
class Executive extends Model
{
    use SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'type',
        'term_number',
        'term_starts_on',
        'term_ends_on',
        'status',
        'parent_executive_id',
        'source_legislature_id',
    ];

    protected $casts = [
        'term_number'    => 'integer',
        'term_starts_on' => 'date',
        'term_ends_on'   => 'date',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function parentExecutive(): BelongsTo
    {
        return $this->belongsTo(Executive::class, 'parent_executive_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ExecutiveMember::class, 'executive_id');
    }
}

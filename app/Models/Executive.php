<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Article III — Executive of a jurisdiction (ESM-16: ONE machine).
 *
 * Each jurisdiction with a legislature has exactly one executives row.
 * The lifecycle EVOLVES this same row — no second row, ever:
 *
 *   forming → delegated (F-LEG-014, supermajority — committee composed
 *             from the chamber by the SAME proportional algorithm as
 *             committees, Art. III §2)
 *           → conversion_voted (F-LEG-015 adopted; constituent
 *             dual-supermajority process open, Art. III §3)
 *           → elected (process passed + executive election certified;
 *             `type` flips committee|individual per the conversion act)
 *           → dissolved | reverted
 *
 * Delegated members are EX-OFFICIO legislators: their term IS their
 * legislative seat's term (via executive_members.legislature_member_id;
 * term_id stays NULL — never a duplicated lockstep source of truth).
 * `modified` is an event (exec_office_alter), not a resting status.
 */
class Executive extends Model
{
    use SoftDeletes;

    public const TYPE_COMMITTEE  = 'committee';
    public const TYPE_INDIVIDUAL = 'individual';

    public const STATUS_FORMING          = 'forming';
    public const STATUS_DELEGATED        = 'delegated';
    public const STATUS_CONVERSION_VOTED = 'conversion_voted';
    public const STATUS_ELECTED          = 'elected';
    public const STATUS_DISSOLVED        = 'dissolved';
    public const STATUS_REVERTED         = 'reverted';

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
        'delegation_law_id',
        'delegated_scope',
        'conversion_process_id',
        'conversion_law_id',
        'converted_at',
        'delegated_member_count',
    ];

    protected $casts = [
        'term_number'            => 'integer',
        'term_starts_on'         => 'date',
        'term_ends_on'           => 'date',
        'converted_at'           => 'datetime',
        'delegated_member_count' => 'integer',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function parentExecutive(): BelongsTo
    {
        return $this->belongsTo(Executive::class, 'parent_executive_id');
    }

    public function sourceLegislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'source_legislature_id');
    }

    public function delegationLaw(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'delegation_law_id');
    }

    public function conversionLaw(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'conversion_law_id');
    }

    public function conversionProcess(): BelongsTo
    {
        return $this->belongsTo(MultiJurisdictionVote::class, 'conversion_process_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ExecutiveMember::class, 'executive_id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class, 'executive_id');
    }

    public function seatedMembers(): HasMany
    {
        return $this->members()->where('status', ExecutiveMember::STATUS_SEATED);
    }
}

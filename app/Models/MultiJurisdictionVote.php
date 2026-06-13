<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dual-supermajority process substrate (C-5) — Phase D/E/F consumers
 * (executive office creation/alteration, judiciary conversion, cultural
 * institutions, additional articles, unions, disintermediation).
 * Phase C wiring is MultiJurisdictionVoteService only; no form consumes
 * it until F-LEG-015 (Phase D).
 */
class MultiJurisdictionVote extends Model
{
    use HasUuids, SoftDeletes;

    public const BASIS_SUPERMAJORITY = 'supermajority';

    public const BASIS_UNANIMITY = 'unanimity';

    public const STATUS_OPEN = 'open';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const KINDS = [
        'exec_office_create', 'exec_office_alter', 'judiciary_convert',
        'cultural_institution', 'additional_articles', 'union', 'disintermediation',
        // Phase E (PHASE_E_DESIGN_challenge_law §E.3) — Door 2a: the
        // constituent-consent leg of a dual-door setting amendment
        // (DUAL_DOOR_KEYS, e.g. judiciary_is_elected, Art. IV §3).
        'setting_amendment',
    ];

    protected $fillable = [
        'id',
        'kind',
        'subject_type',
        'subject_id',
        'initiating_legislature_id',
        'initiating_vote_id',
        'basis',
        'constituent_total',
        'required',
        'yes_count',
        'no_count',
        'status',
        'opens_at',
        'closes_at',
    ];

    protected $casts = [
        'constituent_total' => 'integer',
        'required' => 'integer',
        'yes_count' => 'integer',
        'no_count' => 'integer',
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
    ];

    public function initiatingLegislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'initiating_legislature_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(ConstituentConsent::class, 'process_id');
    }
}

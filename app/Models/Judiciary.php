<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV — Judiciary of a jurisdiction (ESM-18: ONE machine).
 *
 * Each jurisdiction with a legislature has exactly one judiciaries row.
 * The lifecycle EVOLVES this same row — no second row, ever (the executive
 * ESM-16 posture):
 *
 *   forming → creating (F-LEG-017 supermajority; the nomination + consent
 *             phase between the creation vote and a seated bench)
 *           → appointed (every seat consented; the equal-constituent
 *             invariant holds — Art. IV §1/§2)
 *           → conversion_voted (F-LEG-018 adopted; constituent
 *             dual-supermajority process open, Art. IV §3)
 *           → elected (process passed + judicial election certified;
 *             type flips appointed→elected, the appointed bench closes)
 *           → reverted (failed constituent consent — stays on its
 *             appointed footing) | dissolved
 *
 * Default type is `appointed` (Art. IV §1 hard constraint — never silently
 * flipped; elected courts come ONLY via F-LEG-018 conversion). The
 * AUTHORITATIVE term length resolves through SettingsResolver
 * (judicial_appointment_years) at seating — never the term_years column
 * (kept as the per-court display snapshot at creation).
 */
class Judiciary extends Model
{
    use SoftDeletes;

    public const TYPE_APPOINTED = 'appointed';

    public const TYPE_ELECTED = 'elected';

    public const STATUS_FORMING = 'forming';

    public const STATUS_CREATING = 'creating';

    public const STATUS_APPOINTED = 'appointed';

    public const STATUS_CONVERSION_VOTED = 'conversion_voted';

    public const STATUS_ELECTED = 'elected';

    public const STATUS_DISSOLVED = 'dissolved';

    public const STATUS_REVERTED = 'reverted';

    public const NOMINATION_CONSTITUENT = 'constituent';

    public const NOMINATION_COMMITTEE = 'committee';

    /** Resting states in which the court can hear cases (the cases-agent gate). */
    public const OPERATING_STATUSES = [self::STATUS_APPOINTED, self::STATUS_ELECTED];

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'court_name',
        'type',
        'min_judges',
        'term_years',
        'status',
        'parent_judiciary_id',
        'creation_law_id',
        'nomination_mode',
        'conversion_process_id',
        'conversion_law_id',
        'converted_at',
        'judge_count',
        'source_legislature_id',
    ];

    protected $casts = [
        'min_judges' => 'integer',
        'term_years' => 'integer',
        'judge_count' => 'integer',
        'converted_at' => 'datetime',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function parentJudiciary(): BelongsTo
    {
        return $this->belongsTo(Judiciary::class, 'parent_judiciary_id');
    }

    public function sourceLegislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'source_legislature_id');
    }

    public function creationLaw(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'creation_law_id');
    }

    public function conversionLaw(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'conversion_law_id');
    }

    public function conversionProcess(): BelongsTo
    {
        return $this->belongsTo(MultiJurisdictionVote::class, 'conversion_process_id');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(JudicialSeat::class, 'judiciary_id');
    }

    public function seatedSeats(): HasMany
    {
        return $this->seats()->where('status', JudicialSeat::STATUS_SEATED);
    }

    public function nominations(): HasMany
    {
        return $this->hasMany(JudicialNomination::class, 'judiciary_id');
    }
}

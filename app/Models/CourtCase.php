<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 — one dispute before a judiciary (ESM-CASE, the WF-JUD-03
 * spine). The lifecycle is owned by CaseService; no other class mutates
 * `status`. `double_jeopardy_locked` is the persisted Art. II §8 fact the
 * hardened re-prosecution bar reads.
 *
 * The model maps the `cases` table — `Case` is a PHP reserved word, so the
 * class is CourtCase (the $table override carries the name).
 */
class CourtCase extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'cases';

    public const KIND_CIVIL = 'civil';

    public const KIND_CRIMINAL = 'criminal';

    public const KIND_ADMINISTRATIVE = 'administrative';

    public const KIND_CONSTITUTIONAL = 'constitutional';

    public const STATUS_FILED = 'filed';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PANELED = 'paneled';

    public const STATUS_JURY_EMPANELED = 'jury_empaneled';

    public const STATUS_HEARD = 'heard';

    public const STATUS_DELIBERATION = 'deliberation';

    public const STATUS_DECIDED = 'decided';

    public const STATUS_SENTENCED = 'sentenced';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_APPEALED = 'appealed';

    /** Severity values a court may classify a case at (drives panel size). */
    public const SEVERITY_MINOR = 'minor';

    public const SEVERITY_MODERATE = 'moderate';

    public const SEVERITY_SERIOUS = 'serious';

    public const SEVERITY_CONSTITUTIONAL_MAJOR = 'constitutional_major';

    /** Terminal states — a closed/dismissed case is finished. */
    public const TERMINAL_STATUSES = [self::STATUS_CLOSED, self::STATUS_DISMISSED];

    protected $fillable = [
        'id',
        'docket_no',
        'judiciary_id',
        'jurisdiction_id',
        'kind',
        'title',
        'statement_of_claim',
        'claimed_severity',
        'court_severity',
        'jury_entitled',
        'jury_waived',
        'filed_via_form',
        'filed_by_user_id',
        'filed_on_behalf_of_user_id',
        'advocate_id',
        'panel_id',
        'jury_id',
        'appeal_of_case_id',
        'status',
        'double_jeopardy_locked',
        'accepted_at',
        'decided_at',
        'closed_at',
    ];

    protected $casts = [
        'jury_entitled' => 'boolean',
        'jury_waived' => 'boolean',
        'double_jeopardy_locked' => 'boolean',
        'accepted_at' => 'datetime',
        'decided_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function judiciary(): BelongsTo
    {
        return $this->belongsTo(Judiciary::class, 'judiciary_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function advocate(): BelongsTo
    {
        return $this->belongsTo(Advocate::class, 'advocate_id');
    }

    public function appealOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'appeal_of_case_id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(CaseParty::class, 'case_id');
    }

    public function filings(): HasMany
    {
        return $this->hasMany(CaseFiling::class, 'case_id');
    }

    public function panel(): HasOne
    {
        return $this->hasOne(Panel::class, 'case_id');
    }

    public function jury(): HasOne
    {
        return $this->hasOne(Jury::class, 'case_id');
    }

    public function verdict(): HasOne
    {
        return $this->hasOne(Verdict::class, 'case_id');
    }
}

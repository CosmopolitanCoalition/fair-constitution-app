<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article IV §4 — a party on one side of a case. `accused` marks the natural
 * person entitled to a jury + counsel in a criminal case. The right to
 * representation (Art. I) is rendered, never enforced: a defendant may be
 * self-represented.
 */
class CaseParty extends Model
{
    use HasUuids, SoftDeletes;

    public const ROLE_PROSECUTION = 'prosecution';

    public const ROLE_PLAINTIFF = 'plaintiff';

    public const ROLE_DEFENDANT = 'defendant';

    public const ROLE_RESPONDENT = 'respondent';

    public const ROLE_INTERVENOR = 'intervenor';

    public const ROLE_ACCUSED = 'accused';

    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_ORGANIZATION = 'organization';

    public const TYPE_JURISDICTION = 'jurisdiction';

    public const TYPE_GOVERNMENT_BODY = 'government_body';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_SUBSTITUTED = 'substituted';

    protected $fillable = [
        'id',
        'case_id',
        'party_role',
        'party_type',
        'party_user_id',
        'party_ref_type',
        'party_ref_id',
        'represented_by_advocate_id',
        'retainer_note',
        'status',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(CourtCase::class, 'case_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'party_user_id');
    }

    public function advocate(): BelongsTo
    {
        return $this->belongsTo(Advocate::class, 'represented_by_advocate_id');
    }
}

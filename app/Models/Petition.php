<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESM-10 — citizen petition (C-8, Art. II §6 "Creation of Laws by
 * Petition"). Thresholds are SNAPSHOTS taken at creation (CLK-17):
 * `population_basis` is the CIVIC population (active associations, never
 * WorldPop), `threshold_count` = ceil(basis × pct / 100). Signatures are
 * revocable until the F-ELB-005 audit; the audited count freezes at the
 * threshold check.
 *
 * Kill-paths: failed signature audit → invalidated; unconstitutional
 * finding (Phase E F-JDG-008) → invalidated. Phase C HOLDS petitions at
 * constitutional_review — the judiciary is forming, and the kill-path is
 * constitutional, not skippable. PetitionService::stubConstitutionalReview
 * is the explicit, audited Phase C advance (review_stub = true).
 */
class Petition extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_CREATED               = 'created';
    public const STATUS_GATHERING             = 'gathering';
    public const STATUS_THRESHOLD_REACHED     = 'threshold_reached';
    public const STATUS_SIGNATURE_AUDIT       = 'signature_audit';
    public const STATUS_CONSTITUTIONAL_REVIEW = 'constitutional_review';
    public const STATUS_VALIDATED             = 'validated';
    public const STATUS_ON_BALLOT             = 'on_ballot';
    public const STATUS_ADOPTED               = 'adopted';
    public const STATUS_REJECTED              = 'rejected';
    public const STATUS_INVALIDATED           = 'invalidated';

    /** act_types a petition may carry — no dual_supermajority by petition. */
    public const ACT_TYPES = ['ordinary', 'setting_change', 'supermajority'];

    /** Statuses in which signing (and revoking) stays open (mockup contract). */
    public const SIGNABLE_STATUSES = [self::STATUS_GATHERING, self::STATUS_THRESHOLD_REACHED];

    protected $fillable = [
        'id',
        'creator_user_id',
        'jurisdiction_id',
        'title',
        'law_text',
        'act_type',
        'targets_setting_key',
        'proposed_value',
        'scale',
        'scope_judiciary_id',
        'population_basis',
        'threshold_pct',
        'threshold_count',
        'status',
        'audit_result',
        'review_case_id',
        'review_stub',
        'referendum_question_id',
    ];

    protected $casts = [
        'proposed_value'   => 'array',
        'scale'            => 'array',
        'population_basis' => 'integer',
        'threshold_pct'    => 'decimal:2',
        'threshold_count'  => 'integer',
        'audit_result'     => 'array',
        'review_stub'      => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(PetitionSignature::class, 'petition_id');
    }

    public function referendumQuestion(): BelongsTo
    {
        return $this->belongsTo(ReferendumQuestion::class, 'referendum_question_id');
    }

    /** Unrevoked signature count — the live CLK-17 quantity. */
    public function liveSignatureCount(): int
    {
        return $this->signatures()->whereNull('revoked_at')->count();
    }
}

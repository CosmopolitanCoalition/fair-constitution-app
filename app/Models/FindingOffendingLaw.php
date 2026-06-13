<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The reserved multi-law fan-out join (PHASE_E_DESIGN_challenge_law §A E-2 +
 * §I). §5.2 says "what law(s) are in error" (plural); the common case is a
 * single offending law (read off findings.offending_law_id). A finding
 * implicating several laws gets one row here per law (each with its own
 * recommended remedy + window). V1 builds the single-law spine and one window
 * pair; this join reserves the N-law fan-out without committing the
 * clock-multiplicity fork (q-ledger).
 */
class FindingOffendingLaw extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'finding_id',
        'law_id',
        'version_no',
        'remedy_recommendation_id',
    ];

    protected $casts = [
        'version_no' => 'integer',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(ConstitutionalFinding::class, 'finding_id');
    }

    public function law(): BelongsTo
    {
        return $this->belongsTo(Law::class, 'law_id');
    }
}

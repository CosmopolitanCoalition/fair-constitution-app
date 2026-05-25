<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-row review decision captured during Setup wizard's Step 4 manual data review.
 *
 * One row per (category, jurisdiction_id). Re-deciding overwrites the existing
 * row's `decision` + `note`. No autofix happens as a result of saving — the
 * decision is operator-recorded context for any future remediation flow.
 */
class DataReviewDecision extends Model
{
    use SoftDeletes;

    protected $table = 'data_review_decisions';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'category',
        'jurisdiction_id',
        'decision',
        'note',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }
}

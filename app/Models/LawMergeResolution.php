<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One per-law decision in a disintermediation merge (Art. V §8): incorporate the
 * intermediary law into a constituent (via EnactmentService::amendLaw, history
 * preserved), defer it, or let it lapse.
 */
class LawMergeResolution extends Model
{
    use HasUuids;

    public const DECISION_INCORPORATE = 'incorporate';

    public const DECISION_DEFER = 'defer';

    public const DECISION_LAPSE = 'lapse';

    protected $fillable = [
        'id', 'process_id', 'law_id', 'target_jurisdiction_id',
        'decision', 'resulting_law_id', 'resolved_by',
    ];
}

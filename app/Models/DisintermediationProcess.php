<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A disintermediation process (F-LEG-030, Art. V §8). UNANIMITY of constituents
 * + encompassing consent; on passage the intermediary's laws merge into its
 * former constituents and its children re-point to the encompassing jurisdiction.
 */
class DisintermediationProcess extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_MERGED = 'merged';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'id', 'intermediary_jurisdiction_id', 'encompassing_jurisdiction_id',
        'constituent_process_id', 'encompassing_consent', 'encompassing_consent_vote_id', 'status',
    ];

    protected $casts = [
        'encompassing_consent' => 'boolean',
    ];

    public function constituentProcess(): BelongsTo
    {
        return $this->belongsTo(MultiJurisdictionVote::class, 'constituent_process_id');
    }

    public function lawMergeResolutions(): HasMany
    {
        return $this->hasMany(LawMergeResolution::class, 'process_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A union formation/join/exit process (F-LEG-029, Art. V §7). Dual ratification:
 * applicant population supermajority + union-constituent supermajority.
 */
class UnionProcess extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_FORMATION = 'formation';

    public const KIND_JOIN = 'join';

    public const KIND_EXIT = 'exit';

    public const STATUS_OPEN = 'open';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'id', 'kind', 'applicant_jurisdiction_ids', 'union_jurisdiction_id',
        'compatibility_diff', 'codified_variables', 'applicant_referendum_election_id',
        'applicant_supermajority_met', 'constituent_process_id', 'status',
        'resulting_jurisdiction_id', 'initiating_legislature_id',
    ];

    protected $casts = [
        'applicant_jurisdiction_ids' => 'array',
        'compatibility_diff' => 'array',
        'codified_variables' => 'array',
        'applicant_supermajority_met' => 'boolean',
    ];

    public function constituentProcess(): BelongsTo
    {
        return $this->belongsTo(MultiJurisdictionVote::class, 'constituent_process_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One constituent jurisdiction's consent within a multi-jurisdiction
 * process (C-5). `chamber_vote_id` links the constituent chamber's own
 * peg-quorum vote when one was held.
 */
class ConstituentConsent extends Model
{
    use HasUuids;

    public $timestamps = false;

    public const RESULT_PENDING = 'pending';
    public const RESULT_YES     = 'yes';
    public const RESULT_NO      = 'no';

    protected $fillable = [
        'id',
        'process_id',
        'jurisdiction_id',
        'legislature_id',
        'chamber_vote_id',
        'result',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function process(): BelongsTo
    {
        return $this->belongsTo(MultiJurisdictionVote::class, 'process_id');
    }
}

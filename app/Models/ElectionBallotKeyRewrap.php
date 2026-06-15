<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evidence of one ballot-key re-wrap on an autonomy flip (Phase G, G5a). Records
 * that an election's data key was re-wrapped to the gaining cluster AND proven to
 * reproduce the certified counts before commit. Holds NO key material — only
 * fingerprints of the (ciphertext) wrapped blobs and a digest over PUBLIC record
 * hashes.
 */
class ElectionBallotKeyRewrap extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'election_id',
        'jurisdiction_id',
        'from_cluster_id',
        'to_cluster_id',
        'prior_wrap_fingerprint',
        'new_wrap_fingerprint',
        'races_verified',
        'count_record_digest',
        'verified_at',
    ];

    protected $casts = [
        'races_verified' => 'integer',
        'verified_at' => 'datetime',
    ];

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }
}

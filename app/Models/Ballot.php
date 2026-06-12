<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anonymous ballot content — the voter-free half of the secrecy boundary
 * (ESM-05, B-7; design §B.5).
 *
 * INVARIANTS (pinned by BallotSecrecyTest asserting the column list):
 *  - NO user column, NO envelope column, NO relation to anything
 *    voter-shaped — the schema-level unlinkability IS the constitutional
 *    guarantee (Art. II).
 *  - NO timestamps ($timestamps = false): wall-clock insert time is itself
 *    a linking channel; the hour-truncated `cast_bucket` is the only time.
 *  - Single writer: app/Domain/Ballots/BallotBox.php (WI-B2).
 *
 * `payload_encrypted` (sodium secretbox, per-election key) and `salt` (the
 * commitment salt, returned once in the voter receipt) are hidden from
 * serialization. `ballot_hash` = sha256(salt ‖ canonical ranking JSON) is
 * the published self-audit commitment.
 */
class Ballot extends Model
{
    use HasUuids;

    public const KIND_RANKED     = 'ranked';
    public const KIND_REFERENDUM = 'referendum';

    /** No created_at/updated_at — see class docblock. */
    public $timestamps = false;

    protected $fillable = [
        'id',
        'race_id',
        'kind',
        'referendum_question_id',
        'payload_encrypted',
        'salt',
        'ballot_hash',
        'cast_bucket',
        'counted',
    ];

    /** Content + salt never serialize; only the hash is publishable. */
    protected $hidden = [
        'payload_encrypted',
        'salt',
    ];

    protected $casts = [
        'cast_bucket' => 'datetime',
        'counted'     => 'boolean',
    ];

    public function race(): BelongsTo
    {
        return $this->belongsTo(ElectionRace::class, 'race_id');
    }

    public function scopeCounted($query)
    {
        return $query->where('counted', true);
    }
}

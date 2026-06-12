<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One threshold LANE of a chamber vote (C-3): 'all' for unicameral and
 * committee bodies, 'type_a'/'type_b' (exactly two rows) for bicameral.
 *
 * `serving`, `quorum_required` and `required_yes` are SNAPSHOTS taken at
 * open through the PROTECTED ConstitutionalValidator functions — readers
 * render these numbers, never recompute. `present`/`quorate`/`passed`
 * are set at close. Identical math for every lane = q-ledger #q7 dual
 * agreement falls out structurally.
 */
class ChamberVoteTally extends Model
{
    use HasUuids;

    public $timestamps = false;

    public const LANE_ALL    = 'all';
    public const LANE_TYPE_A = 'type_a';
    public const LANE_TYPE_B = 'type_b';

    protected $fillable = [
        'id',
        'vote_id',
        'lane',
        'serving',
        'quorum_required',
        'required_yes',
        'present',
        'yes',
        'no',
        'abstain',
        'quorate',
        'passed',
    ];

    protected $casts = [
        'serving'         => 'integer',
        'quorum_required' => 'integer',
        'required_yes'    => 'integer',
        'present'         => 'integer',
        'yes'             => 'integer',
        'no'              => 'integer',
        'abstain'         => 'integer',
        'quorate'         => 'boolean',
        'passed'          => 'boolean',
    ];

    public function vote(): BelongsTo
    {
        return $this->belongsTo(ChamberVote::class, 'vote_id');
    }
}

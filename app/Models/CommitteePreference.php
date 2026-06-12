<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-LEG-010 — a member's ranked committee preferences (ordered committee
 * ids, most preferred first). Re-submittable until an F-SPK-005 run
 * consumes them; the run snapshots all inputs into its audit payload, so
 * later edits affect only future runs (§C.3).
 */
class CommitteePreference extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'legislature_id',
        'member_id',
        'rankings',
        'submitted_at',
    ];

    protected $casts = [
        'rankings'     => 'array',
        'submitted_at' => 'datetime',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'member_id');
    }
}

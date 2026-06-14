<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A border settlement between two jurisdictions (Art. V §2). Adopted only if a
 * supermajority of the AFFECTED-AREA population agrees.
 */
class BorderSettlement extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_ADOPTED = 'adopted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'id', 'jurisdiction_a_id', 'jurisdiction_b_id', 'affected_jurisdiction_ids',
        'affected_population', 'referendum_election_id', 'affected_supermajority_met',
        'jurisdiction_map_id', 'status',
    ];

    protected $casts = [
        'affected_jurisdiction_ids' => 'array',
        'affected_population' => 'integer',
        'affected_supermajority_met' => 'boolean',
    ];
}

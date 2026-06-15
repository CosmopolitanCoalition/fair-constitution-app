<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evidence of one operational-bundle transfer on an autonomy flip (Phase G, G5).
 * Holds NO key material and NOT the sealed blob — only the subtree, the peer, the
 * election-key counts, and a fingerprint of the opaque sealed bundle.
 */
class OperationalPartitionExport extends Model
{
    use HasUuids, SoftDeletes;

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    public const STATUS_SEALED = 'sealed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'id',
        'root_jurisdiction_id',
        'direction',
        'peer_server_id',
        'election_count',
        'applied_count',
        'sealed_fingerprint',
        'status',
    ];

    protected $casts = [
        'election_count' => 'integer',
        'applied_count' => 'integer',
    ];
}

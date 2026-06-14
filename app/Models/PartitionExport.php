<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A signed partition bundle for an authority flip (Phase F, WF-JUR-08).
 * Status walks the two-phase flip: prepared → signed → transmitted →
 * ingested → flip_committed (failed/reverted on a no-ACK peer).
 */
class PartitionExport extends Model
{
    use HasUuids, SoftDeletes;

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_TRANSMITTED = 'transmitted';

    public const STATUS_INGESTED = 'ingested';

    public const STATUS_FLIP_COMMITTED = 'flip_committed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REVERTED = 'reverted';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'direction',
        'peer_id',
        'manifest',
        'checksum',
        'checkpoint_audit_seq',
        'signed_by',
        'signature',
        'status',
        'authority_flipped_at',
        'error',
    ];

    protected $casts = [
        'manifest' => 'array',
        'checkpoint_audit_seq' => 'integer',
        'authority_flipped_at' => 'datetime',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(FederationPeer::class, 'peer_id');
    }
}

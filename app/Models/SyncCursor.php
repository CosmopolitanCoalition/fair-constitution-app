<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A resumable cold-sync cursor (Phase G). Tracks page-by-page progress of a
 * mirror pulling a peer's full corpus.
 */
class SyncCursor extends Model
{
    use HasUuids, SoftDeletes;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const MODE_COLD = 'cold';

    public const MODE_INCREMENTAL = 'incremental';

    public const STATUS_OPEN = 'open';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_ABORTED = 'aborted';

    protected $fillable = [
        'id', 'peer_id', 'direction', 'mode', 'anchor_seq', 'from_seq',
        'next_from_seq', 'page_size', 'pages_applied', 'records_applied',
        'last_page_hash', 'status', 'abort_reason', 'detail',
    ];

    protected $casts = [
        'anchor_seq' => 'integer',
        'from_seq' => 'integer',
        'next_from_seq' => 'integer',
        'page_size' => 'integer',
        'pages_applied' => 'integer',
        'records_applied' => 'integer',
        'detail' => 'array',
    ];

    public function peer(): BelongsTo
    {
        return $this->belongsTo(FederationPeer::class, 'peer_id');
    }
}

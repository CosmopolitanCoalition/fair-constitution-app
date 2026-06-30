<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A resumable per-(peer, table) cursor for the paginated foundation drain (seed
 * redesign) — the geodata-foundation counterpart of {@see SyncCursor} (the audit
 * tail). One row tracks how far a mirror has UPSERTed one foundation table from a
 * donor: from_key/next_from_key are the keyset watermark (JSON arrays so the same
 * shape carries a uuid PK and a composite PK), rows_applied/total_rows drive the
 * per-table progress bar, and status flips to complete on a short (caught-up) page.
 */
class FoundationSyncCursor extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_ABORTED = 'aborted';

    protected $fillable = [
        'id', 'peer_id', 'table_name', 'from_key', 'next_from_key', 'page_size',
        'pages_applied', 'rows_applied', 'total_rows', 'status', 'abort_reason', 'detail',
    ];

    protected $casts = [
        'from_key' => 'array',
        'next_from_key' => 'array',
        'page_size' => 'integer',
        'pages_applied' => 'integer',
        'rows_applied' => 'integer',
        'total_rows' => 'integer',
        'detail' => 'array',
    ];

    public function peer(): BelongsTo
    {
        return $this->belongsTo(FederationPeer::class, 'peer_id');
    }
}

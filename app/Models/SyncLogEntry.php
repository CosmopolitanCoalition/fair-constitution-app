<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One federation sync exchange (`sync_log`, Phase F, WF-JUR-06).
 *
 * APPEND-ONLY — same posture as AuditEntry: no soft deletes, no updated_at, and
 * the table raises on UPDATE/DELETE/TRUNCATE at the database layer. This model
 * mirrors that: updating or deleting through Eloquent throws before a query is
 * attempted. Rows are written exclusively by FederationSyncService.
 */
class SyncLogEntry extends Model
{
    use HasUuids;

    protected $table = 'sync_log';

    /** Append-only: there is no updated_at column. */
    public const UPDATED_AT = null;

    public const RESULT_APPLIED = 'applied';

    public const RESULT_CONFLICT_AUTHORITATIVE_WINS = 'conflict_authoritative_wins';

    public const RESULT_REJECTED_TAMPER = 'rejected_tamper';

    public const RESULT_REJECTED_NON_AUTHORITATIVE = 'rejected_non_authoritative';

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    protected $fillable = [
        'id',
        'peer_id',
        'direction',
        'payload_hash',
        'peer_head_hash',
        'from_seq',
        'to_seq',
        'result',
        'audit_seq',
        'detail',
    ];

    protected $casts = [
        'seq' => 'integer',
        'from_seq' => 'integer',
        'to_seq' => 'integer',
        'audit_seq' => 'integer',
        'detail' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('sync_log is append-only — entries can never be updated.'));
        static::deleting(fn () => throw new LogicException('sync_log is append-only — entries can never be deleted.'));
    }
}

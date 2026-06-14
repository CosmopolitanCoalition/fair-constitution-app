<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A signed checkpoint of our audit head (`audit_checkpoints`, Phase F).
 *
 * APPEND-ONLY (AuditEntry posture): no soft deletes, no updated_at, DB raises on
 * UPDATE/DELETE/TRUNCATE, and the model throws on update/delete. Written
 * exclusively by FederationSyncService::publishCheckpoint().
 */
class AuditCheckpoint extends Model
{
    use HasUuids;

    protected $table = 'audit_checkpoints';

    /** Append-only: there is no updated_at column. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'id',
        'audit_seq',
        'head_hash',
        'published_to',
        'signature',
    ];

    protected $casts = [
        'seq' => 'integer',
        'audit_seq' => 'integer',
        'published_to' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_checkpoints is append-only — entries can never be updated.'));
        static::deleting(fn () => throw new LogicException('audit_checkpoints is append-only — entries can never be deleted.'));
    }
}

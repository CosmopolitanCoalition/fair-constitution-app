<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A single link in the constitutional audit chain (`audit_log`).
 *
 * IMMUTABLE — no soft deletes, no updates, ever. The table is append-only
 * by construction (Postgres trigger raises on UPDATE/DELETE/TRUNCATE) and
 * this model mirrors that posture: updating or deleting through Eloquent
 * throws before a query is even attempted. Rows are written exclusively
 * by App\Services\AuditService::append().
 */
class AuditEntry extends Model
{
    protected $table = 'audit_log';

    protected $keyType = 'string';
    public $incrementing = false;

    /** Append-only: there is no updated_at column. */
    public const UPDATED_AT = null;

    /** Writes go through AuditService only; guarding nothing is safe here. */
    protected $guarded = [];

    protected $casts = [
        'seq'         => 'integer',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
        'payload'     => 'array',
        'rejected'    => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_log is append-only — entries can never be updated.'));
        static::deleting(fn () => throw new LogicException('audit_log is append-only — entries can never be deleted.'));
    }
}

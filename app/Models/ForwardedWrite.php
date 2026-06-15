<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A forwarded write recorded on the authoritative leader (Phase G, G4). The
 * (origin_server_id, idempotency_key) unique index makes forwarding
 * exactly-once: a settled row is replayed, never re-executed.
 */
class ForwardedWrite extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'id',
        'origin_server_id',
        'idempotency_key',
        'form_id',
        'jurisdiction_id',
        'status',
        'audit_seq',
        'result_hash',
        'citation',
    ];

    protected $casts = [
        'audit_seq' => 'integer',
    ];

    public function isSettled(): bool
    {
        return $this->status !== self::STATUS_PENDING;
    }

    /**
     * The outcome payload the WriteController returns — identical on first
     * execution and on every idempotent replay.
     *
     * @return array<string,mixed>
     */
    public function outcome(): array
    {
        if ($this->status === self::STATUS_REJECTED) {
            return [
                'status' => self::STATUS_REJECTED,
                'citation' => $this->citation,
            ];
        }

        return [
            'status' => $this->status,
            'audit_seq' => $this->audit_seq,
            'result_hash' => $this->result_hash,
        ];
    }
}

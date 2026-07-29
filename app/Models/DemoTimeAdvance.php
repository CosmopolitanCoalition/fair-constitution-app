<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One logical demo-mesh time advance, recorded on every node that applies it
 * (demo_time_advances — Wave 3, DEMO_MESH_TIME_COORDINATION.md §2).
 *
 * APPEND-ONLY IDEMPOTENCY LEDGER. `advance_id` is the coordinator-minted key,
 * identical on every node in the mesh — the audit seq/hash are per-node and
 * cannot dedup a replay, so the dedup lives here. A row means "this node has
 * already applied that logical advance"; DemoMeshTimeCoordinator inserts with
 * insertOrIgnore so a re-delivered record is a no-op, and because the ledger is
 * durable the guarantee survives a restart.
 *
 * No soft delete: the ledger is the record of what was applied and is never
 * rewound (a played timeline stays distinguishable from a lived one, like the
 * audit entries the advance also writes).
 */
class DemoTimeAdvance extends Model
{
    protected $table = 'demo_time_advances';

    /** advance_id is the coordinator-minted uuid PK — not auto-incrementing, not generated here. */
    protected $primaryKey = 'advance_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public const ORIGIN_LOCAL = 'local';

    public const ORIGIN_SYNC = 'sync';

    protected $fillable = [
        'advance_id',
        'days',
        'issued_by',
        'issued_at',
        'plan_hash',
        'origin',
        'source_peer_id',
        'applied_at',
    ];

    protected $casts = [
        'days' => 'integer',
        'issued_at' => 'datetime',
        'applied_at' => 'datetime',
    ];
}

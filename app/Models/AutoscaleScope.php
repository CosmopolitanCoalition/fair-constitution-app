<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sweep scope inside an autoscale run — the pull engine's sweep work
 * unit (re-engineering 2026-07-19).
 *
 * A sweep item's giant cascade is split into per-scope rows: the root scope
 * is minted at enumeration; each completed scope materializes its giant
 * children (DistrictingService::giantChildrenForScope — the one-frame law)
 * in the same transaction that marks it done. Workers claim scopes with
 * FOR UPDATE SKIP LOCKED, so Earth's provinces sweep in parallel while the
 * item stays the per-legislature unit of review/adoption/drift.
 */
class AutoscaleScope extends Model
{
    use HasUuids;

    public const STATUSES = ['pending', 'running', 'done', 'failed'];

    protected $table = 'autoscale_scopes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'run_id',
        'item_id',
        'legislature_id',
        'scope_jurisdiction_id',
        'parent_scope_id',
        'depth',
        'status',
        'claim_token',
        'reason',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'depth'       => 'integer',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutoscaleRun::class, 'run_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AutoscaleItem::class, 'item_id');
    }
}

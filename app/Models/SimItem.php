<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claimable unit of populate work.
 *
 * Mirrors `AutoscaleItem`. The status column IS the progress store — there is
 * no separate progress table, because the live bars run a fresh
 * `COUNT(*) FILTER (…) GROUP BY kind` per poll against
 * `sim_items_claim_idx` / `sim_items_layers_idx`. That is the pattern the
 * districting Step-3 UI already proved: real numbers every 2 s, never a
 * once-a-minute denormalized copy.
 *
 * A failed item never sinks the run: a phase's barrier opens when its pool has
 * zero pending+running, and done / review / failed all count as settled. A
 * refused unit's absence is honest, and the acceptance scan flags it.
 */
class SimItem extends Model
{
    use HasUuids;

    protected $table = 'sim_items';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'metrics' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_REVIEW = 'review';

    public const STATUS_FAILED = 'failed';

    /** Settled = the barrier may open. Review and failure are settled outcomes. */
    public const SETTLED = [self::STATUS_DONE, self::STATUS_REVIEW, self::STATUS_FAILED];

    /** Open = the barrier stays shut. */
    public const OPEN = [self::STATUS_PENDING, self::STATUS_RUNNING];

    public function run(): BelongsTo
    {
        return $this->belongsTo(SimRun::class, 'run_id');
    }
}

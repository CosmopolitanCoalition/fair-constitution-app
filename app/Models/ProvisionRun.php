<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The Step 4 engine's phase and clock record (Wave 6). One live run at a
 * time; the ledger (provision_ledger) is the work-list and carries no run id.
 */
class ProvisionRun extends Model
{
    use HasUuids;

    public const STATUS_QUEUED  = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_HALTED  = 'halted';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    public const LIVE_STATUSES = ['queued', 'running', 'halted'];

    protected $guarded = [];

    protected $casts = [
        'halt_requested_at' => 'datetime',
        'paused_until'      => 'datetime',
        'ledger_seeded_at'  => 'datetime',
        'started_at'        => 'datetime',
        'finished_at'       => 'datetime',
        'rolled_back_at'    => 'datetime',
        'ledger_total'      => 'integer',
        'ledger_skipped'    => 'integer',
        'shells_done'       => 'integer',
        'units_done'        => 'integer',
        'review_count'      => 'integer',
        'baseline'          => 'array',
        'world_baseline'    => 'array',
    ];

    public function haltRequested(): bool
    {
        return $this->halt_requested_at !== null;
    }

    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }
}

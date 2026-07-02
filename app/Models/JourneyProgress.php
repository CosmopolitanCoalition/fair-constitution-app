<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-user journey lesson state (mockups-v3-wiring Phase 3c).
 *
 * NODE-LOCAL and mutable — which 0-based steps of a journey this person has
 * ticked off, and when they completed the arc. Never replicated (the earned
 * ledger is the `achievements` table); unique per (user_id, journey_id).
 * Once `completed_at` is set the step set is FROZEN: completion is a ledger
 * event, never undone (JourneyService enforces).
 */
class JourneyProgress extends Model
{
    use HasUuids;

    protected $table = 'journey_progress';

    protected $fillable = [
        'id',
        'user_id',
        'journey_id',
        'steps_done',
        'completed_at',
    ];

    protected $casts = [
        'steps_done'   => 'array',
        'completed_at' => 'datetime',
    ];
}

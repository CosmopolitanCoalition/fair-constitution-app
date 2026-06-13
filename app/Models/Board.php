<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * THE unified board (Phase D binding decision): departments, CGCs, and
 * private organizations share ONE boards + board_seats set — one
 * co-determination engine (Art. III §6).
 *
 * `worker_seats` (required entitlement snapshot), `worker_headcount`, and
 * `composition_valid` are co-determination OUTPUT — written only through
 * CoDeterminationService / OrgBoardService::reconcile (pinned by
 * WorkerRepresentationTest). `composition_valid = false` blocks board
 * acts except the curing elections + chair vote.
 */
class Board extends Model
{
    use HasUuids, SoftDeletes;

    public const BOARDABLE_ORGANIZATIONS = 'organizations';
    public const BOARDABLE_DEPARTMENTS   = 'departments';

    public const STATUS_FORMING   = 'forming';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_DISSOLVED = 'dissolved';

    protected $fillable = [
        'id',
        'boardable_type',
        'boardable_id',
        'owner_seats',
        'worker_seats',
        'worker_headcount',
        'chair_seat_id',
        'composition_valid',
        'status',
        'cycle_months',
    ];

    protected $casts = [
        'owner_seats'       => 'integer',
        'worker_seats'      => 'integer',
        'worker_headcount'  => 'integer',
        'composition_valid' => 'boolean',
        'cycle_months'      => 'integer',
    ];

    public function seats(): HasMany
    {
        return $this->hasMany(BoardSeat::class, 'board_id');
    }

    public function chairSeat(): BelongsTo
    {
        return $this->belongsTo(BoardSeat::class, 'chair_seat_id');
    }

    public function boardable(): MorphTo
    {
        return $this->morphTo(null, 'boardable_type', 'boardable_id');
    }

    /** Seated seats of the owner SIDE (governor / owner_elected). */
    public function seatedOwnerSideSeats(): int
    {
        return $this->seats()
            ->whereIn('seat_class', [BoardSeat::CLASS_GOVERNOR, BoardSeat::CLASS_OWNER_ELECTED])
            ->where('status', BoardSeat::STATUS_SEATED)
            ->count();
    }

    /** The organization this board governs, when boardable is an org. */
    public function organization(): ?Organization
    {
        return $this->boardable_type === self::BOARDABLE_ORGANIZATIONS
            ? Organization::query()->find($this->boardable_id)
            : null;
    }

    /** The board's jurisdiction, resolved through its boardable. */
    public function jurisdictionId(): ?string
    {
        $row = \Illuminate\Support\Facades\DB::table($this->boardable_type)
            ->where('id', $this->boardable_id)
            ->first(['jurisdiction_id']);

        return $row?->jurisdiction_id !== null ? (string) $row->jurisdiction_id : null;
    }
}

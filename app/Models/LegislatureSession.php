<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One chamber session (C-2). Drives the ESM-08 motion context and CLK-02.
 *
 * Threshold snapshots (`serving_at_open`, `quorum_required`, per-kind
 * jsonb mirrors) are taken at open through ConstitutionalValidator —
 * serving members only; a vacant seat is simply not serving. Attendance
 * feeds the quorum CALL and the public record, NEVER a vote denominator.
 */
class LegislatureSession extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_SCHEDULED     = 'scheduled';
    public const STATUS_OPEN          = 'open';
    public const STATUS_ADJOURNED     = 'adjourned';
    public const STATUS_FAILED_QUORUM = 'failed_quorum';
    public const STATUS_CANCELLED     = 'cancelled';

    /** Agenda item kinds (jsonb `agenda` entries). */
    public const AGENDA_KINDS = [
        'emergency_power', 'constitutional_matter', 'committee_report',
        'bill_floor', 'motion', 'statement', 'general',
    ];

    protected $fillable = [
        'id',
        'legislature_id',
        'session_no',
        'called_by_member_id',
        'scheduled_for',
        'opened_at',
        'adjourned_at',
        'serving_at_open',
        'quorum_required',
        'serving_by_kind',
        'quorum_required_by_kind',
        'quorum_met',
        'agenda',
        'minutes_record_id',
        'status',
    ];

    protected $casts = [
        'session_no'              => 'integer',
        'scheduled_for'           => 'datetime',
        'opened_at'               => 'datetime',
        'adjourned_at'            => 'datetime',
        'serving_at_open'         => 'integer',
        'quorum_required'         => 'integer',
        'serving_by_kind'         => 'array',
        'quorum_required_by_kind' => 'array',
        'quorum_met'              => 'boolean',
        'agenda'                  => 'array',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function calledBy(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'called_by_member_id');
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(SessionAttendance::class, 'session_id');
    }

    public function motions(): HasMany
    {
        return $this->hasMany(Motion::class, 'session_id');
    }

    /** Locked slot-1 agenda items still awaiting address (Art. II §2 order). */
    public function pendingFirstBusiness(): array
    {
        return array_values(array_filter(
            $this->agenda ?? [],
            fn (array $item) => ($item['slot'] ?? null) === 1
                && ($item['locked'] ?? false)
                && ($item['status'] ?? null) === 'pending'
        ));
    }
}

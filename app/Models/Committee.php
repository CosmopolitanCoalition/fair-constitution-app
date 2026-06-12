<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * I-COM Committee (Phase C chamber ops §C). Created by F-LEG-009 adoption
 * (supermajority chamber vote — `created_by_vote_id` is a cross-scope soft
 * ref into chamber_votes); seated by the F-SPK-005 assignment run; chaired
 * via F-LEG-011 whole-house RCV.
 *
 * Bicameral kind split (Art. V §3 mirror): `type_a_seats`/`type_b_seats`
 * NULL = unicameral chamber (unsplit seats).
 */
class Committee extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_CREATED   = 'created';
    public const STATUS_SEATED    = 'seated';
    public const STATUS_DISSOLVED = 'dissolved';

    protected $fillable = [
        'id',
        'legislature_id',
        'name',
        'purpose',
        'seats',
        'type_a_seats',
        'type_b_seats',
        'created_by_vote_id',
        'created_by_law_id',
        'chair_member_id',
        'alternate_member_id',
        'status',
    ];

    protected $casts = [
        'seats'        => 'integer',
        'type_a_seats' => 'integer',
        'type_b_seats' => 'integer',
    ];

    public function legislature(): BelongsTo
    {
        return $this->belongsTo(Legislature::class, 'legislature_id');
    }

    public function seatRows(): HasMany
    {
        return $this->hasMany(CommitteeSeat::class, 'committee_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(CommitteeMeeting::class, 'committee_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(CommitteeReport::class, 'committee_id');
    }

    public function chair(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'chair_member_id');
    }

    public function alternate(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'alternate_member_id');
    }

    public function scopeLive($query)
    {
        return $query->whereIn('status', [self::STATUS_CREATED, self::STATUS_SEATED]);
    }
}

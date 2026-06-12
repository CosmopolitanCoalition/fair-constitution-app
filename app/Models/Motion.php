<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ESM-08 motion (C-4): submitted → recognized → debated → voted →
 * adopted | failed, plus withdrawn. Referral kinds name a bill;
 * 'amendment' carries amendment_text which becomes a bill_versions row
 * on adoption. The deciding vote is a procedural_motion chamber vote
 * (majority of all serving — the unstated-threshold owner ruling).
 */
class Motion extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_PROCEDURAL      = 'procedural';
    public const KIND_REFERRAL        = 'referral';
    public const KIND_DIRECT_TO_FLOOR = 'direct_to_floor';
    public const KIND_AMENDMENT       = 'amendment';
    public const KIND_TABLE           = 'table';
    public const KIND_ADJOURN         = 'adjourn';
    public const KIND_REPLACE_SPEAKER = 'replace_speaker';
    public const KIND_OTHER           = 'other';

    public const KINDS = [
        self::KIND_PROCEDURAL, self::KIND_REFERRAL, self::KIND_DIRECT_TO_FLOOR,
        self::KIND_AMENDMENT, self::KIND_TABLE, self::KIND_ADJOURN,
        self::KIND_REPLACE_SPEAKER, self::KIND_OTHER,
    ];

    /** Kinds whose adoption acts on a named bill. */
    public const BILL_KINDS = [
        self::KIND_REFERRAL, self::KIND_DIRECT_TO_FLOOR,
        self::KIND_AMENDMENT, self::KIND_TABLE,
    ];

    public const STATUS_SUBMITTED  = 'submitted';
    public const STATUS_RECOGNIZED = 'recognized';
    public const STATUS_DEBATED    = 'debated';
    public const STATUS_VOTED      = 'voted';
    public const STATUS_ADOPTED    = 'adopted';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_WITHDRAWN  = 'withdrawn';

    protected $fillable = [
        'id',
        'session_id',
        'bill_id',
        'moved_by_member_id',
        'seconded_by_member_id',
        'text',
        'kind',
        'status',
        'vote_id',
        'amendment_text',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LegislatureSession::class, 'session_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(LegislatureMember::class, 'moved_by_member_id');
    }

    public function vote(): BelongsTo
    {
        return $this->belongsTo(ChamberVote::class, 'vote_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * F-ELB-004 certification record (B-9). One current certification per
 * election (partial unique); an audit re-run with outcome 'corrected'
 * supersedes it (status 'superseded_by_audit') via a fresh F-ELB-004.
 * `certified_by_member_id` NULL = bootstrap system board (engine check
 * keeps it effectively required, WI-B4). `count_record_hash` = hash over
 * all race record_hashes, sealed into the audit chain.
 */
class ElectionCertification extends Model
{
    use HasUuids;

    public const STATUS_CERTIFIED           = 'certified';
    public const STATUS_SUPERSEDED_BY_AUDIT = 'superseded_by_audit';

    protected $fillable = [
        'id',
        'election_id',
        'election_board_id',
        'certified_by_member_id',
        'certified_at',
        'count_record_hash',
        'status',
    ];

    protected $casts = [
        'certified_at' => 'datetime',
    ];

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(ElectionBoard::class, 'election_board_id');
    }

    public function certifiedBy(): BelongsTo
    {
        return $this->belongsTo(ElectionBoardMember::class, 'certified_by_member_id');
    }
}

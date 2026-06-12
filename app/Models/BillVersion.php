<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One full-text version of a bill (C-6) — append-only by convention.
 * change_kind: introduction | committee_amendment | floor_amendment.
 */
class BillVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    public const KIND_INTRODUCTION        = 'introduction';
    public const KIND_COMMITTEE_AMENDMENT = 'committee_amendment';
    public const KIND_FLOOR_AMENDMENT     = 'floor_amendment';

    protected $fillable = [
        'id',
        'bill_id',
        'version_no',
        'law_text',
        'changed_by_member_id',
        'change_kind',
        'created_at',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'created_at' => 'datetime',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }
}

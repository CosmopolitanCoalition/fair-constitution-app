<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-3 — the one-way testimony valve (Plane B → Plane A). When a participant FILES a Matrix
 * message as testimony (F-SOC-002), its body + actor_display are frozen at file time and sealed
 * into public_records; this row records that snapshot. published_record_id is the record's UUID
 * (NOT its seq) — mirroring social_threads.published_record_id. The live Matrix message stays
 * editable; the civic act is the immutable snapshot.
 */
class MatrixEventSnapshot extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'matrix_event_id',
        'matrix_room_id',
        'published_record_id',
        'actor_display',
        'origin_server_ts',
        'body_snapshot',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(PublicRecord::class, 'published_record_id', 'id');
    }
}

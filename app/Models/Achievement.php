<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One earned journey medal (mockups-v3-wiring Phase 3c).
 *
 * APPEND-ONLY LEDGER: database triggers block UPDATE/DELETE/TRUNCATE, so
 * this model deliberately does NOT use SoftDeletes — a soft delete is an
 * UPDATE and can never fire (the `deleted_at` column exists only for
 * schema-parity with the partial-unique pattern). An earned medal is never
 * un-earned.
 *
 * Mesh-replicated SHAPE (source_server_id + audit_seq) per the
 * public_records conventions; FederationSyncService registration is
 * deferred to Phase 4 (mesh code frozen this campaign). `title` is the
 * journey title denormalized at earn time; `audit_seq` seals the earn to
 * the hash chain. All writes flow through JourneyService::markStep()
 * inside its transaction.
 *
 * A medal never changes a vote, a seat, or what you are allowed to do.
 */
class Achievement extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'journey_id',
        'title',
        'source_server_id',
        'audit_seq',
        'earned_at',
    ];

    protected $casts = [
        'earned_at' => 'datetime',
        'audit_seq' => 'integer',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-3 — the append-only audit of every appservice carve-out action (M-1 judicial, M-2
 * rights, M-4 anti-spam). M-3 per-user block is client-side (m.ignored_user_list) and is NEVER
 * an appservice action, so it never appears here. This log is the durable constitutional artifact
 * (the bytes' disappearance is best-effort across the mesh; the LOG is not) AND the cross-mesh
 * "censorship-without-an-order" discontinuity detector — it rides the signed public_records tail.
 */
class MatrixCarveoutLog extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'matrix_carveout_log';

    public const CARVE_M1_JUDICIAL = 'm1_judicial';
    public const CARVE_M2_RIGHTS   = 'm2_rights';
    public const CARVE_M4_ANTISPAM = 'm4_antispam';
    // M-5 is the PHYSICAL-LAW legal-compliance removal — operator-plane (attestation_id IS NULL), NOT a
    // constitutional carve-out. It may NEVER write a server ACL (matrix_server_acls stays m1/m4 only).
    public const CARVE_M5_LEGAL    = 'm5_legal';

    public const ACTION_SOFT_FAIL   = 'soft_fail';   // M-4 + reversible M-1 (suppress relay/display)
    public const ACTION_HARD_REDACT = 'hard_redact'; // M-2 only (content-stripping)
    public const ACTION_SERVER_ACL  = 'server_acl';
    public const ACTION_PURGE       = 'purge';       // M-5 csam_hashmatch ONLY — DELETEs the local bytes

    protected $fillable = [
        'id',
        'matrix_room_id',
        'matrix_event_id',
        'carve_out',
        'action',
        'attestation_id',
        'issuer_server_id',
        'public_records_id',
        'jurisdiction_id',
        'is_seated_at_time',
    ];

    protected $casts = [
        'is_seated_at_time' => 'boolean',
    ];
}

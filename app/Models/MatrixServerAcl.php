<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase K-3 — the per-room m.room.server_acl mirror. A server ACL on a PUBLIC room is written
 * ONLY by the appservice, ONLY for M-1 (logged judicial order) or M-4 (behavior-based abusive
 * server) — never viewpoint. The allow list MUST always retain the local server + every legitimate
 * federation_peer (the allow:[] self-brick / self-ban footgun, enforced in the writing service);
 * this table is the audit trail. The mesh application is rig-gated.
 */
class MatrixServerAcl extends Model
{
    use HasUuids, SoftDeletes;

    public const CARVE_M1_JUDICIAL = 'm1_judicial';
    public const CARVE_M4_ANTISPAM = 'm4_antispam';

    protected $fillable = [
        'id',
        'matrix_room_id',
        'allow',
        'deny',
        'written_by_carve_out',
    ];

    protected $casts = [
        'allow' => 'array',
        'deny'  => 'array',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A mirror's adoption request on the host (Phase G, G2). The nonce ledger that
 * backs anti-replay; `status` walks pending → admitted | rejected | expired.
 */
class ClusterAdoptionRequest extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ADMITTED = 'admitted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'id',
        'applicant_server_id',
        'applicant_public_key',
        'nonce',
        'admission_method',
        'status',
        'join_key_handle',
        'cluster_membership_id',
        // G3c — join-wizard negotiation (advisory; co_member never auto-grants R/W).
        'requested_relation',
        'requested_scope_jurisdiction_id',
        'applicant_name',
        'applicant_url',
        'note',
    ];
}

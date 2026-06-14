<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Who is authoritative for a jurisdiction (Phase F, WF-JUR-08).
 * `claimed_by_peer_id` NULL = this instance; a peer id = that peer.
 */
class AuthorityClaim extends Model
{
    use HasUuids, SoftDeletes;

    public const RESOLUTION_UNCONTESTED = 'uncontested';

    public const RESOLUTION_RECOGNIZED = 'recognized';

    public const RESOLUTION_NEGOTIATING = 'negotiating';

    public const RESOLUTION_MIRRORED = 'mirrored';

    protected $fillable = [
        'id',
        'jurisdiction_id',
        'claimed_by_peer_id',
        'resolution',
        'authority_flipped_at',
        'partition_export_id',
        'notes',
    ];

    protected $casts = [
        'authority_flipped_at' => 'datetime',
    ];

    public function jurisdiction(): BelongsTo
    {
        return $this->belongsTo(Jurisdiction::class, 'jurisdiction_id');
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(FederationPeer::class, 'claimed_by_peer_id');
    }

    /** NULL claimed_by_peer_id means this instance holds authority. */
    public function isLocal(): bool
    {
        return $this->claimed_by_peer_id === null;
    }
}

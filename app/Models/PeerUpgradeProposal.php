<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase G (G-VER) — a signed, subtree-scoped version-upgrade proposal. The upgrade
 * equivalent of partition_exports: a mutable status lifecycle (open → ratified /
 * rejected / superseded) whose every transition is appended to the hash chain, with
 * the Ed25519 signature pinning the immutable proposal core.
 *
 * Only a `constitutional_bump` hits the freeze (Art. II §7) and the hardened
 * admissibility filter (Art. VII); schema/app_release bumps are wire/provenance and
 * flow without either. Carries NO ballots, locations, or keys — version strings +
 * signature + counts only, so it rides the public chain like partition_exports.
 */
class PeerUpgradeProposal extends Model
{
    use HasUuids, SoftDeletes;

    public const KIND_CONSTITUTIONAL_BUMP = 'constitutional_bump';
    public const KIND_SCHEMA_BUMP         = 'schema_bump';
    public const KIND_APP_RELEASE         = 'app_release';
    // Mesh Roles ★7 — a capability grant flowing through the identical dual-meter consent.
    public const KIND_ROLE_GRANT          = 'role_grant';

    public const STATUS_OPEN       = 'open';
    public const STATUS_RATIFIED   = 'ratified';
    public const STATUS_REJECTED   = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'id',
        'kind',
        'from_constitutional_version',
        'to_constitutional_version',
        'from_schema_version',
        'to_schema_version',
        'from_app_release',
        'to_app_release',
        'hardened_params',
        'affected_root_jurisdiction_id',
        'proposed_by_server_id',
        'signature',
        'status',
        'seated_process_id',
        'ratified_at',
        // Mesh Roles ★7 — role_grant proposals only.
        'capability',
        'grant_payload',
    ];

    protected $casts = [
        'hardened_params' => 'array',
        'grant_payload'   => 'array',
        'ratified_at'     => 'datetime',
    ];

    public function consents(): HasMany
    {
        return $this->hasMany(PeerUpgradeConsent::class, 'proposal_id');
    }

    /** The Meter B seated-institution MultiJurisdictionVote, once opened. */
    public function seatedProcess()
    {
        return $this->belongsTo(MultiJurisdictionVote::class, 'seated_process_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}

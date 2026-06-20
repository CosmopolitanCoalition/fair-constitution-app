<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One capability CHANNEL an instance hosts (Mesh Roles & Channels of Trust). The SIBLING of
 * FederationTransport: transports = how you reach a box; capabilities = what a box offers. A box's "role"
 * is the derived set of its enabled channels — never a stored tier. SELF_ASSERTED channels are claims the
 * box makes about itself (affect only the box); GOVERNED channels are power-bearing and may only be
 * enabled with a verified, unexpired grant from the dual-meter consent (the grant receipt lives on the
 * row). Operator-plane vocabulary: 'capability', never 'role', so it can never collide with the citizen
 * R-01…R-30 system (RoleService) — the code-level plane wall (decision I).
 */
class InstanceCapability extends Model
{
    use HasUuids, SoftDeletes;

    /** The closed vocabulary — mirrors FederationTransport::TRANSPORTS + the DB CHECK constraint. */
    public const CHANNELS = [
        'mesh.member', 'mirror', 'etl',
        'broker.dns', 'broker.tls', 'client.serve', 'authority.grant', 'matrix.homeserver', 'voice.sfu',
    ];

    /** Self-asserted — affect only the box, no governance surface, no grant needed. */
    public const SELF_ASSERTED = ['mesh.member', 'mirror', 'etl'];

    /** Power-bearing — enabling requires a verified, unexpired grant (the dual-meter consent). */
    public const GOVERNED = [
        'broker.dns', 'broker.tls', 'client.serve', 'authority.grant', 'matrix.homeserver', 'voice.sfu',
    ];

    public static function isGoverned(string $capability): bool
    {
        return in_array($capability, self::GOVERNED, true);
    }

    protected $table = 'instance_capabilities';

    protected $fillable = [
        'id',
        'server_id',
        'capability',
        'is_self',
        'enabled',
        'priority',
        'granted_by_server_id',
        'grant_signature',
        'grant_expires_at',
    ];

    protected $casts = [
        'is_self'          => 'boolean',
        'enabled'          => 'boolean',
        'priority'         => 'integer',
        'grant_expires_at' => 'datetime',
    ];
}

<?php

namespace App\Services\Matrix;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\MatrixIdentity;
use App\Models\MatrixRoom;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;

/**
 * Phase K-3 (K3-G) / Phase 5 — participation in a jurisdiction's PUBLIC COMMONS (square / halls).
 *
 * The public commons is OPEN (Art. I — free movement + equal treatment): ANY authenticated player may
 * speak in a jurisdiction's public square / halls, resident OR visitor. Residency gates GOVERNANCE
 * POWERS (voting, standing for office, the role tools, and the testimony seal into the record), which
 * the GAME enforces — NOT access to the room. The post is sent under the speaker's pseudonymous handle
 * (the `u-<handle>` mxid, never their legal name). Officeholder speech carries a cga.acting_seat
 * annotation derived LIVE from current roles at SEND TIME — a POWER BADGE, not an access gate: a member
 * who vacates mid-session loses it (re-derived, never cached); a seated member's #halls posts are
 * M-4-exempt (Art. II §3, the unobstructable priority channel — consumed by the M-4 path). The ONLY
 * thing that may restrict commons participation is a content-neutral, social-scoped abuse LIMITATION
 * (Layer 3) — which never touches a player's vote / candidacy / role tools.
 *
 * (Corrected 2026-06-27: the prior rule residency-gated commons ACCESS; the operator's constitutional
 * correction is that the commons is open and only POWERS are residency-gated. `isAssociatedWith` is
 * the residency predicate that the gated POWERS — e.g. the testimony seal — reuse.)
 */
class MatrixPostingGateService
{
    public function __construct(
        private RoleService $roles,
        private MatrixClientService $client,
    ) {}

    /** Post a player's message into a public-square / halls room (open commons, pseudonymous). */
    public function post(User $actor, string $jurisdictionId, string $roomId, string $body): array
    {
        $this->assertMayAccessCommons($actor, $jurisdictionId, $roomId);

        $content = ['msgtype' => 'm.text', 'body' => $body];

        $seat = $this->actingSeatFor($actor);
        if ($seat !== null) {
            $content['cga.acting_seat'] = $seat;   // derived LIVE, stripped if the role isn't held now
        }

        return $this->client->sendMessage($roomId, $content, $this->matrixUserId($actor));
    }

    /**
     * The public commons is OPEN (Art. I) — any authenticated player may participate, resident or
     * visitor. Access is NOT residency-gated; residency gates governance POWERS, which the game
     * enforces elsewhere. Two floors remain: the target must be THIS jurisdiction's public square /
     * halls (not some other room the caller named — fail-closed room scope), and the player must not be
     * under a social-feature abuse limitation (Layer 3).
     */
    public function assertMayAccessCommons(User $actor, string $jurisdictionId, string $roomId): void
    {
        // ROOM SCOPE (fail-closed) — the OPEN commons is a jurisdiction's public SQUARE / HALLS only.
        // Org / institution / per-object / private rooms have their OWN controls and are NOT reachable
        // here. Without this, an opened gate + the appservice's room-creator power (membership was
        // residency-gated by the appservice alone — MatrixRoomCreationService) would let any player
        // target any room by id. Mirrors the K3-K TranslationGate: unknown / tombstoned → deny.
        $this->assertPublicCommonsRoom($roomId, $jurisdictionId);

        // Layer 3 — a content-neutral, social-scoped abuse LIMITATION is the only OTHER thing that may
        // bar a player from the commons (residency is NOT a gate here — Art. I). Cross-node revocation
        // propagation (Flag 2) will materialize a foreign limitation into a local store; the enforcement
        // hook lands HERE, scoped by $jurisdictionId. A limitation suspends social FEATURES but NEVER a
        // player's vote / candidacy / role tools. (No local limitation store exists yet — explicit seam.)
    }

    /**
     * The OPEN commons is a jurisdiction's public SQUARE / HALLS, and ONLY that — verified against the
     * MatrixRoom row, fail-closed (mirrors the K3-K TranslationGate's unknown/tombstoned → deny posture).
     * An attacker-supplied room id can therefore never reach a non-commons room (organization,
     * institution, per-object governance) nor a jurisdiction other than the one named in the request.
     */
    public function assertPublicCommonsRoom(string $matrixRoomId, string $jurisdictionId): void
    {
        $room = MatrixRoom::query()
            ->where('matrix_room_id', $matrixRoomId)
            ->whereNull('tombstoned_at')
            ->first();

        $isOpenCommons = $room !== null
            && (bool) $room->is_public
            && $room->room_type === MatrixRoom::ROOM_COMMONS
            && in_array($room->space_type, [MatrixRoom::SPACE_PUBLIC_SQUARE, MatrixRoom::SPACE_HALLS], true)
            && $room->entity_type === MatrixRoom::ENTITY_JURISDICTION
            && (string) $room->entity_id === $jurisdictionId;

        if (! $isOpenCommons) {
            throw new ConstitutionalViolation(
                'The open commons is a jurisdiction\'s public square or halls. Organization, institution, '
                .'per-object, and private rooms have their own controls and are not reachable here (Art. I).',
                'Art. I'
            );
        }
    }

    /**
     * GOVERNANCE-participation predicate: does the actor hold an active residency association with this
     * jurisdiction (the ancestor-sweep already gives a parent its descendants' residents)? This gates the
     * POWERS scoped to a jurisdiction — e.g. sealing testimony into its record — and is deliberately
     * SEPARATE from commons access, which is open (Art. I). NEVER karma, account age, or any reputation.
     */
    public function isAssociatedWith(User $actor, string $jurisdictionId): bool
    {
        // residency_confirmations tracks active/inactive via is_active (no soft-deletes column).
        return DB::table('residency_confirmations')
            ->where('user_id', (string) $actor->getKey())
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->exists();
    }

    /** The cga.acting_seat for officeholder speech, derived from LIVE roles at send time (null if none). */
    public function actingSeatFor(User $actor): ?string
    {
        $roles = $this->roles->rolesFor($actor);

        return match (true) {
            in_array('R-19', $roles, true) || in_array('R-20', $roles, true) => 'judicial',
            in_array('R-10', $roles, true) => 'speaker',
            in_array('R-09', $roles, true) => 'legislature_member',
            default => null,
        };
    }

    /** The pseudonymous @u-<handle>:domain — NEVER the legal name (Art. I). */
    public function matrixUserId(User $actor): string
    {
        return '@'.$this->localpartFor($actor).':'.config('matrix.server_name');
    }

    /**
     * The pseudonymous LOCALPART (`u-<handle>`) — the single source of truth for a user's Matrix identity,
     * reused by the OIDC provider (K3-C) so the id_token's preferred_username, the provisioned
     * matrix_identities row, and a sent message all carry the IDENTICAL pseudonym. NEVER the legal name
     * (Art. I).
     *
     * The PROVISIONED identity is authoritative once it exists: a (vanishingly rare) localpart collision can
     * be discriminated at provision time (MatrixIdentityProvisioner), so message-sending must read the SAME
     * stored value rather than re-deriving and disagreeing — otherwise a user's identity would split.
     */
    public function localpartFor(User $actor): string
    {
        $stored = MatrixIdentity::query()
            ->where('user_id', (string) $actor->getKey())
            ->whereNull('deleted_at')
            ->value('matrix_localpart');

        return ! empty($stored) ? (string) $stored : $this->deriveLocalpart($actor);
    }

    /**
     * Derive a fresh pseudonymous localpart: the profile handle if set, else a 128-bit non-PII id from the
     * user-id hash. (Widened from 32 bits per the K3-C security review — at 32 bits a birthday collision
     * near ~65k handle-less users on one instance was mis-recovered into an opaque 500 + permanent identity
     * denial. 128 bits makes a collision astronomically improbable; the provisioner still discriminates the
     * residual case rather than failing.)
     */
    public function deriveLocalpart(User $actor): string
    {
        $profile = SocialProfile::query()->where('user_id', (string) $actor->getKey())->first();
        $base = ! empty($profile?->handle)
            ? (string) $profile->handle
            : substr(hash('sha256', (string) $actor->getKey()), 0, 32);

        return 'u-'.preg_replace('/[^a-z0-9._=\-]/', '', strtolower($base));
    }
}

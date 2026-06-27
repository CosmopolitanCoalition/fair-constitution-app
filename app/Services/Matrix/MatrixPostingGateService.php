<?php

namespace App\Services\Matrix;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\MatrixIdentity;
use App\Models\SocialProfile;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;

/**
 * Phase K-3 (K3-G) — posting rights in a jurisdiction's public square / halls. RESIDENCY is the ONLY
 * gate (Art. I): an active residency association with the jurisdiction (the ancestor-sweep already
 * gives a parent its descendants' residents). NEVER karma, account age, or any Matrix reputation.
 * The post is sent AS the resident's pseudonymous @u-<handle> (never their legal name). Officeholder
 * speech carries a cga.acting_seat annotation derived LIVE from current roles at SEND TIME — so a
 * member who vacates mid-session loses it (re-derived, never cached); a seated member's #halls posts
 * are M-4-exempt (Art. II §3, the unobstructable priority channel — consumed by the M-4 path).
 */
class MatrixPostingGateService
{
    public function __construct(
        private RoleService $roles,
        private MatrixClientService $client,
    ) {}

    /** Post a resident's message into a public-square / halls room (residency-gated, pseudonymous). */
    public function post(User $actor, string $jurisdictionId, string $roomId, string $body): array
    {
        $this->assertMayPost($actor, $jurisdictionId);

        $content = ['msgtype' => 'm.text', 'body' => $body];

        $seat = $this->actingSeatFor($actor);
        if ($seat !== null) {
            $content['cga.acting_seat'] = $seat;   // derived LIVE, stripped if the role isn't held now
        }

        return $this->client->sendMessage($roomId, $content, $this->matrixUserId($actor));
    }

    /** RESIDENCY (an active association with this jurisdiction) is the ONLY gate — Art. I. */
    public function assertMayPost(User $actor, string $jurisdictionId): void
    {
        // residency_confirmations tracks active/inactive via is_active (no soft-deletes column).
        $associated = DB::table('residency_confirmations')
            ->where('user_id', (string) $actor->getKey())
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->exists();

        if (! $associated) {
            throw new ConstitutionalViolation(
                "Posting in a jurisdiction's public square requires an active residency association (R-03) with it — the only gate (Art. I).",
                'Art. I'
            );
        }
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

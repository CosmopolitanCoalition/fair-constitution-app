<?php

namespace App\Services\Matrix;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\FederationPeer;
use App\Models\MatrixServerAcl;

/**
 * Phase K-3 (K3-E) — mesh-gated S2S federation for Matrix rooms. Two layers (design §6.4):
 *  (1) homeserver-config federation_domain_whitelist — the SAME peers that mirror your public records
 *      may federate your Matrix rooms; a scale_demo instance forces it empty (CI-2, no consent).
 *      Applied at deploy/rig time (Synapse config), so here it is COMPUTED for the runbook.
 *  (2) per-room m.room.server_acl — written ONLY by the appservice, ONLY for M-1 (judicial) or M-4
 *      (behavior-based abusive server), NEVER viewpoint. HARD RAIL: the allow list MUST always retain
 *      the local server + every mesh peer — `allow:[]` self-bricks the room (an ACLed-out server can't
 *      even send its own leave event; Synapse #5468). The mesh APPLICATION of an ACL is rig-gated;
 *      the local write + the brick-guard are dev-testable here.
 */
class MatrixFederationGateService
{
    public function __construct(private MatrixClientService $client) {}

    /**
     * The federation_domain_whitelist the homeserver should run: the local server PLUS every TRUSTED mesh
     * peer's Matrix server_name — exactly the same peers that mirror our public records may federate our
     * Matrix rooms (design §6.4). A scale_demo instance forces empty (a demo has no consent to federate).
     *
     * A peer's Matrix domain is its OWN signed handshake claim (or the host of its federation url) — it is
     * not an authority grant: the whitelist only says "we will ACCEPT Matrix federation from these domains,"
     * and Matrix S2S independently authenticates every server against that domain's published keys, so a
     * peer cannot impersonate a domain it merely names. Trust to even be in this set is the pinned-peer TOFU.
     *
     * @return list<string>
     */
    public function desiredFederationWhitelist(bool $scaleDemo = false): array
    {
        if ($scaleDemo) {
            return [];
        }

        $local = (string) config('matrix.server_name');

        $peerDomains = FederationPeer::query()
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn (FederationPeer $p) => $p->isTrusted())
            ->map(fn (FederationPeer $p) => $p->matrixServerName())
            ->filter()
            ->all();

        return array_values(array_unique(array_merge([$local], $peerDomains)));
    }

    /**
     * Write m.room.server_acl on a PUBLIC room — only M-1 / M-4, never viewpoint, never self-bricking.
     */
    public function setRoomServerACL(string $roomId, array $deny, string $carveOut): MatrixServerAcl
    {
        if (! in_array($carveOut, [MatrixServerAcl::CARVE_M1_JUDICIAL, MatrixServerAcl::CARVE_M4_ANTISPAM], true)) {
            throw new ConstitutionalViolation(
                'A server ACL on a public room is only M-1 (logged judicial order) or M-4 (behavior-based abusive server) — never viewpoint.',
                'Art. I'
            );
        }

        $allow = $this->desiredFederationWhitelist();
        $local = (string) config('matrix.server_name');

        // The self-brick / self-ban footgun (Synapse #5468; matrix-spec #397).
        if (empty($allow) || ! in_array($local, $allow, true)) {
            throw new ConstitutionalViolation(
                'A server ACL must always retain the local server + every mesh peer (allow:[] self-bricks the room).',
                'Art. I'
            );
        }

        $this->client->sendStateEvent($roomId, 'm.room.server_acl', '', [
            'allow'             => $allow,
            'deny'              => $deny,
            'allow_ip_literals' => false,
        ]);

        return MatrixServerAcl::query()->create([
            'matrix_room_id'       => $roomId,
            'allow'                => $allow,
            'deny'                 => $deny,
            'written_by_carve_out' => $carveOut,
        ]);
    }
}

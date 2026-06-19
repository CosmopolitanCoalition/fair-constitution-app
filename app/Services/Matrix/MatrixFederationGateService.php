<?php

namespace App\Services\Matrix;

use App\Domain\Engine\ConstitutionalViolation;
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
     * The federation_domain_whitelist the homeserver should run. Always retains the local server.
     * Peer Matrix domains join here when the mesh is live (rig — same peers as federation_peers);
     * a scale_demo instance forces empty (a demo has no consent to federate).
     */
    public function desiredFederationWhitelist(bool $scaleDemo = false): array
    {
        if ($scaleDemo) {
            return [];
        }

        // Local always present. Peer-domain inclusion is wired with the two-box mesh (K3-N, rig).
        return [(string) config('matrix.server_name')];
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

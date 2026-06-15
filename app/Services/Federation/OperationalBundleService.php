<?php

namespace App\Services\Federation;

use App\Domain\Ballots\BallotCrypto;
use App\Models\Election;
use App\Models\FederationPeer;
use App\Models\OperationalPartitionExport;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The operational seed bundle (Phase G, G5) — the SEALED, point-to-point transfer
 * of the PRIVATE rows that ride an autonomy flip and must NEVER travel in the
 * routine public-records sync tail.
 *
 * Its payload is the set of per-election data keys k_e for the flipping subtree.
 * k_e is wrapped on each instance under that instance's own app-key-derived KEK,
 * so it cannot simply be copied across — the relinquishing instance unwraps it,
 * the bundle carries the RAW k_e SEALED to the gaining cluster's key (libsodium
 * sealed box: only the gaining cluster can open it), and on arrival the gaining
 * cluster re-wraps each k_e under ITS OWN KEK via BallotKeyRewrapService::adopt —
 * which proves, fail-closed, that the certified counts still reproduce before the
 * key commits.
 *
 * The privacy boundary holds: the keys exist in the clear only transiently in
 * memory and inside the sealed box. The ledger + audit carry counts + fingerprints
 * only — never k_e, never the sealed blob, never a ballot. This is the autonomy-flip
 * EXCEPTION to "ballots/keys never federate": a one-time sealed handover to the new
 * authoritative owner, not routine replication.
 */
class OperationalBundleService
{
    private const SCHEMA = 'cga.operational-bundle.v1';

    public function __construct(
        private readonly InstanceIdentityService $identity,
        private readonly PartitionExportService $partition,
        private readonly BallotKeyRewrapService $rewrap,
        private readonly AuditService $audit,
    ) {}

    /**
     * OUTBOUND (relinquishing side): collect each subtree election's raw k_e and
     * SEAL them to the gaining peer's key. Returns the opaque sealed blob and the
     * ledger row (which holds no key material).
     *
     * @return array{sealed: string, export: OperationalPartitionExport}
     */
    public function buildSealedFor(string $rootJurisdictionId, FederationPeer $gainingPeer): array
    {
        if ($gainingPeer->public_key === null) {
            throw new RuntimeException('Cannot seal an operational bundle — the gaining peer has no pinned public key.');
        }

        $localKek = BallotCrypto::kekFromAppKey((string) config('app.key'));

        // The subtree includes the root itself (its own elections flip too).
        $jurisdictionIds = array_values(array_unique(array_merge(
            [$rootJurisdictionId],
            $this->partition->descendants($rootJurisdictionId),
        )));

        $elections = Election::query()
            ->whereIn('jurisdiction_id', $jurisdictionIds)
            ->whereNotNull('ballot_key_wrapped')
            ->get(['id', 'ballot_key_wrapped']);

        $electionKeys = [];
        foreach ($elections as $election) {
            $rawKe = BallotCrypto::unwrapDataKey((string) $election->ballot_key_wrapped, $localKek);
            $electionKeys[] = ['election_id' => (string) $election->id, 'k_e' => base64_encode($rawKe)];
            sodium_memzero($rawKe);
        }

        $payload = (string) json_encode([
            'schema'               => self::SCHEMA,
            'root_jurisdiction_id' => $rootJurisdictionId,
            'from_server_id'       => $this->identity->serverId(),
            'to_server_id'         => (string) $gainingPeer->server_id,
            'election_keys'        => $electionKeys,
        ], JSON_UNESCAPED_SLASHES);

        $sealed = InstanceIdentityService::sealTo((string) $gainingPeer->public_key, $payload);

        $export = OperationalPartitionExport::create([
            'root_jurisdiction_id' => $rootJurisdictionId,
            'direction'            => OperationalPartitionExport::DIRECTION_OUTBOUND,
            'peer_server_id'       => (string) $gainingPeer->server_id,
            'election_count'       => count($electionKeys),
            'applied_count'        => 0,
            'sealed_fingerprint'   => hash('sha256', $sealed),
            'status'               => OperationalPartitionExport::STATUS_SEALED,
        ]);

        $this->audit->append(
            module: 'federation_operational',
            event: 'operational_bundle.sealed',
            payload: [
                'root_jurisdiction_id' => $rootJurisdictionId,
                'to_server_id'         => (string) $gainingPeer->server_id,
                'election_count'       => count($electionKeys),
                'sealed_fingerprint'   => $export->sealed_fingerprint,
            ],
            ref: 'WF-JUR-06',
            actorId: null,
            jurisdictionId: $rootJurisdictionId,
        );

        return ['sealed' => $sealed, 'export' => $export];
    }

    /**
     * INBOUND (gaining side): open the sealed bundle and re-wrap every election's
     * key under OUR KEK via BallotKeyRewrapService::adopt. ATOMIC + fail-closed: if
     * ANY election's re-wrap cannot reproduce its certified count, the whole apply
     * rolls back (no election left half-flipped) and a failed ledger row is recorded.
     */
    public function openAndApply(string $sealedB64, ?string $fromClusterId = null, ?string $toClusterId = null): OperationalPartitionExport
    {
        $payload = json_decode($this->identity->openSealed($sealedB64), true);

        if (! is_array($payload) || ($payload['schema'] ?? null) !== self::SCHEMA) {
            throw new RuntimeException('Sealed bundle is malformed or not a CGA operational bundle.');
        }

        $rootId = ($payload['root_jurisdiction_id'] ?? null) ?: null;
        $fromServerId = (string) ($payload['from_server_id'] ?? '');
        $electionKeys = is_array($payload['election_keys'] ?? null) ? $payload['election_keys'] : [];

        try {
            return DB::transaction(function () use ($electionKeys, $rootId, $fromServerId, $fromClusterId, $toClusterId): OperationalPartitionExport {
                $applied = 0;

                foreach ($electionKeys as $entry) {
                    $electionId = (string) ($entry['election_id'] ?? '');
                    $rawKe = base64_decode((string) ($entry['k_e'] ?? ''), true);
                    $election = $electionId !== '' ? Election::query()->find($electionId) : null;

                    if ($election === null || $rawKe === false) {
                        // A malformed/unknown entry fails the whole atomic apply.
                        throw new BallotRewrapFailed($electionId, null, 'bundle entry references an unknown election or a malformed key');
                    }

                    $this->rewrap->adopt($election, $rawKe, $toClusterId, $fromClusterId);
                    $applied++;
                }

                $export = OperationalPartitionExport::create([
                    'root_jurisdiction_id' => $rootId,
                    'direction'            => OperationalPartitionExport::DIRECTION_INBOUND,
                    'peer_server_id'       => $fromServerId !== '' ? $fromServerId : null,
                    'election_count'       => count($electionKeys),
                    'applied_count'        => $applied,
                    'sealed_fingerprint'   => null,
                    'status'               => OperationalPartitionExport::STATUS_APPLIED,
                ]);

                $this->audit->append(
                    module: 'federation_operational',
                    event: 'operational_bundle.applied',
                    payload: [
                        'root_jurisdiction_id' => $rootId,
                        'from_server_id'       => $fromServerId,
                        'election_count'       => count($electionKeys),
                        'applied_count'        => $applied,
                    ],
                    ref: 'WF-JUR-06',
                    actorId: null,
                    jurisdictionId: $rootId,
                );

                return $export;
            });
        } catch (BallotRewrapFailed $e) {
            // The atomic apply rolled back — NO election was re-wrapped. Record the
            // abort outside the reverted transaction and re-throw (fail closed).
            OperationalPartitionExport::create([
                'root_jurisdiction_id' => $rootId,
                'direction'            => OperationalPartitionExport::DIRECTION_INBOUND,
                'peer_server_id'       => $fromServerId !== '' ? $fromServerId : null,
                'election_count'       => count($electionKeys),
                'applied_count'        => 0,
                'sealed_fingerprint'   => null,
                'status'               => OperationalPartitionExport::STATUS_FAILED,
            ]);

            throw $e;
        }
    }
}

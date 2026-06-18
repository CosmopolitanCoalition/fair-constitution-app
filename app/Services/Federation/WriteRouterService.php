<?php

namespace App\Services\Federation;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Engine\Contracts\ResolvesForwardedActor;
use App\Models\FederationPeer;
use App\Models\ForwardedWrite;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Identity\AttestedActorContext;
use Illuminate\Support\Str;

/**
 * Write-routing (Phase G, G4). The mesh serves READS locally everywhere; a
 * WRITE for a jurisdiction this instance does not own is forwarded to that
 * jurisdiction's authoritative leader and executed there through the NORMAL
 * ConstitutionalEngine — same authorize → validate → handle → audit pipeline,
 * NO bypass. There is exactly one validation path whether a filing arrives by
 * local HTTP, by queue, by clock, or forwarded from a peer.
 *
 * Authority (where a write executes) is read from `authoritative_server_id` via
 * AuthorityResolver and is ORTHOGONAL to leadership (which node inside an
 * authoritative cluster currently writes — a Patroni axis this class never
 * touches). Citizen forwarding waits for G-ID (SystemOnlyForwardedActor refuses
 * an actor claim until then); system-scoped forwarding works now.
 */
class WriteRouterService
{
    public function __construct(
        private readonly AuthorityResolver $authority,
        private readonly MultiplexClient $multiplex,
        private readonly ConstitutionalEngine $engine,
        private readonly ResolvesForwardedActor $forwardedActor,
        private readonly InstanceIdentityService $identity,
        private readonly AttestedActorContext $attestedActor,
    ) {}

    /** The peer server_id a write for this payload routes to, or null = local. */
    public function routeFor(array $payload): ?string
    {
        return $this->authority->authoritativeServerIdFor($this->jurisdictionIdOf($payload));
    }

    /** Does this instance execute a write for this payload locally? */
    public function isLocalAuthority(array $payload): bool
    {
        return $this->authority->isLocalAuthority($this->jurisdictionIdOf($payload));
    }

    /**
     * THE write-routing decision: execute here when we are authoritative, else
     * forward to the authoritative leader. The single seam a citizen-write
     * controller adopts (system-scoped until G-ID lands).
     *
     * @return array<string,mixed>
     */
    public function dispatch(string $formId, ?User $actor, array $payload, ?array $actorEnvelope = null): array
    {
        if ($this->isLocalAuthority($payload)) {
            $result = $this->engine->file($formId, $actor, $payload);

            return [
                'status' => 'executed',
                'local' => true,
                'audit_seq' => $result->entry->seq,
                'result_hash' => $result->entry->hash,
            ];
        }

        return ['status' => 'forwarded', 'local' => false] + $this->forward($formId, $payload, $actorEnvelope);
    }

    /**
     * Forward a write to the authoritative leader. The idempotency key defaults
     * to a content hash, so a lost-response retry of the identical write
     * re-reads the leader's recorded outcome instead of double-filing; a future
     * citizen path supplies an explicit per-action nonce.
     *
     * @return array<string,mixed>
     */
    public function forward(string $formId, array $payload, ?array $actorEnvelope = null, ?string $idempotencyKey = null): array
    {
        $serverId = $this->routeFor($payload);

        if ($serverId === null) {
            throw new ForwardedWriteRefused('forward() called for a jurisdiction we are authoritative for', 421);
        }

        $peer = FederationPeer::query()->where('server_id', $serverId)->first();

        if ($peer === null) {
            throw new ForwardedWriteRefused('no_route_to_authoritative_leader', 421);
        }

        // The SAME signed write travels the multiplex ladder until one transport
        // delivers (G8b); a peer unreachable over EVERY channel is a no-route refusal,
        // exactly as an unreachable single url was before.
        try {
            $response = $this->multiplex->reach($serverId, 'POST', '/api/federation/write', [
                'form_id' => $formId,
                'payload' => $payload,
                'actor' => $actorEnvelope,
                'origin_server_id' => $this->identity->serverId(),
                'idempotency_key' => $idempotencyKey ?? $this->contentKey($formId, $payload),
            ]);
        } catch (NoSurvivingTransport) {
            throw new ForwardedWriteRefused('no_route_to_authoritative_leader', 421);
        }

        return ['status_code' => $response->status(), 'leader' => $serverId, 'body' => $response->json()];
    }

    /**
     * Execute a forwarded write that arrived at us — we are the authoritative
     * leader. Idempotent on (origin_server_id, idempotency_key): a settled prior
     * forward returns its recorded outcome; a concurrent duplicate trips the
     * unique index (→ QueryException the controller maps to 409).
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     *
     * @throws ForwardedWriteRefused on a misdirected/unverifiable/malformed forward
     */
    public function executeForwarded(array $envelope, FederationPeer $from): array
    {
        $origin = (string) ($envelope['origin_server_id'] ?? $from->server_id);
        $key = (string) ($envelope['idempotency_key'] ?? '');
        $formId = (string) ($envelope['form_id'] ?? '');
        $payload = (array) ($envelope['payload'] ?? []);

        if ($key === '' || $formId === '') {
            throw new ForwardedWriteRefused('missing form_id or idempotency_key', 422);
        }

        // Defense in depth — we must actually hold authority for this write. A
        // peer cannot make us execute a jurisdiction we don't own (or one a
        // third party owns); the engine would also refuse, but fail fast here.
        $jid = $this->jurisdictionIdOf($payload);
        if (! $this->authority->isLocalAuthority($jid)) {
            throw new ForwardedWriteRefused('misdirected: not authoritative for that jurisdiction', 421);
        }

        // Idempotent replay — a settled prior forward returns its outcome verbatim.
        $existing = ForwardedWrite::query()
            ->where('origin_server_id', $origin)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null && $existing->isSettled()) {
            return $existing->outcome();
        }

        // Resolve the actor — throws ForwardedWriteRefused to refuse an
        // unverifiable claim; null = system filing; a VERIFIED G-ID attestation
        // (AttestedForwardedActor) also places the attested roles in the request
        // context, which the engine authorizes against and we clear below.
        $actor = $this->forwardedActor->resolve($envelope);

        try {
            // Claim the key (the unique index blocks a concurrent duplicate). A prior
            // PENDING row (a crash mid-execution) is re-driven rather than re-claimed.
            $row = $existing ?? ForwardedWrite::create([
                'origin_server_id' => $origin,
                'idempotency_key' => $key,
                'form_id' => $formId,
                'jurisdiction_id' => $jid,
                'status' => ForwardedWrite::STATUS_PENDING,
            ]);

            try {
                $result = $this->engine->file($formId, $actor, $payload); // NORMAL path — no bypass
                $row->update([
                    'status' => ForwardedWrite::STATUS_EXECUTED,
                    'audit_seq' => $result->entry->seq,
                    'result_hash' => $result->entry->hash,
                ]);
            } catch (ConstitutionalViolation $violation) {
                // A valid denial — the engine already chained the rejected edge on our
                // own log. Record it idempotently so a replay returns the same refusal.
                $row->update([
                    'status' => ForwardedWrite::STATUS_REJECTED,
                    'citation' => $violation->citation,
                ]);
            }

            return $row->refresh()->outcome();
        } finally {
            // The attested context authorizes EXACTLY this one forwarded filing —
            // never the next request, never a local user.
            $this->attestedActor->clear();
        }
    }

    private function jurisdictionIdOf(array $payload): ?string
    {
        $id = $payload['jurisdiction_id'] ?? null;

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    private function contentKey(string $formId, array $payload): string
    {
        return hash('sha256', $this->identity->serverId().'|'.$formId.'|'.AuditService::canonicalJson($payload));
    }
}

<?php

namespace App\Domain\Engine;

use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\FormRegistry;
use App\Models\InstanceSettings;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * THE single entry point for state-changing civic actions (WF-SYS-04).
 *
 * file(formId, actor, payload) pipeline:
 *   1. canonicalize  — FormRegistry resolves aliases; unknown IDs throw
 *   2. authorize     — handler-declared role requirement vs ResolvesRoles
 *                      (Phase A: StubRoleResolver; real derivation in WI-5)
 *   3. validate      — ConstitutionalValidator::check() (hardened rules)
 *   4. execute       — handler->handle() inside DB::transaction
 *   5. audit         — AuditService::append() in the SAME transaction:
 *                      no mutation without its chain entry, no entry
 *                      without its mutation.
 *
 * Denials: any ConstitutionalViolation (from authorization, validation,
 * or the handler) rolls back the mutation, appends a rejected=true chain
 * entry carrying the citation (rejections are first-class records), and
 * rethrows for the HTTP layer (422). Credential material is stripped from
 * rejection payloads before recording.
 *
 * Controllers, queued jobs, and clock handlers all call file() — one
 * validation path for HTTP, queue, and scheduler.
 */
class ConstitutionalEngine
{
    /** Keys never recorded to the chain, even on rejections. */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'password_hash',
        'current_password',
        'remember_token',
        'token',
        'secret',
        // Ballot content (Art. II §2 secrecy): a REJECTED F-IND-007 filing
        // (double-vote, window-closed) must never seal the voter's rankings
        // into the chain — accepted ballots are recorded by BallotBox as
        // participation only, and rejections get the same treatment here.
        'rankings',
        // Referendum ballot content (Art. II §6, Phase C F-IND-008): the
        // voter's yes/no must never reach the chain — same treatment.
        'choice',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ConstitutionalValidator $validator,
        private readonly ResolvesRoles $roles,
    ) {}

    /**
     * File a constitutional form.
     *
     * @param  string  $formId  canonical or aliased form ID
     * @param  User|null  $actor  null = system filing (jobs, clocks)
     * @param  array  $payload  form input; `jurisdiction_id` (when
     *                          present and a UUID) scopes the audit row
     *
     * @throws \InvalidArgumentException for unknown form IDs (not a constitutional matter)
     * @throws ConstitutionalViolation on any constitutional denial (after recording it)
     * @throws RuntimeException when the form has no Phase A handler yet
     */
    public function file(string $formId, ?User $actor, array $payload): EngineResult
    {
        $canonical = FormRegistry::canonical($formId);
        $handler = $this->resolveHandler($canonical);

        // audit_log.actor_user_id is uuid. Until the WI-3 users rebuild
        // lands, user PKs are still bigint and cannot be recorded there —
        // defensively record only UUID keys (from WI-3 on, always true).
        $actorKey = $actor?->getKey();
        $actorId = $actorKey !== null && Str::isUuid((string) $actorKey) ? (string) $actorKey : null;
        $jurisdictionId = $this->jurisdictionIdFrom($payload);

        try {
            // Mirror write-guard (Phase G, G2). A read-only mirror is
            // authoritative for NOTHING: it refuses EVERY constitutional filing —
            // HTTP, queue, or clock — and records the refusal as a rejected edge
            // on its OWN local chain. (Mirrored host records live in
            // public_records with audit_seq=null, NEVER in audit_log, so this
            // edge cannot fork the replicated chain.) The write surface is
            // indistinguishable from absent.
            if (InstanceSettings::current()->isMirror()) {
                throw new ConstitutionalViolation(
                    'This instance is a read-only mirror; it is authoritative for nothing and accepts no constitutional filings.',
                    'A Fair Constitution — a mirror replicates public records, it does not legislate'
                );
            }

            $this->authorize($handler, $canonical, $actor);

            $this->validator->check($canonical, $payload);

            return DB::transaction(function () use ($canonical, $handler, $actor, $payload, $actorId, $jurisdictionId): EngineResult {
                $recorded = $handler->handle($actor, $payload);

                // Self-creating filings (F-IND-001): the actor does not exist
                // until the handler creates them. Adopt the created individual
                // as the actor so their own record slice (WI-8 My Record)
                // includes its genesis "account created" entry. Rejected
                // filings never reach here, so they keep a null actor.
                if ($actorId === null
                    && isset($recorded['user_id'])
                    && Str::isUuid((string) $recorded['user_id'])) {
                    $actorId = (string) $recorded['user_id'];
                }

                $entry = $this->audit->append(
                    module: $handler->module(),
                    event: $handler->event(),
                    payload: $recorded,
                    ref: $canonical,
                    actorId: $actorId,
                    jurisdictionId: $jurisdictionId,
                );

                return new EngineResult($canonical, $entry, $recorded);
            });
        } catch (ConstitutionalViolation $violation) {
            // The mutation (if any) rolled back with the transaction; the
            // rejection itself is recorded in a fresh transaction.
            $this->audit->append(
                module: $handler->module(),
                event: $handler->event().'.rejected',
                payload: [
                    'form_id' => $canonical,
                    'citation' => $violation->citation,
                    'payload' => $this->sanitize($payload),
                ],
                ref: $canonical,
                actorId: $actorId,
                jurisdictionId: $jurisdictionId,
                rejected: true,
                blockedReason: $violation->getMessage().' ('.$violation->citation.')',
            );

            throw $violation;
        }
    }

    // -------------------------------------------------------------------------

    private function resolveHandler(string $canonical): FormHandler
    {
        $class = FormRegistry::handlerFor($canonical);

        if ($class === null) {
            throw new RuntimeException(
                "Form [{$canonical}] has no handler yet — Phase A implements ".count(FormRegistry::HANDLERS).' forms.'
            );
        }

        return app($class);
    }

    /**
     * Handler-declared role requirement vs derived roles.
     *
     * - systemOnly handlers accept only a null actor.
     * - An empty requiredRoles() list means no role gate (guest-filable).
     * - A null actor (system filing) bypasses role gates — jobs and clock
     *   handlers file on behalf of the system.
     */
    private function authorize(FormHandler $handler, string $canonical, ?User $actor): void
    {
        if ($handler->systemOnly()) {
            if ($actor !== null) {
                throw new ConstitutionalViolation(
                    "Form {$canonical} is system-filed only.",
                    'CGA Forms Catalog'
                );
            }

            return;
        }

        $required = $handler->requiredRoles();

        if ($required === [] || $actor === null) {
            return;
        }

        $held = $this->roles->rolesFor($actor);

        if (array_intersect($required, $held) === []) {
            throw new ConstitutionalViolation(
                sprintf(
                    'Filing %s requires role %s; actor holds [%s].',
                    $canonical,
                    implode(' or ', $required),
                    implode(', ', $held)
                ),
                'CGA Roles & Forms Chart'
            );
        }
    }

    private function jurisdictionIdFrom(array $payload): ?string
    {
        $id = $payload['jurisdiction_id'] ?? null;

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }

    private function sanitize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                unset($payload[$key]);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
            }
        }

        return $payload;
    }
}

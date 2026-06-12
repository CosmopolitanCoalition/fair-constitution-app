<?php

namespace App\Domain\Forms\Contracts;

use App\Models\User;

/**
 * Contract for constitutional form handlers
 * (app/Domain/Forms/Handlers/{StudlyFormId}.php, registered in
 * FormRegistry::HANDLERS).
 *
 * Handlers run INSIDE the engine's DB transaction; whatever they mutate
 * commits atomically with the audit entry. The array they return is the
 * payload recorded to the audit chain — handlers are responsible for
 * shaping it (never credentials, never ballot content, never raw
 * coordinates).
 */
interface FormHandler
{
    /** audit_log.module this form's entries belong to (e.g. 'identity'). */
    public function module(): string;

    /** audit_log.event recorded on successful filing (e.g. 'individual.registered'). */
    public function event(): string;

    /**
     * Role codes allowed to file this form (any one suffices), checked
     * against the bound ResolvesRoles implementation. An EMPTY array means
     * no role gate (guest-filable, e.g. F-IND-001 registration). A null
     * actor (system filing from jobs/clock handlers) bypasses role gates.
     *
     * @return list<string>
     */
    public function requiredRoles(): array;

    /**
     * True when only the system (null actor) may file this form
     * (e.g. F-IND-006 Residency Verification Confirmation).
     */
    public function systemOnly(): bool;

    /**
     * Perform the form's mutation and return the audit payload snapshot.
     *
     * @throws \App\Domain\Engine\ConstitutionalViolation on constitutional grounds
     */
    public function handle(?User $actor, array $payload): array;
}

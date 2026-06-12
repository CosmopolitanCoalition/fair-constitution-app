<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Contracts\ResidencyHandlerDelegate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F-IND-003 — Residency Declaration (R-01).
 *
 * PHASE A STUB: validates that the declared jurisdiction exists and that
 * the filer consented to location pings (declaration without consent is
 * rejected — verification cannot run without it), then delegates to the
 * bound ResidencyHandlerDelegate. The claim state machine
 * (residency_claims, CLK-05 arming) plugs in via that delegate in WI-5;
 * Phase A binds a no-op.
 *
 * rights.automatic: this is a RIGHTS_AUTOMATIC_FORMS member — the
 * ConstitutionalValidator rejects any filing carrying eligibility
 * conditions beyond jurisdictional association (Art. I), and this handler
 * consults nothing but the declared association.
 */
class ResidencyDeclaration implements FormHandler
{
    public function __construct(
        private readonly ResidencyHandlerDelegate $delegate,
    ) {
    }

    public function module(): string
    {
        return 'residency';
    }

    public function event(): string
    {
        return 'residency.declared';
    }

    public function requiredRoles(): array
    {
        return ['R-01'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $jurisdictionId = $payload['jurisdiction_id'] ?? null;

        if (! is_string($jurisdictionId) || ! Str::isUuid($jurisdictionId)) {
            throw new ConstitutionalViolation(
                'F-IND-003 requires a declared jurisdiction_id (UUID).',
                'Art. I'
            );
        }

        $exists = DB::table('jurisdictions')
            ->where('id', $jurisdictionId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            throw new ConstitutionalViolation(
                "Declared jurisdiction [{$jurisdictionId}] does not exist.",
                'Art. I'
            );
        }

        if (($payload['ping_consent'] ?? false) !== true) {
            throw new ConstitutionalViolation(
                'Residency declaration requires explicit consent to location pings — verification cannot run without it.',
                'Art. I'
            );
        }

        return [
            'jurisdiction_id' => $jurisdictionId,
            'ping_consent'    => true,
        ] + $this->delegate->declare($actor, $payload);
    }
}

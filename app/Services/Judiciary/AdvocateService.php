<?php

namespace App\Services\Judiciary;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Advocate;
use App\Models\Judiciary;
use App\Services\PublicRecordService;
use App\Services\RoleService;
use Illuminate\Support\Facades\DB;

/**
 * Advocate registration, suspension/withdrawal, and the bar lookup
 * (PHASE_E_DESIGN_cases_juries §C). Thin — the substantive work is the R-21
 * derivation. F-IND-015 rejects ONLY on association + duplicate, never on a
 * merits/identity test: registration is available to any R-03 (Art. I); the
 * client's right to "zealous and competent advocates" is satisfied by the bar
 * existing.
 */
class AdvocateService
{
    public function __construct(
        private readonly PublicRecordService $records,
        private readonly RoleService $roles,
    ) {}

    /**
     * F-IND-015 — register an advocate at a judiciary. Association with the
     * judiciary's jurisdiction is the ONLY eligibility check; a duplicate
     * registration is rejected.
     */
    public function register(string $userId, string $judiciaryId, ?string $qualificationsNote = null): Advocate
    {
        $judiciary = Judiciary::query()->findOrFail($judiciaryId);

        $this->assertAssociation($userId, (string) $judiciary->jurisdiction_id);

        $duplicate = Advocate::query()
            ->where('user_id', $userId)
            ->where('judiciary_id', $judiciaryId)
            ->whereNull('deleted_at')
            ->exists();

        if ($duplicate) {
            throw new ConstitutionalViolation(
                'This advocate is already registered with the court — one registration per advocate per judiciary.',
                'Art. IV §4 · as implemented'
            );
        }

        return DB::transaction(function () use ($userId, $judiciary, $judiciaryId, $qualificationsNote): Advocate {
            $advocate = Advocate::create([
                'user_id' => $userId,
                'judiciary_id' => $judiciaryId,
                'jurisdiction_id' => (string) $judiciary->jurisdiction_id,
                'status' => Advocate::STATUS_REGISTERED,
                'qualifications_note' => $qualificationsNote,
                'registered_at' => now(),
            ]);

            $this->records->publish(
                kind: 'registration',
                title: sprintf('Advocate registered — court %s', (string) $judiciary->id),
                body: $qualificationsNote,
                attrs: [
                    'actor_user_id' => $userId,
                    'jurisdiction_id' => (string) $judiciary->jurisdiction_id,
                    'via_form' => 'F-IND-015',
                    'subject_type' => 'advocates',
                    'subject_id' => (string) $advocate->id,
                ],
            );

            $this->roles->flushUser($userId);

            return $advocate;
        });
    }

    /** The advocate row for a user at a judiciary, or throw (F-ADV-* gate). */
    public function requireRegistered(string $userId, string $judiciaryId): Advocate
    {
        $advocate = Advocate::query()
            ->where('user_id', $userId)
            ->where('judiciary_id', $judiciaryId)
            ->where('status', Advocate::STATUS_REGISTERED)
            ->whereNull('deleted_at')
            ->first();

        if ($advocate === null) {
            throw new ConstitutionalViolation(
                'Filing on behalf of a client requires registration with this court — register first (F-IND-015).',
                'Art. IV §4'
            );
        }

        return $advocate;
    }

    private function assertAssociation(string $userId, string $jurisdictionId): void
    {
        $associated = DB::table('residency_confirmations')
            ->where('user_id', $userId)
            ->where('jurisdiction_id', $jurisdictionId)
            ->where('is_active', true)
            ->exists();

        if (! $associated) {
            throw new ConstitutionalViolation(
                'Advocate registration requires an active association with the court\'s jurisdiction — '
                .'association is the ONLY eligibility check (Art. I).',
                'Art. I'
            );
        }
    }
}

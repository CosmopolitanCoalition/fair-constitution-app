<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\JudicialActor;
use App\Models\CaseFiling;
use App\Models\User;
use App\Models\Warrant;
use App\Services\Judiciary\CaseFilingService;
use App\Services\PublicRecordService;
use Illuminate\Support\Facades\DB;

/**
 * F-JDG-010 — Warrant Issuance (Art. II §8 Arrest Warrant Requirement).
 *
 * Actor R-19/R-20. Issues an arrest/search/seizure `warrants` row. The two
 * constitutional facts are MANDATORY: a NOT-NULL non-empty `stated_reason`
 * ("establishing the reason for the arrest") and, for an arrest, a
 * `max_hold_duration_hours` > 0 ("the maximum duration an Individual can be
 * held"). A warrant missing either is structurally unfilable — the handler
 * re-asserts both with the citation, the DB CHECKs are the belt.
 */
class WarrantIssuance implements FormHandler
{
    public function __construct(
        private readonly CaseFilingService $filings,
        private readonly PublicRecordService $records,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'warrant.issued';
    }

    public function requiredRoles(): array
    {
        return ['R-19', 'R-20'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $case = JudicialActor::case($payload, 'F-JDG-010');
        $seat = JudicialActor::seat($actor, (string) $case->judiciary_id, 'F-JDG-010');

        $kind = (string) ($payload['kind'] ?? '');

        if (! in_array($kind, [Warrant::KIND_ARREST, Warrant::KIND_SEARCH, Warrant::KIND_SEIZURE], true)) {
            throw new ConstitutionalViolation('F-JDG-010 names the warrant kind (arrest/search/seizure).', 'Art. II §8');
        }

        // Art. II §8 — the reason is constitutionally mandatory for EVERY warrant.
        $statedReason = trim((string) ($payload['stated_reason'] ?? ''));

        if ($statedReason === '') {
            throw new ConstitutionalViolation(
                'A warrant must establish the reason — no warrant issues without a stated reason (Art. II §8).',
                'Art. II §8'
            );
        }

        // Art. II §8 — an ARREST warrant additionally requires the maximum
        // duration an Individual can be held.
        $maxHold = null;

        if ($kind === Warrant::KIND_ARREST) {
            $maxHold = isset($payload['max_hold_duration_hours']) ? (int) $payload['max_hold_duration_hours'] : 0;

            if ($maxHold <= 0) {
                throw new ConstitutionalViolation(
                    'An arrest warrant must establish the maximum duration an Individual can be held (Art. II §8).',
                    'Art. II §8'
                );
            }
        }

        return DB::transaction(function () use ($case, $seat, $kind, $statedReason, $maxHold, $payload, $actor) {
            $record = $this->records->publish(
                kind: 'certification',
                title: sprintf('%s warrant — %s (%s)', ucfirst($kind), $case->title, $case->docket_no),
                body: $statedReason,
                attrs: [
                    'actor_user_id' => (string) $actor->getKey(),
                    'jurisdiction_id' => (string) $case->jurisdiction_id,
                    'via_form' => 'F-JDG-010',
                    'subject_type' => 'cases',
                    'subject_id' => (string) $case->id,
                ],
            );

            $warrant = Warrant::create([
                'case_id' => (string) $case->id,
                'issued_by_seat_id' => (string) $seat->id,
                'kind' => $kind,
                'stated_reason' => $statedReason,
                'max_hold_duration_hours' => $maxHold,
                'subject_user_id' => isset($payload['subject_user_id']) ? (string) $payload['subject_user_id'] : null,
                'status' => Warrant::STATUS_ISSUED,
                'issued_at' => now(),
                'expires_at' => $payload['expires_at'] ?? null,
                'record_id' => (string) $record->id,
            ]);

            $this->filings->docket($case->refresh(), [
                'filing_form' => 'F-JDG-010',
                'filing_kind' => CaseFiling::KIND_WARRANT,
                'filed_by_user_id' => (string) $actor->getKey(),
                'filed_by_role' => 'R-19',
                'title' => sprintf('%s warrant issued', ucfirst($kind)),
                'body' => $statedReason,
                'enforce_attach_window' => false,
            ]);

            return [
                'case_id' => (string) $case->id,
                'warrant_id' => (string) $warrant->id,
                'kind' => $kind,
                'max_hold_duration_hours' => $maxHold,
                'record_id' => (string) $record->id,
            ];
        });
    }
}

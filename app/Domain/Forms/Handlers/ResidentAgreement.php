<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Models\User;
use App\Services\Redlines\RedlineService;
use App\Services\Redlines\ResidentAgreementService;
use Illuminate\Support\Facades\DB;

/**
 * F-IND-020 — Resident Agreement (R-01).
 *
 * The citizen door for the consent plane (Design Round 2 ③; operator: the #9
 * reversal "for sure needs to be built"). It carries person-to-person /
 * N-party agreements AND the clause-redline actions on any agreement the actor
 * is a party to — one door, action-dispatched, because a redline is just
 * negotiation of the same instrument. Bill redlines are NOT here: a bill is
 * amended by a chamber vote through F-LEG-007.
 *
 * Every action is PARTY-GATED: an outsider can neither sign nor redline an
 * agreement they are not part of. Parties are named (the consent plane — a
 * signature is a name), distinct from the pseudonymous money plane. A refusal
 * is an answer with a citation.
 */
class ResidentAgreement implements FormHandler
{
    public function __construct(
        private ResidentAgreementService $agreements,
        private RedlineService $redlines,
    ) {}

    public function module(): string
    {
        return 'agreements';
    }

    public function event(): string
    {
        return 'resident.agreement';
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
        if ($actor === null) {
            throw new ConstitutionalViolation('An agreement is entered by a person.', 'CGA Forms Catalog (F-IND-020)');
        }

        $me     = (string) $actor->getKey();
        $action = (string) ($payload['action'] ?? '');

        return match ($action) {
            'create_agreement' => $this->create($me, $payload),
            'sign_agreement'   => $this->sign($me, $payload),
            'propose_redline'  => $this->proposeRedline($me, $payload),
            'accept_redline'   => $this->resolveRedline($me, $payload, 'accept'),
            'reject_redline'   => $this->resolveRedline($me, $payload, 'reject'),
            'withdraw_redline' => $this->resolveRedline($me, $payload, 'withdraw'),
            default            => throw new ConstitutionalViolation(
                "Unknown F-IND-020 action [{$action}].",
                'CGA Forms Catalog (F-IND-020)'
            ),
        };
    }

    /** @return array<string, mixed> */
    private function create(string $me, array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $terms = trim((string) ($payload['terms'] ?? ''));

        if ($title === '' || $terms === '') {
            throw new ConstitutionalViolation('An agreement needs a title and its terms.', 'CGA Forms Catalog (F-IND-020)');
        }

        $signers = is_array($payload['signers'] ?? null) ? $payload['signers'] : [];

        return ['action' => 'create_agreement']
            + $this->wrap(fn () => $this->agreements->create($title, $terms, $me, $signers));
    }

    /** @return array<string, mixed> */
    private function sign(string $me, array $payload): array
    {
        $agreementId = (string) ($payload['agreement_id'] ?? '');

        return ['action' => 'sign_agreement']
            + $this->wrap(fn () => $this->agreements->sign($agreementId, $me));
    }

    /** @return array<string, mixed> */
    private function proposeRedline(string $me, array $payload): array
    {
        $subjectType = (string) ($payload['subject_type'] ?? '');
        $subjectId   = (string) ($payload['subject_id'] ?? '');

        $this->assertParty($subjectType, $subjectId, $me);

        return ['action' => 'propose_redline']
            + $this->wrap(fn () => $this->redlines->propose(
                $subjectType,
                $subjectId,
                ($payload['clause_id'] ?? null) !== null ? (string) $payload['clause_id'] : null,
                (string) ($payload['kind'] ?? ''),
                (string) ($payload['body'] ?? ''),
                ($payload['rationale'] ?? null) !== null ? (string) $payload['rationale'] : null,
                ($payload['rights_flag'] ?? null) !== null ? (string) $payload['rights_flag'] : null,
                $me,
            ));
    }

    /** @return array<string, mixed> */
    private function resolveRedline(string $me, array $payload, string $how): array
    {
        $redlineId = (string) ($payload['redline_id'] ?? '');
        $redline   = DB::table('redlines')->where('id', $redlineId)->first();

        if ($redline === null) {
            throw new ConstitutionalViolation('Unknown redline.', 'CGA Forms Catalog (F-IND-020)');
        }

        $this->assertParty((string) $redline->subject_type, (string) $redline->subject_id, $me);

        return ['action' => $how . '_redline', 'redline_id' => $redlineId] + $this->wrap(function () use ($how, $redlineId) {
            return match ($how) {
                'accept'   => $this->redlines->acceptForAgreement($redlineId),
                'reject'   => tap(['status' => 'rejected'], fn () => $this->redlines->reject($redlineId)),
                'withdraw' => tap(['status' => 'withdrawn'], fn () => $this->redlines->withdraw($redlineId)),
                default    => [],
            };
        });
    }

    /**
     * The party gate. Only a party to the instrument may sign or redline it.
     * org_contract: the org's agent (acting for the org) or the named
     * counterparty. resident: any named signer.
     */
    private function assertParty(string $subjectType, string $subjectId, string $me): void
    {
        $isParty = match ($subjectType) {
            RedlineService::SUBJECT_RESIDENT     => $this->agreements->isParty($subjectId, $me),
            RedlineService::SUBJECT_ORG_CONTRACT => $this->isPartyToOrgContract($subjectId, $me),
            default                              => false,
        };

        if (! $isParty) {
            throw new ConstitutionalViolation(
                'Only a party to this agreement may negotiate it.',
                'Art. I'
            );
        }
    }

    private function isPartyToOrgContract(string $contractId, string $me): bool
    {
        $c = DB::table('org_contracts')->where('id', $contractId)->first();
        if ($c === null) {
            return false;
        }

        if ($c->counterparty_type === 'users' && (string) $c->counterparty_id === $me) {
            return true;
        }

        $agentId = DB::table('organizations')->where('id', $c->organization_id)->value('agent_user_id');

        return $agentId !== null && (string) $agentId === $me;
    }

    /**
     * Service exceptions are refusals: a ConstitutionalViolation renders its
     * citation app-wide; the engine records the rejected filing first. A plain
     * RuntimeException (a state error, e.g. "already signed") becomes one too.
     *
     * @return array<string, mixed>
     */
    private function wrap(callable $fn): array
    {
        try {
            return $fn();
        } catch (ConstitutionalViolation $e) {
            throw $e;
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw new ConstitutionalViolation($e->getMessage(), 'CGA Forms Catalog (F-IND-020)');
        }
    }
}

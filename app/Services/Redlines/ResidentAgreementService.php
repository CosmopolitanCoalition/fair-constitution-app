<?php

namespace App\Services\Redlines;

use App\Domain\Engine\ConstitutionalViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Design Round 2 ③ — person-to-person / N-party agreements (the #9 reversal;
 * operator "for sure needs to be built"). An agreement between residents with
 * no organization — org_contracts requires an org, so this is its own plane.
 *
 * Art. I: freedom to contract requires the consent of EACH party. The
 * agreement is ACTIVE only when ALL named signers have signed — a one-sided
 * contract never takes effect. The initiator signs on creation; every other
 * named party signs for themselves. Parties are shown BY NAME (a signature is
 * a name — the consent plane, not the pseudonymous money plane).
 */
class ResidentAgreementService
{
    public function __construct(private RedlineService $redlines) {}

    /**
     * Open an agreement. The initiator is a signer and signs now; the other
     * named residents are unsigned parties until each signs.
     *
     * @param  array<int, string>  $signerUserIds  the OTHER parties (not the initiator)
     * @return array{agreement_id: string, status: string}
     */
    public function create(string $title, string $terms, string $initiatorUserId, array $signerUserIds): array
    {
        $others = array_values(array_unique(array_filter(
            array_map('strval', $signerUserIds),
            fn ($id) => $id !== '' && $id !== $initiatorUserId,
        )));

        if ($others === []) {
            throw new ConstitutionalViolation(
                'An agreement needs at least one other party — a contract with yourself is not one.',
                'Art. I'
            );
        }

        $agreementId = (string) Str::uuid();

        DB::transaction(function () use ($agreementId, $title, $terms, $initiatorUserId, $others) {
            DB::table('resident_agreements')->insert([
                'id'                => $agreementId,
                'title'             => $title,
                'terms'             => $terms,
                'initiator_user_id' => $initiatorUserId,
                'status'            => 'offered',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            // The initiator signs on creation; the others are unsigned parties.
            DB::table('resident_agreement_signers')->insert([
                'id'             => (string) Str::uuid(),
                'agreement_id'   => $agreementId,
                'signer_user_id' => $initiatorUserId,
                'signed_at'      => now(),
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            foreach ($others as $userId) {
                DB::table('resident_agreement_signers')->insert([
                    'id'             => (string) Str::uuid(),
                    'agreement_id'   => $agreementId,
                    'signer_user_id' => $userId,
                    'signed_at'      => null,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }

            // The negotiable overlay starts from the whole terms.
            $this->redlines->seedClauses(RedlineService::SUBJECT_RESIDENT, $agreementId, $title, $terms);
        });

        return ['agreement_id' => $agreementId, 'status' => 'offered'];
    }

    /**
     * A named party signs. When the LAST unsigned party signs, the agreement
     * becomes active in the same act.
     *
     * @return array{agreement_id: string, status: string, all_signed: bool}
     */
    public function sign(string $agreementId, string $userId): array
    {
        $signer = DB::table('resident_agreement_signers')
            ->where('agreement_id', $agreementId)
            ->where('signer_user_id', $userId)
            ->first();

        if ($signer === null) {
            throw new ConstitutionalViolation(
                'Only a named party to this agreement may sign it.',
                'Art. I'
            );
        }
        if ($signer->signed_at !== null) {
            throw new RuntimeException('You have already signed this agreement.');
        }

        $allSigned = false;

        DB::transaction(function () use ($agreementId, $userId, &$allSigned) {
            DB::table('resident_agreement_signers')
                ->where('agreement_id', $agreementId)->where('signer_user_id', $userId)
                ->update(['signed_at' => now(), 'updated_at' => now()]);

            $unsigned = DB::table('resident_agreement_signers')
                ->where('agreement_id', $agreementId)->whereNull('signed_at')->count();

            if ($unsigned === 0) {
                $allSigned = true;
                DB::table('resident_agreements')->where('id', $agreementId)->update([
                    'status'       => 'active',
                    'effective_at' => now(),
                    'updated_at'   => now(),
                ]);
            }
        });

        return ['agreement_id' => $agreementId, 'status' => $allSigned ? 'active' : 'offered', 'all_signed' => $allSigned];
    }

    /** Is this user a party to the agreement? (the redline party-gate helper) */
    public function isParty(string $agreementId, string $userId): bool
    {
        return DB::table('resident_agreement_signers')
            ->where('agreement_id', $agreementId)->where('signer_user_id', $userId)->exists();
    }
}

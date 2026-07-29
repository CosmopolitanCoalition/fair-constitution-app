<?php

namespace App\Services\Redlines;

use App\Domain\Engine\ConstitutionalViolation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Design Round 2 ③ — the shared clause/redline overlay (operator ruling
 * 2026-07-29, adopted as the implementation of settled law).
 *
 * ONE data model, TWO authority planes:
 *   - AGREEMENTS (subject_type='org_contract') are the CONSENT plane —
 *     bilateral mutual consent. A party proposes; the other accepts, rejects,
 *     or the proposer withdraws. An ACCEPTED redline applies to the clause AND
 *     CLEARS the signatures, because a signature is on a SPECIFIC text — the
 *     parties must re-sign the changed instrument.
 *   - BILLS (subject_type='bill') are the GOVERNANCE plane — a redline is NOT
 *     a counterparty accept. Adoption is a CHAMBER VOTE. This service does not
 *     re-implement that authority: composeBillText assembles the overlay into
 *     the whole text a legislator files as an F-LEG-007 amendment motion, and
 *     the existing motion+vote machinery adopts it. The WIRING pin holds —
 *     bill_versions stays whole-text; clauses are an additive overlay.
 *
 * The Art. I floor: rights_flag is a DECLARED WAIVER tag. A clause may not
 * waive a constitutional right, so a redline carrying a rights_flag is refused
 * pre-commit (Art. I), and voidInPart() is the backstop for anything that ever
 * slips through. THE HONEST LIMIT: free text cannot be mechanically proven
 * safe — this enforces the structured tag and unenforceability, not a
 * natural-language proof.
 */
class RedlineService
{
    public const SUBJECT_ORG_CONTRACT = 'org_contract';
    public const SUBJECT_RESIDENT     = 'resident';       // a resident_agreement (piece 6)
    public const SUBJECT_BILL         = 'bill';

    public const KIND_EDIT   = 'edit';
    public const KIND_ADD    = 'add';
    public const KIND_STRIKE = 'strike';

    /** A rights_flag names a right a clause purports to WAIVE (Art. I floor). */
    public const RIGHTS = ['voting', 'candidacy', 'residency', 'petition', 'due_process'];

    /**
     * Seed the overlay from a subject's whole text, once. The whole text stays
     * authoritative; this is the negotiable view of it. Idempotent — if the
     * subject already has clauses, nothing happens.
     */
    public function seedClauses(string $subjectType, string $subjectId, string $heading, string $body): void
    {
        $exists = DB::table('clauses')
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->whereNull('deleted_at')->exists();

        if ($exists) {
            return;
        }

        DB::table('clauses')->insert([
            'id'           => (string) Str::uuid(),
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'ordinal'      => 0,
            'heading'      => $heading,
            'body'         => $body,
            'rights_flag'  => null,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * Propose a redline (pending). The Art. I floor runs here: a declared
     * waiver of a right is refused before it can persist.
     *
     * @return array{redline_id: string, status: string}
     */
    public function propose(
        string $subjectType,
        string $subjectId,
        ?string $clauseId,
        string $kind,
        string $body,
        ?string $rationale,
        ?string $rightsFlag,
        string $proposerUserId,
    ): array {
        if (! in_array($kind, [self::KIND_EDIT, self::KIND_ADD, self::KIND_STRIKE], true)) {
            throw new RuntimeException("Unknown redline kind [{$kind}].");
        }

        // THE Art. I FLOOR. A clause may not sign away a constitutional right.
        if ($rightsFlag !== null) {
            if (! in_array($rightsFlag, self::RIGHTS, true)) {
                throw new RuntimeException("Unknown rights flag [{$rightsFlag}].");
            }

            throw new ConstitutionalViolation(
                "No clause may waive the right to {$rightsFlag}. A term that tries is void in that part; the rest of the agreement stands.",
                'Art. I'
            );
        }

        // A new-clause proposal has no target; edit/strike must name one.
        if ($kind !== self::KIND_ADD && $clauseId === null) {
            throw new RuntimeException('An edit or strike must name the clause it changes.');
        }

        $id = (string) Str::uuid();
        DB::table('redlines')->insert([
            'id'               => $id,
            'subject_type'     => $subjectType,
            'subject_id'       => $subjectId,
            'clause_id'        => $clauseId,
            'proposer_user_id' => $proposerUserId,
            'kind'             => $kind,
            'body'             => $body,
            'rationale'        => $rationale,
            'rights_flag'      => null,
            'status'           => 'pending',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return ['redline_id' => $id, 'status' => 'pending'];
    }

    /**
     * Accept a redline on an AGREEMENT: apply it to the overlay and CLEAR the
     * signatures — the instrument changed, so the parties re-sign. Bills never
     * reach here (they adopt by vote).
     *
     * @return array{redline_id: string, status: string, signatures_cleared: bool}
     */
    public function acceptForAgreement(string $redlineId): array
    {
        $redline = DB::table('redlines')->where('id', $redlineId)->first();
        if ($redline === null) {
            throw new RuntimeException('Unknown redline.');
        }
        if ($redline->subject_type === self::SUBJECT_BILL) {
            throw new ConstitutionalViolation(
                'A bill amendment is adopted by a vote of the chamber, not accepted by a party.',
                'Art. V §3'
            );
        }
        if ($redline->status !== 'pending') {
            throw new RuntimeException('Only a pending redline can be accepted.');
        }

        DB::transaction(function () use ($redline) {
            $this->applyToOverlay($redline);

            DB::table('redlines')->where('id', $redline->id)->update([
                'status' => 'accepted', 'updated_at' => now(),
            ]);

            // A signature is on a specific text. The text changed → every
            // signature on it is void; the instrument drops out of 'active' and
            // the parties re-sign the changed text.
            if ($redline->subject_type === self::SUBJECT_ORG_CONTRACT) {
                DB::table('org_contracts')->where('id', $redline->subject_id)->update([
                    'signed_by_org_at'          => null,
                    'signed_by_counterparty_at' => null,
                    'status'                    => 'offered',
                    'updated_at'                => now(),
                ]);
            } elseif ($redline->subject_type === self::SUBJECT_RESIDENT) {
                DB::table('resident_agreement_signers')
                    ->where('agreement_id', $redline->subject_id)
                    ->update(['signed_at' => null, 'updated_at' => now()]);
                DB::table('resident_agreements')->where('id', $redline->subject_id)->update([
                    'status' => 'offered', 'effective_at' => null, 'updated_at' => now(),
                ]);
            }
        });

        return ['redline_id' => $redlineId, 'status' => 'accepted', 'signatures_cleared' => true];
    }

    public function reject(string $redlineId): void
    {
        $this->setStatus($redlineId, 'rejected');
    }

    public function withdraw(string $redlineId): void
    {
        $this->setStatus($redlineId, 'withdrawn');
    }

    /**
     * Compose the current overlay (with accepted edits/strikes applied) into
     * the whole text a legislator files as an F-LEG-007 amendment motion. This
     * is the ONLY bridge to the bill plane — the vote still adopts it.
     */
    public function composeBillText(string $billId): string
    {
        $clauses = DB::table('clauses')
            ->where('subject_type', self::SUBJECT_BILL)->where('subject_id', $billId)
            ->whereNull('deleted_at')
            ->orderBy('ordinal')
            ->get(['heading', 'body']);

        return $clauses->map(fn ($c) => trim(($c->heading ? $c->heading . "\n" : '') . $c->body))
            ->implode("\n\n");
    }

    private function applyToOverlay(object $redline): void
    {
        if ($redline->kind === self::KIND_ADD) {
            $maxOrdinal = (int) DB::table('clauses')
                ->where('subject_type', $redline->subject_type)->where('subject_id', $redline->subject_id)
                ->whereNull('deleted_at')->max('ordinal');

            DB::table('clauses')->insert([
                'id'           => (string) Str::uuid(),
                'subject_type' => $redline->subject_type,
                'subject_id'   => $redline->subject_id,
                'ordinal'      => $maxOrdinal + 1,
                'heading'      => null,
                'body'         => $redline->body,
                'rights_flag'  => null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            return;
        }

        if ($redline->kind === self::KIND_STRIKE) {
            DB::table('clauses')->where('id', $redline->clause_id)->update([
                'deleted_at' => now(), 'updated_at' => now(),
            ]);

            return;
        }

        // edit
        DB::table('clauses')->where('id', $redline->clause_id)->update([
            'body' => $redline->body, 'updated_at' => now(),
        ]);
    }

    private function setStatus(string $redlineId, string $status): void
    {
        $updated = DB::table('redlines')
            ->where('id', $redlineId)->where('status', 'pending')
            ->update(['status' => $status, 'updated_at' => now()]);

        if ($updated === 0) {
            throw new RuntimeException('Only a pending redline can be resolved.');
        }
    }
}

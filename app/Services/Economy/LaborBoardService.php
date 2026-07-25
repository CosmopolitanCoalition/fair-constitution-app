<?php

namespace App\Services\Economy;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\OrgWorker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase M slice M-2 — the work board. Postings, applications, hiring.
 *
 * THE ONE RULE THAT MATTERS HERE: this is a FRONT DOOR onto a chain that was
 * already built and constitutionally pinned, never a bypass of it.
 *
 *   accept() → ConstitutionalEngine::file('F-IND-014')
 *            → OrgMembershipService::registerWorker
 *            → org_contracts(labor_recurring), worker-side signed
 *            → F-ORG-001 countersign by the organisation
 *            → RecomputeWorkerHeadcountJob (afterCommit)
 *            → CoDeterminationService::recompute  [PROTECTED, Art. III §6]
 *            → CLK-13 / CLK-14 → worker board seats
 *
 * Everything from F-IND-014 onward already exists and is pinned end-to-end by
 * WorkerRepresentationTest. So this service deliberately writes NOTHING to
 * org_workers, org_contracts or boards itself — it files the form and lets the
 * built machinery do its job. A hire that reached an organisation's headcount
 * without passing Art. III §6 would make worker representation optional, which
 * is exactly what §6 exists to prevent. LaborBoardTest pins that accept()
 * files the form rather than touching those tables.
 *
 * Note the split of concerns: a POSTING is market surface (who wants work
 * done), the HIRE is constitutional machinery (what that does to a board).
 * The board can be redesigned freely; the chain behind it cannot.
 */
class LaborBoardService
{
    public function __construct(private ConstitutionalEngine $engine) {}

    /** An organisation posts work. */
    public function post(
        string $organizationId,
        string $title,
        string $terms,
        ?string $rate = null,
        ?string $currencyId = null,
    ): string {
        $id = (string) Str::uuid();

        DB::table('work_postings')->insert([
            'id'              => $id,
            'organization_id' => $organizationId,
            'title'           => $title,
            'terms'           => $terms,
            'rate'            => $rate,
            'currency_id'     => $currencyId,
            'status'          => 'open',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return $id;
    }

    /** A resident applies (F-IND-019). Account-scoped, like everything in M. */
    public function apply(string $postingId, string $applicantAccountId, ?string $note = null): string
    {
        $posting = DB::table('work_postings')->where('id', $postingId)->first();

        if ($posting === null || $posting->status !== 'open') {
            throw new RuntimeException('That posting is not open.');
        }

        $id = (string) Str::uuid();

        DB::table('work_applications')->insert([
            'id'                   => $id,
            'posting_id'           => $postingId,
            'applicant_account_id' => $applicantAccountId,
            'note'                 => $note,
            'status'               => 'applied',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        return $id;
    }

    /**
     * Accept an application → file F-IND-014 as the applicant.
     *
     * The applicant is passed as a User because the engine files AS an actor
     * and F-IND-014 is an R-01 form — the worker registers themselves. The
     * organisation's side of the agreement is its countersign (F-ORG-001),
     * which is a separate, already-built act; a hire is two consents, and
     * this service cannot manufacture the second one.
     */
    public function accept(string $applicationId, User $applicant, ?string $contractTerms = null): void
    {
        $application = DB::table('work_applications')->where('id', $applicationId)->first();

        if ($application === null || $application->status !== 'applied') {
            throw new RuntimeException('That application is not awaiting a decision.');
        }

        $posting = DB::table('work_postings')->where('id', $application->posting_id)->first();

        if ($posting === null) {
            throw new InvalidArgumentException('That application has no posting.');
        }

        DB::transaction(function () use ($application, $posting, $applicant, $contractTerms) {
            // The constitutional path. NOT a direct write to org_workers.
            $this->engine->file('F-IND-014', $applicant, [
                'employer_type'  => OrgWorker::EMPLOYER_ORGANIZATIONS,
                'employer_id'    => (string) $posting->organization_id,
                'contract_terms' => $contractTerms ?: $posting->terms,
            ]);

            DB::table('work_applications')->where('id', $application->id)->update([
                'status'     => 'accepted',
                'updated_at' => now(),
            ]);

            DB::table('work_postings')->where('id', $posting->id)->update([
                'status'     => 'filled',
                'updated_at' => now(),
            ]);
        });
    }

    public function decline(string $applicationId): void
    {
        DB::table('work_applications')
            ->where('id', $applicationId)
            ->where('status', 'applied')
            ->update(['status' => 'declined', 'updated_at' => now()]);
    }
}

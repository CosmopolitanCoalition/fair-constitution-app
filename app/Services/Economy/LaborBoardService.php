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
        return DB::transaction(function () use ($postingId, $applicantAccountId, $note) {
            $posting = DB::table('work_postings')->where('id', $postingId)->whereNull('deleted_at')->lockForUpdate()->first();

            if ($posting === null || $posting->status !== 'open') {
                throw new RuntimeException('That posting is not open.');
            }

            $alreadyApplied = DB::table('work_applications')
                ->where('posting_id', $postingId)
                ->where('applicant_account_id', $applicantAccountId)
                ->exists();

            if ($alreadyApplied) {
                throw new RuntimeException('You have already applied to this posting. Its application remains on the record, including after withdrawal or decline.');
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
        });
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
        $this->withApplication($applicationId, function ($application, $posting) use ($applicant, $contractTerms) {
            $this->assertApplicant($application, $applicant);
            $this->assertPending($application, $posting);
            $this->activeOrganization((string) $posting->organization_id);
            if ($application->offered_at === null || empty($application->offer_terms)) {
                throw new RuntimeException('The employer has not offered terms for this application yet.');
            }
            if ($contractTerms !== null && $contractTerms !== $application->offer_terms) {
                throw new InvalidArgumentException('Acceptance must use the exact terms offered by the employer.');
            }
            // The constitutional path. NOT a direct write to org_workers.
            $result = $this->engine->file('F-IND-014', $applicant, [
                'employer_type'  => OrgWorker::EMPLOYER_ORGANIZATIONS,
                'employer_id'    => (string) $posting->organization_id,
                'contract_terms' => (string) $application->offer_terms,
            ]);
            $contractId = $result->recorded['contract_id'] ?? null;
            if (! is_string($contractId) || ! Str::isUuid($contractId)) throw new RuntimeException('Worker registration did not return an agreement. No offer was accepted.');

            DB::table('work_applications')->where('id', $application->id)->update([
                'status'     => 'accepted',
                'org_contract_id' => $contractId,
                'updated_at' => now(),
            ]);

            DB::table('work_postings')->where('id', $posting->id)->update([
                'status'     => 'filled',
                'updated_at' => now(),
            ]);
        });
    }

    /** Explicit employer action; offering never files a worker's consent. */
    public function offer(string $applicationId, User $employer, string $terms): void
    {
        $this->withApplication($applicationId, function ($application, $posting) use ($employer, $terms) {
            $this->assertEmployer((string) $posting->organization_id, $employer);
            $this->assertPending($application, $posting);
            if ($application->offered_at !== null) throw new RuntimeException('An offer is already recorded. Its terms cannot be changed while the applicant decides.');
            if (trim($terms) === '') throw new InvalidArgumentException('An offer must state the complete work terms.');
            DB::table('work_applications')->where('id', $application->id)->update([
                'offered_at' => now(), 'offer_terms' => trim($terms), 'updated_at' => now(),
            ]);
        });
    }

    public function decline(string $applicationId, User $employer): void
    {
        $this->withApplication($applicationId, function ($application, $posting) use ($employer) {
            $this->assertEmployer((string) $posting->organization_id, $employer);
            $this->assertPending($application, $posting);
            DB::table('work_applications')->where('id', $application->id)->update(['status' => 'declined', 'updated_at' => now()]);
        });
    }

    public function withdraw(string $applicationId, User $applicant): void
    {
        $this->withApplication($applicationId, function ($application) use ($applicant) {
            $this->assertApplicant($application, $applicant);
            if ($application->status !== 'applied') throw new RuntimeException('Only a pending application can be withdrawn. Accepted agreements use their own contract process.');
            DB::table('work_applications')->where('id', $application->id)->update(['status' => 'withdrawn', 'updated_at' => now()]);
        });
    }

    public function postFor(User $employer, string $organizationId, string $title, string $terms, ?string $rate = null, ?string $currencyId = null): string
    {
        return DB::transaction(function () use ($employer, $organizationId, $title, $terms, $rate, $currencyId) {
            $this->assertEmployer($organizationId, $employer);
            if (trim($title) === '' || trim($terms) === '') throw new InvalidArgumentException('A posting needs a title and complete work terms.');
            return $this->post($organizationId, $title, $terms, $rate, $currencyId);
        });
    }

    public function closePosting(string $postingId, User $employer): void
    {
        DB::transaction(function () use ($postingId, $employer) {
            $posting = DB::table('work_postings')->where('id', $postingId)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_if($posting === null, 404);
            $this->assertEmployer((string) $posting->organization_id, $employer);
            if ($posting->status !== 'open') throw new RuntimeException('Only an open posting can be closed.');
            DB::table('work_postings')->where('id', $postingId)->update(['status' => 'closed', 'updated_at' => now()]);
        });
    }

    public function assertEmployer(string $organizationId, User $actor): object
    {
        $org = $this->activeOrganization($organizationId);
        abort_unless((string) $org->agent_user_id === (string) $actor->getKey(), 403, 'Only this organization’s current agent can manage hiring.');
        return $org;
    }

    private function activeOrganization(string $id): object
    {
        $org = DB::table('organizations')->where('id', $id)->whereNull('deleted_at')
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())->first(['id', 'name', 'agent_user_id', 'status']);
        abort_unless($org && $org->status === 'active', 403, 'Hiring is available only for an active organization.');
        return $org;
    }

    private function assertApplicant(object $application, User $actor): void
    {
        // The restricted binding is checked only for the authenticated applicant,
        // and is never resolved to a person for the employer's review screen.
        $owns = DB::table('economic_account_bindings')->where('account_id', $application->applicant_account_id)
            ->where('owner_type', 'users')->where('owner_id', (string) $actor->getKey())->exists();
        abort_unless($owns, 403, 'This application belongs to another applicant.');
    }

    private function assertPending(object $application, object $posting): void
    {
        if ($application->status !== 'applied' || $application->org_contract_id !== null) throw new RuntimeException('That application is no longer awaiting a decision.');
        if ($posting->status !== 'open' || $posting->deleted_at !== null) throw new RuntimeException('That posting is no longer open.');
    }

    private function withApplication(string $id, callable $operation): mixed
    {
        return DB::transaction(function () use ($id, $operation) {
            $postingId = DB::table('work_applications')->where('id', $id)->value('posting_id');
            abort_if($postingId === null, 404);
            // Every application action locks the posting first. Two offers cannot
            // accept concurrently and fill the same posting twice.
            $posting = DB::table('work_postings')->where('id', $postingId)->lockForUpdate()->first();
            $application = DB::table('work_applications')->where('id', $id)->where('posting_id', $postingId)->lockForUpdate()->first();
            abort_if($posting === null || $application === null, 404);
            return $operation($application, $posting);
        });
    }
}

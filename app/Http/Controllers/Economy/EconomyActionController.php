<?php

namespace App\Http\Controllers\Economy;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase L+M write surfaces — the three doors, and nothing else.
 *
 * This controller is DELIBERATELY THIN. It validates shape, files the form,
 * and reports. It holds no economic logic whatsoever: no balance check, no
 * ownership check, no price arithmetic. Every one of those lives behind
 * `ConstitutionalEngine::file()`, in the handler and the service under it.
 *
 * WHY THAT MATTERS MORE THAN IT LOOKS. A controller that "helpfully" checks
 * the balance before filing produces a SECOND set of rails, and the two drift
 * — the day they disagree, the page and the ledger tell different stories
 * about the same money. So the only question this class answers is "is this
 * the right shape", and the constitution answers everything else.
 *
 * REFUSALS ARE NOT ERRORS. A ConstitutionalViolation renders app-wide as
 * `back()->withErrors(['constitution' => …])` carrying its citation
 * (bootstrap/app.php), and the engine has already recorded the rejected
 * filing on the audit chain before rethrowing. So there is no try/catch here
 * and there should not be one: a refusal is a reasoned answer that the player
 * is entitled to read, not an exception to swallow.
 *
 * PRIVACY. Not one method accepts a person. Counterparties are account ids;
 * the filer's own account is resolved inside the handler from their identity.
 * A write path is exactly where the reader-privacy ruling would have quietly
 * failed, so it is enforced at the narrowest point rather than trusted.
 */
class EconomyActionController extends Controller
{
    public function __construct(private ConstitutionalEngine $engine) {}

    /** F-IND-023 — move money to another account. */
    public function transfer(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // A DECIMAL STRING, never a float: numeric(24,6) through a float
            // silently loses precision, and this is a ledger.
            'amount'        => ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,6})?$/'],
            'to_account_id' => ['required', 'uuid'],
            'memo'          => ['nullable', 'string', 'max:280'],
        ], [
            'amount.regex' => 'An amount is a number, up to six decimal places.',
        ]);

        $this->engine->file('F-IND-023', $request->user(), [
            'to_account_id' => $validated['to_account_id'],
            'amount'        => $validated['amount'],
            'memo'          => $validated['memo'] ?? null,
        ]);

        return back()->with('status', 'Sent (F-IND-023). It is on the ledger, and the ledger is append-only — a transfer cannot be unsent, only sent back.');
    }

    /** F-IND-020 — open a person-to-person / N-party agreement. */
    public function createAgreement(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title'     => ['required', 'string', 'max:200'],
            'terms'     => ['required', 'string', 'max:10000'],
            'signers'   => ['required', 'array', 'min:1'],
            'signers.*' => ['uuid'],
        ]);

        $this->engine->file('F-IND-020', $request->user(), [
            'action'  => 'create_agreement',
            'title'   => $validated['title'],
            'terms'   => $validated['terms'],
            'signers' => $validated['signers'],
        ]);

        return back()->with('status', 'Agreement offered (F-IND-020). It takes effect only when every party has signed — a one-sided contract never takes effect (Art. I).');
    }

    /** F-IND-020 — a named party signs. */
    public function signAgreement(Request $request, string $agreement): RedirectResponse
    {
        $this->engine->file('F-IND-020', $request->user(), [
            'action'       => 'sign_agreement',
            'agreement_id' => $agreement,
        ]);

        return back()->with('status', 'Signed (F-IND-020). When the last party signs, the agreement becomes active in the same act.');
    }

    /** F-IND-020 — propose a clause redline on an agreement you are party to. */
    public function proposeRedline(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject_type' => ['required', 'string', 'in:resident,org_contract,bill'],
            'subject_id'   => ['required', 'uuid'],
            'clause_id'    => ['nullable', 'uuid'],
            'kind'         => ['required', 'string', 'in:edit,add,strike'],
            'body'         => ['required', 'string', 'max:10000'],
            'rationale'    => ['nullable', 'string', 'max:2000'],
            // The Art. I floor is enforced in the service; a tagged waiver is
            // refused there with a citation, not silently dropped here.
            'rights_flag'  => ['nullable', 'string', 'max:32'],
        ]);

        $this->engine->file('F-IND-020', $request->user(), [
            'action'       => 'propose_redline',
            'subject_type' => $validated['subject_type'],
            'subject_id'   => $validated['subject_id'],
            'clause_id'    => $validated['clause_id'] ?? null,
            'kind'         => $validated['kind'],
            'body'         => $validated['body'],
            'rationale'    => $validated['rationale'] ?? null,
            'rights_flag'  => $validated['rights_flag'] ?? null,
        ]);

        return back()->with('status', 'Redline proposed (F-IND-020). The other party accepts, rejects, or counters.');
    }

    /** F-IND-020 — resolve a redline (accept clears the signatures). */
    public function resolveRedline(Request $request, string $redline, string $how): RedirectResponse
    {
        abort_unless(in_array($how, ['accept', 'reject', 'withdraw'], true), 404);

        $this->engine->file('F-IND-020', $request->user(), [
            'action'     => $how . '_redline',
            'redline_id' => $redline,
        ]);

        return back()->with('status', 'Redline resolved (F-IND-020). An accepted change voids the signatures — the parties re-sign the changed text.');
    }

    /** F-IND-024 — bring a thing into the world, or hand one on. */
    public function registerAsset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:200'],
            // Physical and virtual are ONE FLAG, not two systems — a blanket
            // and a map-maker's compass travel the same rails.
            'kind'        => ['required', 'string', 'in:physical,virtual'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity'    => ['nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ]);

        $this->engine->file('F-IND-024', $request->user(), [
            'name'        => $validated['name'],
            'kind'        => $validated['kind'],
            'description' => $validated['description'] ?? null,
            'quantity'    => $validated['quantity'] ?? '1',
        ]);

        return back()->with('status', 'Registered (F-IND-024). It is yours, it has its own provenance, and every hand it passes through is recorded.');
    }

    /** F-IND-022 — offer something on the open market. */
    public function listOffer(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title'       => ['required', 'string', 'max:200'],
            'kind'        => ['required', 'string', 'in:good,service'],
            'price'       => ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,6})?$/'],
            'asset_id'    => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->engine->file('F-IND-022', $request->user(), [
            'action'      => 'list',
            'kind'        => $validated['kind'],
            'title'       => $validated['title'],
            'price'       => $validated['price'],
            'asset_id'    => $validated['asset_id'] ?? null,
            'description' => $validated['description'] ?? null,
        ]);

        return back()->with('status', 'Listed (F-IND-022). Anyone in this world can see it and order it — the market is open (Art. III §5).');
    }

    /** F-IND-022 — order against a listing. */
    public function order(Request $request, string $listing): RedirectResponse
    {
        $this->engine->file('F-IND-022', $request->user(), [
            'action'     => 'order',
            'listing_id' => $listing,
        ]);

        return back()->with('status', 'Ordered (F-IND-022). Nothing has moved yet — the seller accepts, and money and thing move together or not at all.');
    }

    /**
     * F-IND-022 — accept an order and settle it.
     *
     * The handler refuses this to anyone but the seller. The page also hides
     * the control from a buyer, but that is UX: the boundary is the form.
     */
    public function settle(Request $request, string $order): RedirectResponse
    {
        $this->engine->file('F-IND-022', $request->user(), [
            'action'   => 'settle',
            'order_id' => $order,
        ]);

        return back()->with('status', 'Settled (F-IND-022). Money and thing moved in ONE transaction — both, or neither.');
    }

    /**
     * F-IND-019 — apply to a work posting.
     *
     * Applying commits nobody: the HIRE is the separate F-IND-014 chain that
     * acceptance files, and this controller cannot reach it.
     */
    public function applyForWork(Request $request, string $posting): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->engine->file('F-IND-019', $request->user(), [
            'posting_id' => $posting,
            'note'       => $validated['note'] ?? null,
        ]);

        return back()->with('status', 'Applied (F-IND-019). The organization decides — if it accepts, the work agreement is recorded with both signatures, never one.');
    }

    /** F-IND-023 · joint_open — open a co-owned ledger, rule named up front. */
    public function jointOpen(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:160'],
            'purpose'             => ['nullable', 'string', 'max:500'],
            'approval_rule'       => ['required', 'string', 'in:all,majority'],
            'public'              => ['nullable', 'boolean'],
            'party_account_ids'   => ['required', 'array', 'min:1', 'max:20'],
            'party_account_ids.*' => ['uuid'],
        ]);

        $this->engine->file('F-IND-023', $request->user(), [
            'action'            => 'joint_open',
            'name'              => $validated['name'],
            'purpose'           => $validated['purpose'] ?? null,
            'approval_rule'     => $validated['approval_rule'],
            'public'            => (bool) ($validated['public'] ?? false),
            'party_account_ids' => $validated['party_account_ids'],
        ]);

        return back()->with('status', 'Opened (F-IND-023). Fund it with a plain transfer to its account — and from here on, no movement leaves it without the agreed signatures.');
    }

    /** F-IND-023 · joint_propose — propose a movement out; proposing is your signature. */
    public function jointPropose(Request $request, string $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'to_account_id' => ['required', 'uuid'],
            'amount'        => ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,6})?$/'],
            'memo'          => ['nullable', 'string', 'max:240'],
        ], [
            'amount.regex' => 'An amount is a number, up to six decimal places.',
        ]);

        $this->engine->file('F-IND-023', $request->user(), [
            'action'        => 'joint_propose',
            'ledger_id'     => $ledger,
            'to_account_id' => $validated['to_account_id'],
            'amount'        => $validated['amount'],
            'memo'          => $validated['memo'] ?? null,
        ]);

        return back()->with('status', 'Proposed (F-IND-023). Your signature is on it; the movement waits until the ledger\'s rule is met — one signer never moves shared money alone.');
    }

    /** F-IND-023 · joint_approve — the approval that meets the rule settles it. */
    public function jointApprove(Request $request, string $movement): RedirectResponse
    {
        $this->engine->file('F-IND-023', $request->user(), [
            'action'      => 'joint_approve',
            'movement_id' => $movement,
        ]);

        return back()->with('status', 'Approved (F-IND-023). If yours was the signature that completed the rule, the movement has settled — money moved in the same act as the consent.');
    }
}

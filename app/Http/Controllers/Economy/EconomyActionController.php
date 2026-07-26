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
}

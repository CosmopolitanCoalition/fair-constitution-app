<?php

namespace App\Http\Controllers\Legislature;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Legislature;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * F-LEG-039 — the budget act doors. DELIBERATELY THIN, like the economy write
 * surfaces: validate shape, file the form, report. The engine and
 * BudgetService/ChamberActService hold every rule (draft validity, the enacting
 * vote, the appropriations). A ConstitutionalViolation renders app-wide as a
 * back-with-errors carrying its citation.
 *
 *   POST /legislatures/{legislature}/budgets            — draft a budget
 *   POST /legislatures/{legislature}/budgets/{budget}/enact — move it to enactment
 *
 * The enacted budget then appears on the treasury page (PublicFinanceDirectory
 * reads the budgets table for the place), and its lines stand as appropriations.
 */
class BudgetController extends Controller
{
    public function __construct(private ConstitutionalEngine $engine) {}

    public function draft(Request $request, Legislature $legislature): RedirectResponse
    {
        $validated = $request->validate([
            'fiscal_label'          => ['required', 'string', 'max:120'],
            'lines'                 => ['required', 'array', 'min:1'],
            'lines.*.line'          => ['required', 'string', 'max:200'],
            'lines.*.amount'        => ['required', 'string', 'regex:/^\d{1,18}(\.\d{1,6})?$/'],
            'lines.*.department_id' => ['nullable', 'uuid'],
            'currency_id'           => ['nullable', 'uuid'],
        ], [
            'lines.*.amount.regex' => __('An amount is a number, up to six decimal places.'),
        ]);

        $this->engine->file('F-LEG-039', $request->user(), [
            'action'         => 'draft',
            'legislature_id' => (string) $legislature->id,
            'fiscal_label'   => $validated['fiscal_label'],
            'lines'          => $validated['lines'],
            'currency_id'    => $validated['currency_id'] ?? null,
        ]);

        return back()->with('status', __('Budget drafted (F-LEG-039). Move it to enactment for the chamber to vote.'));
    }

    public function enact(Request $request, Legislature $legislature, string $budget): RedirectResponse
    {
        $this->engine->file('F-LEG-039', $request->user(), [
            'action'         => 'enact',
            'legislature_id' => (string) $legislature->id,
            'budget_id'      => $budget,
        ]);

        return back()->with('status', __('Enactment moved (F-LEG-039). The chamber votes; on adoption the lines become appropriations.'));
    }
}

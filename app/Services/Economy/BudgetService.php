<?php

namespace App\Services\Economy;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Executive;
use App\Models\Law;
use App\Services\Executive\GrantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Phase L slice L-4 — budgets, and the appropriations they spawn.
 *
 * THIS COMPLETES A CYCLE RATHER THAN INVENTING ONE. Phase D shipped
 * `appropriations`, `grant_applications`, `grant_disbursements` and a correct,
 * guarded GrantService — but only apply() was ever reachable, and nothing in
 * the application could create an appropriation, so the table was permanently
 * empty and the executive's Grants card could only ever render its empty
 * state. `createAppropriation` had zero callers and zero tests. Budget
 * enactment is the caller it was written for (D-20 #1).
 *
 * The constitutional shape, unchanged from Phase D: legislatures appropriate
 * BY ACT, and the executive administers what was appropriated. So enactment
 * takes the enacting Law and spawns one appropriation per budget line in the
 * SAME transaction — a budget that enacted without its appropriations, or
 * appropriations without their act, would be exactly the half-state this
 * lane's audit criticised.
 *
 * Art. III §4 — Boards of Governors execute their departments' charters, so a
 * budget line may name a department; disbursement stays GrantService's.
 */
class BudgetService
{
    public function __construct(private GrantService $grants) {}

    /**
     * Draft a budget with its lines.
     *
     * @param  array<int, array{line:string, amount:string, department_id?:string|null}>  $lines
     */
    public function draft(string $jurisdictionId, string $currencyId, string $fiscalLabel, array $lines, ?string $legislatureId = null): string
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A budget with no lines appropriates nothing.');
        }

        $budgetId = (string) Str::uuid();

        DB::transaction(function () use ($budgetId, $jurisdictionId, $currencyId, $fiscalLabel, $lines, $legislatureId) {
            $total = '0';

            foreach ($lines as $line) {
                if (bccomp((string) $line['amount'], '0', 6) !== 1) {
                    throw new InvalidArgumentException('A budget line carries a positive amount.');
                }
                $total = bcadd($total, (string) $line['amount'], 6);
            }

            DB::table('budgets')->insert([
                'id'              => $budgetId,
                'jurisdiction_id' => $jurisdictionId,
                'legislature_id'  => $legislatureId,
                'currency_id'     => $currencyId,
                'fiscal_label'    => $fiscalLabel,
                'total'           => $total,
                'status'          => 'draft',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            foreach ($lines as $line) {
                DB::table('budget_lines')->insert([
                    'id'            => (string) Str::uuid(),
                    'budget_id'     => $budgetId,
                    'line'          => $line['line'],
                    'amount'        => $line['amount'],
                    'department_id' => $line['department_id'] ?? null,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        });

        return $budgetId;
    }

    /**
     * Enact a budget (F-LEG-038): every line becomes an appropriation under
     * the enacting act, in one transaction.
     *
     * @return array<int, string>  the appropriation ids created
     */
    public function enact(string $budgetId, Law $law, Executive $executive): array
    {
        return DB::transaction(function () use ($budgetId, $law, $executive) {
            $budget = DB::table('budgets')->where('id', $budgetId)->lockForUpdate()->first();

            if ($budget === null) {
                throw new InvalidArgumentException("Unknown budget [{$budgetId}].");
            }

            if ($budget->status !== 'draft') {
                throw new ConstitutionalViolation(
                    'A budget is enacted once — re-enacting it would appropriate the same money twice.',
                    'Art. II §9 · as implemented'
                );
            }

            $lines = DB::table('budget_lines')->where('budget_id', $budgetId)->get();

            $appropriationIds = [];

            foreach ($lines as $line) {
                // The Phase-D path, unchanged: it re-checks that the act is in
                // force and that the amount is positive, and audit-chains the
                // creation. We supply the caller it never had.
                $appropriation = $this->grants->createAppropriation(
                    $law,
                    $executive,
                    $line->line,
                    (float) $line->amount,
                );

                DB::table('budget_lines')->where('id', $line->id)->update([
                    'appropriation_id' => $appropriation->id,
                    'updated_at'       => now(),
                ]);

                $appropriationIds[] = (string) $appropriation->id;
            }

            DB::table('budgets')->where('id', $budgetId)->update([
                'status'          => 'enacted',
                'enacting_act_id' => (string) $law->id,
                'enacted_at'      => now(),
                'updated_at'      => now(),
            ]);

            return $appropriationIds;
        });
    }
}

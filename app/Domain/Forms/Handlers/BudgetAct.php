<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Engine\ConstitutionalViolation;
use App\Domain\Forms\Contracts\FormHandler;
use App\Domain\Forms\Support\ChamberActor;
use App\Models\Economy\Currency;
use App\Models\Legislature;
use App\Models\LegislatureMember;
use App\Models\User;
use App\Services\Economy\BudgetService;
use App\Services\Legislature\ChamberActService;
use Illuminate\Support\Facades\DB;

/**
 * F-LEG-039 — Budget Act.
 *
 * Two actions, both filed by a serving member (R-09):
 *   draft — draft a budget and its lines (BudgetService::draft).
 *   enact — move a drafted budget to enactment on the floor
 *           (ChamberActService::proposeBudgetEnactment).
 *
 * A budget is an appropriation by act (Art. II §9). Drafting records the
 * intent. Enactment runs the ordinary chamber-vote rail, so both chambers of a
 * bicameral legislature must agree before the money is appropriated. On
 * adoption the lines become appropriations under the enacting act.
 */
class BudgetAct implements FormHandler
{
    public function __construct(
        private readonly BudgetService $budgets,
        private readonly ChamberActService $acts,
    ) {}

    public function module(): string
    {
        return 'legislature';
    }

    public function event(): string
    {
        return 'budget.act';
    }

    public function requiredRoles(): array
    {
        return ['R-09'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        $legislature = ChamberActor::legislature($payload, 'F-LEG-039');
        $member      = ChamberActor::member($actor, (string) $legislature->id, 'F-LEG-039');

        $action = (string) ($payload['action'] ?? 'draft');

        return match ($action) {
            'draft' => $this->draft($legislature, $payload),
            'enact' => $this->enact($legislature, $member, $payload),
            default => throw new ConstitutionalViolation(
                __('Unknown action [:action] — draft or enact.', ['action' => $action]),
                'CGA Forms Catalog (F-LEG-039)'
            ),
        };
    }

    /** @return array<string, mixed> */
    private function draft(Legislature $legislature, array $payload): array
    {
        $fiscalLabel = trim((string) ($payload['fiscal_label'] ?? ''));

        if ($fiscalLabel === '') {
            throw new ConstitutionalViolation(__('A budget names its fiscal period.'), 'CGA Forms Catalog (F-LEG-039)');
        }

        $lines    = $this->normalizeLines($payload['lines'] ?? []);
        $currency = $this->resolveCurrency($payload);

        $budgetId = $this->budgets->draft(
            (string) $legislature->jurisdiction_id,
            (string) $currency->id,
            $fiscalLabel,
            $lines,
            (string) $legislature->id,
        );

        return [
            'action'         => 'budget_drafted',
            'budget_id'      => $budgetId,
            'legislature_id' => (string) $legislature->id,
            'fiscal_label'   => $fiscalLabel,
            'lines'          => count($lines),
        ];
    }

    /** @return array<string, mixed> */
    private function enact(Legislature $legislature, LegislatureMember $member, array $payload): array
    {
        $budgetId = (string) ($payload['budget_id'] ?? '');

        if ($budgetId === '') {
            throw new ConstitutionalViolation(__('Enactment names the budget.'), 'CGA Forms Catalog (F-LEG-039)');
        }

        $result = $this->acts->proposeBudgetEnactment($legislature, $member, $budgetId);

        return [
            'action'         => 'budget_enactment_proposed',
            'budget_id'      => $budgetId,
            'legislature_id' => (string) $legislature->id,
        ] + $result;
    }

    /**
     * @param  mixed  $lines
     * @return array<int, array{line:string, amount:string, department_id:?string}>
     */
    private function normalizeLines(mixed $lines): array
    {
        if (! is_array($lines) || $lines === []) {
            throw new ConstitutionalViolation(__('A budget carries at least one line.'), 'CGA Forms Catalog (F-LEG-039)');
        }

        $out = [];

        foreach ($lines as $line) {
            $label  = trim((string) ($line['line'] ?? ''));
            $amount = (string) ($line['amount'] ?? '');

            if ($label === '') {
                throw new ConstitutionalViolation(__('A budget line names its purpose.'), 'CGA Forms Catalog (F-LEG-039)');
            }

            if ($amount === '' || bccomp($amount, '0', 6) !== 1) {
                throw new ConstitutionalViolation(__('A budget line carries a positive amount.'), 'CGA Forms Catalog (F-LEG-039)');
            }

            $out[] = [
                'line'          => $label,
                'amount'        => $amount,
                'department_id' => isset($line['department_id']) && $line['department_id'] !== '' ? (string) $line['department_id'] : null,
            ];
        }

        return $out;
    }

    private function resolveCurrency(array $payload): Currency
    {
        if (isset($payload['currency_id'])) {
            $currency = Currency::query()->find((string) $payload['currency_id']);

            if ($currency !== null) {
                return $currency;
            }
        }

        // The root's currency — Art. V §5 reserves issuance to the most
        // encompassing jurisdiction, so a world has one by construction.
        $rootId = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');

        $currency = $rootId === null
            ? null
            : Currency::query()->where('jurisdiction_id', $rootId)->whereNull('deleted_at')->first();

        if ($currency === null) {
            throw new ConstitutionalViolation(
                __('This world has no currency yet — the root jurisdiction defines one (Art. V §5).'),
                'Art. V §5'
            );
        }

        return $currency;
    }
}

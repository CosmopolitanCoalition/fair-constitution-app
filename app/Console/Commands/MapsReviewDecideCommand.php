<?php

namespace App\Console\Commands;

use App\Services\DataReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * maps:review-decide — the CLI half of the Step-2 review panel's per-row
 * decision capture (UI↔CLI parity, SetupController::reviewDecision). Records
 * an operator's call on a population / aggregation / orphan / sovereignty
 * discrepancy through the SAME DataReviewService::recordDecision the panel
 * POSTs to: no autofix, decisions recorded only, idempotent upsert on
 * (category, jurisdiction). The category allowlist travels with the pair.
 *
 * Categories are the exact slugs the panel uses: population_gaps ·
 * aggregation_discrepancies · orphans · sovereign_territories. Decisions are
 * free-form (the service enforces no vocabulary, matching the panel); the
 * suggested values per category live in DataReviewService::DECISION_VALUES and
 * print with --list.
 */
class MapsReviewDecideCommand extends Command
{
    protected $signature = 'maps:review-decide
                            {jurisdiction? : Jurisdiction UUID the decision is about}
                            {category?      : population_gaps | aggregation_discrepancies | orphans | sovereign_territories}
                            {decision?      : the decision slug (see --list for suggestions)}
                            {--note=        : optional free-text note}
                            {--list         : print the categories and their suggested decision values, then exit}';

    protected $description = 'Record a Step-2 data-review decision (no autofix) — the CLI door to the review panel';

    public function handle(DataReviewService $service): int
    {
        if ($this->option('list')) {
            foreach (DataReviewService::DECISION_VALUES as $cat => $values) {
                $this->line("<info>{$cat}</info>");
                foreach ($values as $slug => $label) {
                    $this->line("  {$slug} — {$label}");
                }
            }

            return self::SUCCESS;
        }

        $jurisdiction = (string) $this->argument('jurisdiction');
        $category     = (string) $this->argument('category');
        $decision     = (string) $this->argument('decision');

        if ($jurisdiction === '' || $category === '' || $decision === '') {
            $this->error('jurisdiction, category and decision are all required (or pass --list).');

            return self::INVALID;
        }

        // The panel's category allowlist — the guard travels with the pair.
        $allowed = array_keys(DataReviewService::DECISION_VALUES);
        if (! in_array($category, $allowed, true)) {
            $this->error("Unknown review category '{$category}'. One of: ".implode(', ', $allowed).'.');

            return self::INVALID;
        }

        if (! Str::isUuid($jurisdiction)) {
            $this->error("'{$jurisdiction}' is not a valid jurisdiction UUID.");

            return self::INVALID;
        }
        if (! DB::table('jurisdictions')->where('id', $jurisdiction)->exists()) {
            $this->error("No jurisdiction {$jurisdiction}.");

            return self::FAILURE;
        }

        $service->recordDecision($category, $jurisdiction, $decision, $this->option('note') ?: null);
        $this->info("Recorded: {$category} / {$jurisdiction} → {$decision}");

        $suggested = array_keys(DataReviewService::DECISION_VALUES[$category]);
        if (! in_array($decision, $suggested, true)) {
            $this->warn("(note: '{$decision}' is not a suggested value for {$category}; saved anyway, as the panel allows.)");
        }

        return self::SUCCESS;
    }
}

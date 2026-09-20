<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/** Only call after the guarded disposable fixture has created the ledger plane. */
trait UsesProductionLedgerIndexes
{
    protected function installLedgerHistoryIndexes(bool $retireDuplicate = true): void
    {
        $history = require base_path('database/migrations/2026_09_13_050000_public_finance_history_indexes.php');
        foreach ((new \ReflectionClass($history))->getConstant('INDEXES') as $name => [$table, $columns]) {
            if ($table === 'ledger_entries') {
                DB::statement('CREATE INDEX '.$name.' ON '.$table.' ('.$columns.')');
            }
        }
        if ($retireDuplicate) {
            (require base_path('database/migrations/2026_09_20_090000_retire_duplicate_ledger_account_index.php'))->up();
        }
    }
}

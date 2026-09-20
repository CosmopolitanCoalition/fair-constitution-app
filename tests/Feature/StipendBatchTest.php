<?php

namespace Tests\Feature;

use App\Models\SimRun;
use App\Jobs\SimWorkerJob;
use App\Console\Commands\SimPumpCommand;
use App\Services\Demo\SimStipendBatch;
use App\Services\Economy\AccountService;
use App\Services\Economy\LedgerService;
use App\Support\SimClaims;
use App\Support\SimTimer;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/** Reuses the guarded nonce database and all original payment-contract tests. */
class StipendBatchTest extends Phase10PerformanceTest
{
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::statement("CREATE TABLE sim_runs (id uuid PRIMARY KEY, status text, phase text, options jsonb DEFAULT '{}', halt_requested_at timestamptz, paused_until timestamptz, created_at timestamptz, updated_at timestamptz)");
        DB::statement('CREATE TABLE sim_worker_leases (id uuid PRIMARY KEY, run_id uuid, last_seen_at timestamptz)');
        DB::statement('CREATE TABLE sim_items (id uuid PRIMARY KEY, run_id uuid, kind text, status text, position int, jurisdiction_id uuid, legislature_id uuid, race_id uuid, adm_level int, claim_token uuid, metrics jsonb, reason text, started_at timestamptz, updated_at timestamptz, finished_at timestamptz)');
        DB::statement('CREATE INDEX sim_items_claim_order_idx ON sim_items(run_id,kind,status,position,id)');
    }

    protected function tearDown(): void
    {
        foreach ($this->children as [$process, $pipes]) {
            if (is_resource($process)) { proc_terminate($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_close($process); }
        }
        DB::purge('batch_peer');
        parent::tearDown();
    }

    private function world(int $count = 4, bool $draw = false): SimRun
    {
        $this->service();
        DB::table('sim_runs')->insert(['id' => $this->id(700), 'status' => 'running', 'phase' => 'stipends']);
        foreach ([701, 702] as $token) { DB::table('sim_worker_leases')->insert(['id' => $this->id($token), 'run_id' => $this->id(700), 'last_seen_at' => now()]); }
        for ($i = 0; $i < $count; $i++) {
            DB::table('jurisdictions')->insert(['id' => $this->id(80 + $i), 'parent_id' => $this->id(1)]);
            DB::table('legislatures')->insert(['id' => $this->id(120 + $i), 'jurisdiction_id' => $this->id(80 + $i)]);
            $this->resident(100 + $i, ['jurisdiction_id' => $this->id(80 + $i)]);
            $this->member(100 + $i, legislature: 120 + $i);
            $this->settings(80 + $i, ['civic_stipend_floor' => 10 + $i, 'pay_office_holder' => 2 + $i,
                'stipend_funding_source' => $draw ? 'treasury_draw' : 'minted']);
            DB::table('sim_items')->insert(['id' => $this->id(800 + $i), 'run_id' => $this->id(700), 'kind' => 'stipend_scope',
                'status' => 'pending', 'position' => 1, 'jurisdiction_id' => $this->id(80 + $i), 'adm_level' => 6]);
        }
        return SimRun::findOrFail($this->id(700));
    }

    private function batch(SimRun $run, int $limit = 4, int $token = 701): array
    {
        return SimClaims::stipendBatch($run, $this->id($token), $limit);
    }

    private function done(): int { return DB::table('sim_items')->where('status', 'done')->count(); }

    private function noPayments(): void
    {
        foreach (['ledger_entries', 'ubi_disbursements', 'ubi_receipts', 'issuance_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame('0.000000', (string) DB::table('economic_accounts')->sum('balance'));
        $this->assertSame(0, $this->done());
    }

    public function test_four_scopes_commit_once_with_exact_amounts_metrics_and_chain(): void
    {
        $run = $this->world(); $items = $this->batch($run);
        $commits = 0;
        $this->app['events']->listen(TransactionCommitted::class, function ($event) use (&$commits) {
            if ($event->connection->transactionLevel() === 0) { $commits++; }
        });
        $result = app(SimStipendBatch::class)->run($run, $items, $this->id(701));
        $this->assertSame(['done' => 4, 'review' => 0, 'released' => 0], $result);
        $this->assertSame(1, $commits);
        $this->assertSame(4, $this->done());
        $this->assertSame(4, DB::table('issuance_events')->count());
        $this->assertSame(4, DB::table('ubi_disbursements')->count());
        $this->assertSame(8, DB::table('ledger_entries')->distinct()->count('entry_group'));
        foreach ($items as $i => $item) {
            $metrics = json_decode(DB::table('sim_items')->where('id', $item->id)->value('metrics'), true);
            $amount = number_format(12 + 2 * $i, 6, '.', '');
            $this->assertSame($amount, $metrics['total']);
            $this->assertSame($amount, DB::table('economic_accounts')->where('id', $this->id(2100 + $i))->value('balance'));
            $this->assertSame($amount, DB::table('ubi_receipts')->where('disbursement_id', $metrics['disbursement_id'])->value('amount'));
            $this->assertSame(1, $metrics['paid_wallets']);
        }
        $this->assertSame('10000.000000', DB::table('treasury_accounts')->where('id', $this->id(20))->value('balance'));
        $this->assertTrue(app(LedgerService::class)->verifyChain());
        $samples = (new \ReflectionProperty(SimTimer::class, 'n'))->getValue();
        $this->assertSame(1, $samples['stipend_batch.commit']);
        $this->assertSame(1, $samples['stipend_batch.append_wait']);
        $this->assertSame(4, $samples['stipend_batch.scopes']);
        $this->assertSame(4, $samples['stipend_batch.wallets']);
        $this->assertArrayNotHasKey('stipend.commit', $samples);
        $this->assertArrayNotHasKey('stage.stipend_scope', $samples);
        $this->assertSame([], (new \ReflectionProperty(SimTimer::class, 'open'))->getValue());
    }

    public function test_preparation_precedes_lock_and_four_commits_become_one_without_extra_savepoints(): void
    {
        $run = $this->world(); $sim = $this->service();
        $levels = [];
        $this->app['events']->listen(TransactionBeginning::class, function ($event) use (&$levels) {
            $levels[] = $event->connection->transactionLevel();
        });
        $commits = 0;
        $this->app['events']->listen(TransactionCommitted::class, function ($event) use (&$commits) {
            if ($event->connection->transactionLevel() === 0) { $commits++; }
        });
        DB::enableQueryLog(); DB::flushQueryLog();
        // Original payment plus separate queue settlement shape, four scopes.
        foreach (DB::table('sim_items')->orderBy('id')->get() as $item) {
            $sim->runStipendFor($item->jurisdiction_id);
            DB::table('sim_items')->where('id', $item->id)->update(['metrics' => '{}']);
        }
        $baseline = DB::getQueryLog(); $beforeCommits = $commits; $commits = 0;
        $beforeLevels = $levels; $levels = [];
        $items = $this->batch($run);
        DB::flushQueryLog();
        $result = app(SimStipendBatch::class)->run($run, $items, $this->id(701));
        $after = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame(4, $result['done']);
        $this->assertSame(4, $beforeCommits); $this->assertSame(1, $commits);
        $savepoints = fn ($depths) => count(array_filter($depths, fn ($depth) => $depth > 1));
        $this->assertSame($savepoints($beforeLevels), $savepoints($levels), 'No added per-scope savepoints.');
        $this->assertLessThanOrEqual(max($beforeLevels), max($levels));
        $firstLock = array_find_key($after, fn ($q) => str_contains($q['query'], 'pg_advisory_xact_lock'));
        foreach ($after as $index => $query) {
            if (str_contains($query['query'], 'residency_confirmations') || str_contains($query['query'], 'WITH RECURSIVE chain')) {
                $this->assertLessThan($firstLock, $index, 'Roster and policy reads must precede append ownership.');
            }
        }
        fwrite(STDOUT, "\nD010 four-scope query counts: ".count($baseline).' -> '.count($after)."; payment commits 4 -> 1; savepoints ".$savepoints($beforeLevels).' -> '.$savepoints($levels)."\n");
    }

    public function test_treasury_draw_uses_updated_balance_for_each_scope_and_zero_payment_is_honest(): void
    {
        $run = $this->world(draw: true);
        DB::table('treasury_accounts')->where('id', $this->id(20))->update(['balance' => 20]);
        $result = app(SimStipendBatch::class)->run($run, $this->batch($run), $this->id(701));
        $this->assertSame(4, $result['done']);
        $amounts = DB::table('ubi_disbursements')->orderBy('jurisdiction_id')->pluck('total')->all();
        $this->assertSame(['12.000000', '7.999992', '0.000000', '0.000000'], $amounts);
        $this->assertSame(2, DB::table('ubi_receipts')->count());
        $this->assertSame(0, DB::table('issuance_events')->count());
        $this->assertSame(2, (new \ReflectionProperty(SimTimer::class, 'n'))->getValue()['stipend_batch.wallets']);
        $this->assertSame(2, (new \ReflectionProperty(SimTimer::class, 'n'))->getValue()['stipend_batch.paid_scopes']);
        $this->assertTrue(app(LedgerService::class)->verifyChain());
    }

    public function test_mixed_funding_retains_exact_ledger_hashes_receipts_and_balances(): void
    {
        $run = $this->world();
        DB::table('constitutional_settings')->whereIn('jurisdiction_id', [$this->id(81), $this->id(83)])->update(['stipend_funding_source' => 'treasury_draw']);
        $this->freezeTime();
        $sequence = 10000;
        Str::createUuidsUsing(function () use (&$sequence) { return Uuid::fromString($this->id(++$sequence)); });
        $snapshot = function (): array {
            return [
                DB::table('ledger_entries')->orderBy('seq')->get()->map(fn ($row) => array_diff_key((array) $row, ['seq' => true]))->all(),
                DB::table('issuance_events')->orderBy('id')->get()->all(),
                DB::table('ubi_disbursements')->orderBy('id')->get()->all(),
                DB::table('ubi_receipts')->orderBy('id')->get()->all(),
                DB::table('economic_accounts')->orderBy('id')->get(['id', 'currency_id', 'balance', 'deleted_at'])->all(),
                DB::table('treasury_accounts')->orderBy('id')->get()->all(),
            ];
        };
        try {
            DB::beginTransaction();
            foreach (DB::table('sim_items')->orderBy('id')->get() as $item) {
                app(\App\Services\Demo\SimEconomyService::class)->runStipendFor($item->jurisdiction_id);
            }
            $original = $snapshot();
            DB::rollBack();
            $sequence = 10000;
            $result = app(SimStipendBatch::class)->run($run, $this->batch($run), $this->id(701));
            $this->assertSame(4, $result['done']);
            $this->assertEquals($original, $snapshot(), 'All canonical hashes, IDs, payments and balances must match.');
            $this->assertSame(2, DB::table('issuance_events')->count());
        } finally { Str::createUuidsNormally(); }
    }

    public function test_transaction_timeout_rolls_back_and_releases_claims_without_review(): void
    {
        $run = $this->world(); $items = $this->batch($run);
        $real = app(AccountService::class);
        DB::statement("SET transaction_timeout = '100ms'");
        $mock = \Mockery::mock(AccountService::class);
        $mock->shouldReceive('creditManyFromTreasury')->andReturnUsing(function () {
            DB::select('SELECT pg_sleep(0.3)');
        });
        $this->app->instance(AccountService::class, $mock); $this->service();
        $result = app(SimStipendBatch::class)->run($run, $items, $this->id(701));
        $this->assertSame(['done' => 0, 'review' => 0, 'released' => 4], $result);
        $this->noPayments();
        $this->assertSame('100ms', DB::selectOne('SHOW transaction_timeout')->transaction_timeout);
        DB::statement('SET transaction_timeout = 0');
        $this->app->instance(AccountService::class, $real); $this->service();
        $this->assertSame(4, app(SimStipendBatch::class)->run($run, $this->batch($run), $this->id(701))['done']);
        $this->assertSame('0', DB::selectOne('SHOW transaction_timeout')->transaction_timeout);
    }

    public function test_failure_after_mint_rolls_back_all_scopes_and_releases_the_other_claims(): void
    {
        $run = $this->world();
        $real = app(AccountService::class); $calls = 0;
        $mock = \Mockery::mock(AccountService::class);
        $mock->shouldReceive('creditManyFromTreasury')->andReturnUsing(function (...$args) use ($real, &$calls) {
            if (++$calls === 2) { throw new \RuntimeException('fixture credit failure'); }
            return $real->creditManyFromTreasury(...$args);
        });
        $this->app->instance(AccountService::class, $mock); $this->service();
        $result = app(SimStipendBatch::class)->run($run, $this->batch($run), $this->id(701));
        $this->assertSame(['done' => 0, 'review' => 1, 'released' => 3], $result);
        $this->noPayments();
        $this->assertSame('review', DB::table('sim_items')->where('id', $this->id(801))->value('status'));
        $this->app->instance(AccountService::class, $real); $this->service();
        $retry = app(SimStipendBatch::class)->run($run, $this->batch($run), $this->id(701));
        $this->assertSame(3, $retry['done']);
        $this->assertTrue(app(LedgerService::class)->verifyChain());
    }

    public function test_receipt_failure_after_wallet_credit_rolls_back_the_whole_batch(): void
    {
        $run = $this->world();
        DB::statement('ALTER TABLE ubi_receipts ADD CONSTRAINT fixture_second_credit_failure CHECK (amount<>14)');
        $result = app(SimStipendBatch::class)->run($run, $this->batch($run), $this->id(701));
        $this->assertSame(['done' => 0, 'review' => 1, 'released' => 3], $result);
        $this->noPayments();
    }

    public function test_failure_immediately_before_commit_and_lost_ack_after_commit_never_repay(): void
    {
        $run = $this->world(); $once = true;
        $this->app['events']->listen(TransactionCommitting::class, function ($event) use (&$once) {
            if ($once && $event->connection->transactionLevel() === 1) { $once = false; throw new \RuntimeException('fixture before commit'); }
        });
        $result = app(SimStipendBatch::class)->run($run, $this->batch($run), $this->id(701));
        $this->assertSame(4, $result['released']); $this->noPayments();
        $after = true;
        $this->app['events']->listen(TransactionCommitted::class, function ($event) use (&$after) {
            if ($after && $event->connection->transactionLevel() === 0) { $after = false; throw new \RuntimeException('fixture lost commit acknowledgement'); }
        });
        $items = $this->batch($run);
        app(SimStipendBatch::class)->run($run, $items, $this->id(701));
        $this->assertSame(4, $this->done());
        $head = DB::table('ledger_entries')->max('seq');
        app(SimStipendBatch::class)->run($run, $items, $this->id(701));
        $this->assertSame($head, DB::table('ledger_entries')->max('seq'));
        $this->assertSame(4, DB::table('ubi_disbursements')->count());
        $this->assertSame([], $this->batch($run));
    }

    public function test_reclaimed_ownership_cannot_pay_or_overwrite_the_new_owner(): void
    {
        $run = $this->world(); $items = $this->batch($run);
        DB::table('sim_items')->where('id', $items[1]->id)->update(['claim_token' => $this->id(702)]);
        $result = app(SimStipendBatch::class)->run($run, $items, $this->id(701));
        $this->assertSame(3, $result['released']); $this->noPayments();
        $this->assertSame($this->id(702), DB::table('sim_items')->where('id', $items[1]->id)->value('claim_token'));
    }

    public function test_halt_partial_empty_and_final_batches_respect_live_state_and_capacity(): void
    {
        $run = $this->world(5); $items = $this->batch($run);
        DB::table('sim_runs')->where('id', $run->id)->update(['halt_requested_at' => now()]);
        $this->assertSame([], $this->batch($run), 'Even a stale running model must see the SQL halt gate.');
        $result = app(SimStipendBatch::class)->run($run, $items, $this->id(701));
        $this->assertSame(4, $result['released']); $this->noPayments();
        DB::table('sim_runs')->where('id', $run->id)->update(['halt_requested_at' => null]); $run->refresh();
        DB::table('residency_confirmations')->where('jurisdiction_id', $this->id(80))->update(['is_active' => false]);
        $this->assertSame(4, app(SimStipendBatch::class)->run($run, $this->batch($run), $this->id(701))['done']);
        $this->assertCount(1, $last = $this->batch($run));
        $this->assertSame(1, app(SimStipendBatch::class)->run($run, $last, $this->id(701))['done']);
        $this->assertSame(4, DB::table('ubi_disbursements')->count());
        $this->assertSame([], $this->batch($run));
        $this->assertSame(1, SimStipendBatch::capacity(0));
        $this->assertSame(2, SimStipendBatch::capacity(16 * 1048576));
        $this->assertSame(4, SimStipendBatch::capacity(PHP_INT_MAX));
    }

    private function child(string $mode, int $token): array
    {
        $process = proc_open([PHP_BINARY, base_path('tests/Support/stipend_batch_worker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $key = count($this->children); $this->children[$key] = [$process, $pipes];
        fwrite($pipes[0], json_encode(['connection' => DB::connection()->getConfig(), 'mode' => $mode,
            'run' => $this->id(700), 'token' => $this->id($token), 'currency' => $this->id(10), 'treasury' => $this->id(20)], JSON_THROW_ON_ERROR)."\n");
        stream_set_timeout($pipes[1], 12);
        $ready = json_decode(fgets($pipes[1]) ?: '{}', true);
        $this->assertArrayHasKey('pid', $ready);
        return [$key, $ready];
    }

    public function test_two_independent_batches_claim_disjoint_scopes_and_preserve_one_chain(): void
    {
        $this->world(8);
        // Separate connection holds only the append key, never the claim rows.
        config(['database.connections.batch_peer' => array_replace(DB::connection()->getConfig(), ['name' => 'batch_peer', 'url' => null])]);
        $peer = DB::connection('batch_peer'); $peer->beginTransaction();
        $peer->statement('SELECT pg_advisory_xact_lock(?)', [LedgerService::APPEND_LOCK_KEY]);
        [$a, $readyA] = $this->child('normal', 701);
        [$b, $readyB] = $this->child('normal', 702);
        $this->assertCount(8, array_unique([...$readyA['items'], ...$readyB['items']]));
        foreach ([$a, $b] as $key) { fwrite($this->children[$key][1][0], "GO\n"); }
        $deadline = microtime(true) + 8;
        do {
            DB::selectOne('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::table('pg_stat_activity')->whereIn('pid', [$readyA['pid'], $readyB['pid']])->where('wait_event', 'advisory')->count();
            if ($waiting === 2) { break; }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $peer->rollBack();
        $this->assertSame(2, $waiting);
        foreach ([$a, $b] as $key) {
            [$process, $pipes] = $this->children[$key];
            $result = json_decode(fgets($pipes[1]) ?: '{}', true);
            $this->assertSame(4, $result['done'] ?? null);
            fclose($pipes[0]); fclose($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors); unset($this->children[$key]);
        }
        $this->assertSame(8, $this->done());
        $this->assertSame(8, DB::table('ubi_disbursements')->count());
        $this->assertTrue(app(LedgerService::class)->verifyChain());
    }

    public function test_process_death_before_commit_rolls_back_and_reclaim_can_retry(): void
    {
        $this->crashAt('before');
    }

    public function test_process_death_after_commit_leaves_done_and_cannot_repay(): void
    {
        $this->crashAt('after');
    }

    private function crashAt(string $mode): void
    {
        $run = $this->world(); [$key] = $this->child($mode, 701);
        [$process, $pipes] = $this->children[$key]; fwrite($pipes[0], "GO\n");
        $this->assertSame($mode === 'before' ? "COMMITTING\n" : "COMMITTED\n", fgets($pipes[1]));
        proc_terminate($process, 9);
        foreach ($pipes as $pipe) { fclose($pipe); } proc_close($process); unset($this->children[$key]);
        DB::table('sim_worker_leases')->where('id', $this->id(701))->delete();
        (new \ReflectionMethod(SimPumpCommand::class, 'reclaim'))->invoke(app(SimPumpCommand::class), $run);
        if ($mode === 'before') {
            $this->noPayments();
            $this->assertSame(4, app(SimStipendBatch::class)->run($run, $this->batch($run, token: 702), $this->id(702))['done']);
        } else {
            $this->assertSame([], $this->batch($run, token: 702));
        }
        $this->assertSame(4, $this->done());
        $this->assertSame(4, DB::table('ubi_disbursements')->count());
        $this->assertTrue(app(LedgerService::class)->verifyChain());
    }

    public function test_real_worker_runs_partial_final_batch_and_leaves_no_leases_or_legacy_item_timers(): void
    {
        $run = $this->world(5);
        DB::statement('ALTER TABLE sim_worker_leases ADD COLUMN started_at timestamptz, ADD COLUMN claim_type text, ADD COLUMN claim_label text, ADD COLUMN claim_started_at timestamptz, ADD COLUMN activity text, ADD COLUMN activity_started_at timestamptz');
        DB::statement('CREATE TABLE sim_timings (run_id uuid, part text, count bigint, total_us bigint, max_us bigint, updated_at timestamptz, PRIMARY KEY(run_id,part))');
        DB::table('sim_worker_leases')->delete();
        Cache::setDefaultDriver('array');
        Artisan::shouldReceive('call')->with('sim:pump')->once()->andReturn(0);
        (new SimWorkerJob($run->id))->handle();
        $this->assertSame(5, $this->done());
        $this->assertSame(5, DB::table('ubi_disbursements')->count());
        $this->assertSame(0, DB::table('sim_worker_leases')->count());
        $this->assertSame(2, (int) DB::table('sim_timings')->where('part', 'stipend_batch.commit')->value('count'));
        $this->assertSame(5, (int) DB::table('sim_timings')->where('part', 'stipend_batch.scopes')->value('count'));
        $this->assertFalse(DB::table('sim_timings')->where('part', 'lane.item_total')->exists());
        $this->assertTrue(app(LedgerService::class)->verifyChain());
    }
}

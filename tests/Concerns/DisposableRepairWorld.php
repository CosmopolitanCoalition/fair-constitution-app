<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\{Artisan, DB};
use Symfony\Component\Process\Process;

/** Full installed schema in a nonce database; never connects models to the world. */
trait DisposableRepairWorld
{
    private static ?string $repairDatabase = null;
    private static array $repairAdminConfig = [];
    private string $repairOriginal;

    protected function openRepairWorld(): void
    {
        if (getenv('RUN_SIM_INDEX_PG_TESTS') !== '1') { $this->markTestSkipped('Disposable PostgreSQL fixture requires explicit opt-in.'); }
        $this->repairOriginal = DB::getDefaultConnection();
        $base = array_replace(config('database.connections.pgsql'), ['database' => 'postgres', 'url' => null]);
        config(['database.connections.repair_admin' => $base]);
        $admin = DB::connection('repair_admin');
        self::assertSame('postgres', $admin->selectOne('SELECT current_database() AS name')->name);
        self::$repairAdminConfig = $base;
        $new = self::$repairDatabase === null;
        if ($new) {
            self::$repairDatabase = 'repair_test_'.bin2hex(random_bytes(8));
            $admin->statement('CREATE DATABASE '.self::$repairDatabase.' TEMPLATE template0');
        }
        $fixture = array_replace($base, ['database' => self::$repairDatabase]);
        // Even an explicit pgsql model is fenced to the private fixture.
        DB::purge('pgsql'); config(['database.connections.pgsql' => $fixture, 'database.connections.repair_fixture' => $fixture]);
        DB::setDefaultConnection('repair_fixture');
        self::assertSame(self::$repairDatabase, DB::selectOne('SELECT current_database() AS name')->name);
        if ($new) {
            $process = new Process(['psql', '-X', '-v', 'ON_ERROR_STOP=1', '-h', $base['host'], '-p', (string) $base['port'],
                '-U', $base['username'], '-d', self::$repairDatabase, '-f', base_path('database/schema/pgsql-schema.sql')],
                null, ['PGPASSWORD' => $base['password']]);
            $process->setTimeout(120); $process->run();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            Artisan::call('migrate', ['--database' => 'repair_fixture', '--force' => true]);
        }
        DB::statement("SET lock_timeout = '5s'"); DB::statement("SET statement_timeout = '60s'");
        DB::beginTransaction();
    }

    protected function closeRepairWorld(): void
    {
        if (! isset($this->repairOriginal)) { return; }
        while (DB::transactionLevel() > 0) { DB::rollBack(); }
        DB::setDefaultConnection($this->repairOriginal); DB::purge('repair_fixture'); DB::purge('pgsql');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$repairDatabase !== null) {
            if (! preg_match('/^repair_test_[a-f0-9]{16}$/D', self::$repairDatabase)) { throw new \LogicException('Unsafe fixture name'); }
            $c = self::$repairAdminConfig;
            $pdo = new \PDO('pgsql:host='.$c['host'].';port='.$c['port'].';dbname=postgres', $c['username'], $c['password']);
            $pdo->exec('DROP DATABASE '.self::$repairDatabase.' WITH (FORCE)');
            self::$repairDatabase = null;
        }
        parent::tearDownAfterClass();
    }
}

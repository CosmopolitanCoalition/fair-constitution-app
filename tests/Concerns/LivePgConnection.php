<?php

namespace Tests\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Guarded live-pg connection helper (the CaseLifecycleTest posture): the default
 * test connection is sqlite:memory (no schema), so DB-touching constitutional
 * pins run against the real Postgres on a connection that is set as default and
 * always rolled back. SKIP when pg is unreachable.
 */
trait LivePgConnection
{
    protected function livePg(string $name): Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live pins run inside the app container.');
        }

        config([
            'database.connections.'.$name => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection($name);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. ('.$e->getMessage().')');
        }
    }
}

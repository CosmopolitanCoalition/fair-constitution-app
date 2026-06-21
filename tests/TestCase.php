<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the broker file-stores onto throwaway temp paths so NO test ever reads or (in teardown)
        // deletes an operator's REAL Cloudflare credential or delivered cert-grants. Without this, running
        // the suite against a live node would wipe its broker state. Per-process temp files; harmless if a
        // test never writes them.
        $tmp = sys_get_temp_dir().'/fc-test-'.getmypid();
        config([
            'cga.broker.credentials_path' => $tmp.'-broker-credentials.json',
            'cga.broker.received_grants_path' => $tmp.'-broker-grants.json',
        ]);
        // Start every test with an EMPTY broker store — the temp path is per-process, so without this a
        // credential/grant one test writes would leak into the next (e.g. flipping a broker.dns probe).
        @unlink($tmp.'-broker-credentials.json');
        @unlink($tmp.'-broker-grants.json');
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Ballots\BallotCrypto;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The lane-3 parity CLIs (ruling 10, the UI<->CLI parity order): engine:file,
 * elections:receipt-check, and ballot:receipt. These pin the command PLUMBING —
 * argument validation, error paths, and the local commitment crypto — none of
 * which touch the database, so they run on the default test connection.
 *
 * The engine/BallotBox guards that TRAVEL with these doors are pinned elsewhere
 * (the engine's own suites, BallotSecrecyTest); here we only prove the console
 * front doors validate and refuse cleanly.
 */
class ParityCliTest extends TestCase
{
    public function test_engine_file_rejects_a_malformed_payload(): void
    {
        $this->artisan('engine:file', ['form' => 'F-LEG-003', '--payload' => 'not-json'])
            ->expectsOutputToContain('JSON object')
            ->assertExitCode(1);
    }

    public function test_receipt_check_rejects_a_non_hash(): void
    {
        $this->artisan('elections:receipt-check', ['hash' => 'not-a-hash'])
            ->expectsOutputToContain('64 hexadecimal')
            ->assertExitCode(1);
    }

    public function test_ballot_commit_prints_a_salt_and_hash(): void
    {
        $this->artisan('ballot:receipt', [
            'mode' => 'commit',
            '--rankings' => Str::uuid().','.Str::uuid(),
        ])
            ->expectsOutputToContain('ballot_hash:')
            ->assertExitCode(0);
    }

    public function test_ballot_verify_round_trips_and_rejects_a_reorder(): void
    {
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $salt = BallotCrypto::newSaltHex();
        $hash = BallotCrypto::commitmentHash($salt, BallotCrypto::canonicalRankings([$a, $b]));

        // The intended ballot verifies.
        $this->artisan('ballot:receipt', [
            'mode' => 'verify', '--salt' => $salt, '--hash' => $hash, '--rankings' => "$a,$b",
        ])
            ->expectsOutputToContain('commits to exactly')
            ->assertExitCode(0);

        // A re-ordered ballot does NOT — the commitment binds the order.
        $this->artisan('ballot:receipt', [
            'mode' => 'verify', '--salt' => $salt, '--hash' => $hash, '--rankings' => "$b,$a",
        ])
            ->expectsOutputToContain('does not commit')
            ->assertExitCode(1);
    }

    public function test_ballot_commit_needs_a_ballot(): void
    {
        $this->artisan('ballot:receipt', ['mode' => 'commit'])
            ->expectsOutputToContain('--rankings')
            ->assertExitCode(1);
    }
}

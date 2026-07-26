<?php

namespace App\Console\Commands;

use App\Support\GameMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * launch:assert-clean — the machine-checkable form of "this instance carries no
 * fabricated consent, and nothing on it can fabricate any".
 *
 * The Standard instance's defining claim is REAL CONSENT ONLY: every resident, vote
 * and seated member on it belongs to a person who actually did that thing. That claim
 * is not self-evident from looking at the app — a demo world and a real one render
 * identically — so it needs an assertion that a human can run before launch and again
 * every night afterwards.
 *
 * Read-only. Never writes, never fixes; it reports and sets an exit code, so it is safe
 * to run against production and safe to wire into monitoring.
 *
 * Exit 0 = clean. Exit 1 = at least one hard failure. Exit 2 = could not complete
 * (no database, no world), which is NOT a pass and must not be read as one.
 */
class LaunchAssertCleanCommand extends Command
{
    protected $signature = 'launch:assert-clean
        {--json : machine-readable output for monitoring}
        {--allow-users=1 : how many real user accounts are expected (the founder); -1 to skip}';

    protected $description = 'Assert this instance carries no synthetic data and no dev controls (pre-launch + nightly gate)';

    /** Tables that must be EMPTY: every row in them represents a person's act. */
    private const CONSENT_TABLES = [
        'residency_confirmations' => 'a person confirmed as living somewhere',
        'residency_claims' => 'a residency claim',
        'constituent_consents' => 'a recorded consent',
        'standing_attestations' => 'an attestation of standing',
        'jurisdiction_activations' => 'a jurisdiction switched on by its residents',
        'legislature_members' => 'a seated representative',
        'executives' => 'an executive body',
        'executive_members' => 'a seated executive',
        'judiciaries' => 'a judiciary',
        'elections' => 'an election',
        'candidacies' => 'a candidacy',
        'ballots' => 'a ballot',
        'vote_casts' => 'a cast vote',
        'organizations' => 'an organization',
        'matrix_rooms' => 'a live chat room',
        'achievements' => 'an earned achievement',
        'public_records' => 'a published public record',
        'social_posts' => 'a post in the public square',
    ];

    private array $failures = [];

    private array $notes = [];

    private array $facts = [];

    public function handle(): int
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $this->error('Could not reach the database — cannot assert anything.');
            $this->line('  '.$e->getMessage());

            return 2;
        }

        $this->assertNoDevControls();
        $this->assertNotSandbox();
        $this->assertSovereign();
        $this->assertClocks();
        $this->assertNoSyntheticData();
        $this->assertInstitutionsDormant();
        $this->assertUsers();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'clean' => $this->failures === [],
                'failures' => $this->failures,
                'notes' => $this->notes,
                'facts' => $this->facts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->failures === [] ? 0 : 1;
        }

        return $this->report();
    }

    /**
     * The dev time + role controls can file ballots AS a real seated member. They are
     * already refused on a federated or peered node, but a public instance deployed with
     * the key left on is the one path by which a fabricated vote could enter a hash-chained
     * record that other nodes take on trust under Full Faith & Credit. Same family as the
     * public deploy path's refusal of --seed. (DEV_TIME_AND_ROLE_CONTROLS.md §4.)
     */
    private function assertNoDevControls(): void
    {
        if (config('cga.dev_time')) {
            $this->addFailure(
                'DEV TIME CONTROLS ARE ON (cga.dev_time / CGA_DEV_TIME).',
                'They can advance constitutional deadlines and file ballots as a seated member. '.
                'Set CGA_DEV_TIME=false in .env and restart the app + workers.'
            );
        }

        if (config('cga.impersonation')) {
            $this->addFailure(
                'IMPERSONATION IS ON (cga.impersonation).',
                'Anyone with operator access can act as another person. Set it false and restart.'
            );
        }

        if (config('app.debug')) {
            $this->addFailure(
                'APP_DEBUG is true.',
                'Stack traces and configuration leak to visitors. Set APP_DEBUG=false and restart.'
            );
        }
    }

    private function assertNotSandbox(): void
    {
        try {
            if (GameMode::isSandbox()) {
                $this->addFailure(
                    'This world is in SANDBOX mode.',
                    'The dev toolbox can manufacture roles and qualifications. A real instance must be founded in production mode.'
                );
            }
        } catch (\Throwable $e) {
            $this->note('Could not read the game mode: '.$e->getMessage());
        }
    }

    /**
     * A node that JOINED another world is a read-only mirror: it is authoritative for
     * nothing and carries no accounts of its own, so people cannot take part on it. That
     * is a legitimate way to run a node — but it is not what the front door should be, and
     * discovering it after launch is expensive.
     */
    private function assertSovereign(): void
    {
        if (! Schema::hasTable('instance_settings')) {
            $this->note('No instance_settings row — this world has not been founded yet.');

            return;
        }

        $settings = DB::table('instance_settings')->first();

        if ($settings === null) {
            $this->note('No instance_settings row — this world has not been founded yet.');

            return;
        }

        if (! empty($settings->mirror_of_server_id)) {
            $this->addFailure(
                'This node is a MIRROR of another world.',
                'It is authoritative for nothing and has no accounts of its own, so nobody can take part here. '.
                'The public front door must be a node that STARTED a world.'
            );
        }

        if (Schema::hasTable('jurisdictions')) {
            $owned = DB::table('jurisdictions')->whereNotNull('authoritative_server_id')->count();
            if ($owned > 0) {
                $this->addFailure(
                    "{$owned} jurisdictions are owned by another node.",
                    'This node cannot accept changes for them. Expected 0 on a sovereign instance.'
                );
            }
        }

        $this->facts['server_id'] = $settings->server_id ?? null;
    }

    /**
     * The 21-clock registry does not ride in the schema dump, so a bare `migrate` leaves it
     * empty — and federation:init throws without it, with an error that does not obviously
     * point here. Cheap to check, expensive to diagnose.
     */
    private function assertClocks(): void
    {
        if (! Schema::hasTable('clocks')) {
            $this->addFailure('The clocks table does not exist.', 'The schema is not applied.');

            return;
        }

        $count = DB::table('clocks')->count();
        $this->facts['clocks'] = $count;

        if ($count !== 21) {
            $this->addFailure(
                "The constitutional clock registry holds {$count} clocks, expected 21.",
                $count === 0
                    ? 'Run: php artisan db:seed --class=ClockRegistrySeeder --force'
                    : 'The registry has drifted from CLK-01…CLK-21 — investigate before launch.'
            );
        }
    }

    private function assertNoSyntheticData(): void
    {
        foreach (self::CONSENT_TABLES as $table => $what) {
            if (! Schema::hasTable($table)) {
                $this->note("Table {$table} does not exist — skipped.");

                continue;
            }

            $count = DB::table($table)->count();
            if ($count > 0) {
                $this->addFailure(
                    "{$table} holds {$count} rows — each is {$what}.",
                    'On a real instance every one of these must belong to a person who actually did it.'
                );
            }
        }
    }

    /**
     * Sizing a legislature is derivation from geography and is expected. SEATING one means
     * an election certified, which cannot have happened on a world nobody has used yet.
     */
    private function assertInstitutionsDormant(): void
    {
        if (! Schema::hasTable('legislatures')) {
            return;
        }

        $total = DB::table('legislatures')->count();
        $seated = DB::table('legislatures')->where('status', '<>', 'forming')->count();

        $this->facts['legislatures'] = $total;
        $this->facts['legislatures_not_forming'] = $seated;

        if ($seated > 0) {
            $this->addFailure(
                "{$seated} legislatures are past 'forming'.",
                'A legislature only leaves forming when an election certifies. Expected 0 before launch.'
            );
        }
    }

    private function assertUsers(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $allowed = (int) $this->option('allow-users');
        $count = DB::table('users')->count();
        $this->facts['users'] = $count;

        // Synthetic accounts are namespaced; a real instance must have none.
        $synthetic = DB::table('users')
            ->where(function ($q) {
                foreach (['%@demo.invalid', '%@example.com', '%@example.org', '%@test.test'] as $pattern) {
                    $q->orWhere('email', 'like', $pattern);
                }
            })
            ->count();

        if ($synthetic > 0) {
            $this->addFailure(
                "{$synthetic} user accounts use a demo or example address.",
                'These are generated accounts, not people.'
            );
        }

        if ($allowed >= 0 && $count > $allowed) {
            $this->note(
                "{$count} user accounts exist (expected about {$allowed}). ".
                'Real sign-ups are why this is a note, not a failure — confirm they are people.'
            );
        }
    }

    private function addFailure(string $what, string $sowhat): void
    {
        $this->failures[] = ['problem' => $what, 'what_to_do' => $sowhat];
    }

    private function note(string $text): void
    {
        $this->notes[] = $text;
    }

    private function report(): int
    {
        $this->newLine();

        foreach ($this->facts as $k => $v) {
            $this->line(sprintf('  %-26s %s', $k, $v ?? '—'));
        }

        if ($this->notes !== []) {
            $this->newLine();
            foreach ($this->notes as $n) {
                $this->line("  note: {$n}");
            }
        }

        $this->newLine();

        if ($this->failures === []) {
            $this->info('✓ CLEAN — no synthetic data, no dev controls, sovereign, clocks seeded.');
            $this->line('  This instance can carry real consent.');
            $this->newLine();

            return 0;
        }

        $this->error('✗ NOT CLEAN — '.count($this->failures).' problem(s). This instance must not take real users yet.');
        $this->newLine();

        foreach ($this->failures as $i => $f) {
            $this->line('  '.($i + 1).'. '.$f['problem']);
            $this->line('     → '.$f['what_to_do']);
        }

        $this->newLine();

        return 1;
    }
}

<?php

namespace Tests\Constitutional;

use App\Domain\Engine\ConstitutionalViolation;
use App\Models\Economy\Currency;
use App\Models\Jurisdiction;
use App\Services\Economy\CurrencyService;
use App\Services\Economy\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Concerns\LivePgConnection;
use Tests\TestCase;

/**
 * Phase L slice L-2 — the constitutional pins on the public ledger.
 *
 * Three properties this suite exists to make non-negotiable:
 *   1. Value is conserved  — Σdebits = Σcredits per currency, per posting.
 *   2. The past is fixed   — ledger_entries is append-only at the DB level.
 *   3. There is one door   — LedgerService is the ONLY writer, source-scanned
 *                            for WRITE constructs (reads are lawful: the
 *                            ledger is public).
 *
 * Plus Art. V §5: currency is reserved to the most encompassing jurisdiction.
 */
class LedgerIntegrityTest extends TestCase
{
    use LivePgConnection;

    private const LIVE_CONNECTION = 'ledger_live_pg';

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = app(LedgerService::class);
    }

    // ─── 3. One door — no live stack needed ──────────────────────────────

    /**
     * The whole audit value of a public ledger rests on there being no side
     * door. Mirrors CgcIpPublicDomainTest's source scan of the public-domain
     * register: if a second writer ever appears, this fails loudly rather
     * than the ledger quietly ceasing to balance.
     *
     * The pin is on WRITES. Reads are not merely tolerated — the ledger is
     * PUBLIC by design (reader privacy is accounts-never-people, not a hidden
     * ledger), so the public ledger page and the demo command's integrity
     * report lawfully read this table. An earlier version of this scan
     * flagged any file merely MENTIONING `ledger_entries`, which flagged
     * both of those readers and would have forbidden the public ledger
     * surface from existing. The one-door law it was written to enforce
     * says "no second WRITER" — so the scan now matches write constructs.
     *
     * Three layers hold the door, and this scan is only the first:
     *   1. this CI scan   — write-shaped source flagged before it ships;
     *   2. the DB trigger — UPDATE/DELETE/TRUNCATE physically impossible;
     *   3. the hash chain — a side-door INSERT breaks verifyChain(), so it
     *      cannot stay hidden even if the scan misses it.
     * Named blind spot: table-name indirection (`DB::table($var)`) is
     * invisible to any source scan — layer 3 is the answer there.
     */
    public function test_ledger_service_is_the_only_writer(): void
    {
        $allowed = [
            'app/Services/Economy/LedgerService.php',
        ];

        $offenders = [];

        foreach ([app_path(), database_path(), base_path('routes')] as $root) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));

                if (in_array($relative, $allowed, true)) {
                    continue;
                }

                $hits = self::ledgerWriteConstructs(file_get_contents($file->getPathname()));

                if ($hits !== []) {
                    $offenders[] = $relative . ' [' . implode('; ', $hits) . ']';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ledger_entries must be written ONLY through LedgerService. Offending files: '
                . implode(', ', $offenders)
        );
    }

    /**
     * The matcher itself must be shown to bite — a green scan that has never
     * been seen failing carries no information. Every write shape the scan
     * exists to catch is fed to it here and must be flagged.
     */
    public function test_the_write_scan_flags_every_write_shape(): void
    {
        $writes = [
            'chained insert'        => "DB::table('ledger_entries')->insert(['a' => 1]);",
            'chained multiline'     => "DB::table('ledger_entries')\n    ->where('seq', 1)\n    ->update(['amount' => 0]);",
            'chained delete'        => "DB::table(\"ledger_entries\")->where('seq', 1)->delete();",
            'chained upsert'        => "DB::table('ledger_entries')->upsert([], []);",
            'chained increment'     => "DB::table('ledger_entries')->increment('amount');",
            'captured builder'      => "\$q = DB::table('ledger_entries');\n\$q->where('seq', 1);\n\$q->insert(['a' => 1]);",
            'raw INSERT'            => "DB::statement('INSERT INTO ledger_entries (id) VALUES (?)', [\$id]);",
            'raw UPDATE'            => "DB::statement('UPDATE ledger_entries SET amount = 0');",
            'raw DELETE'            => "DB::statement('DELETE FROM ledger_entries WHERE seq = 1');",
            'raw TRUNCATE'          => "DB::statement('TRUNCATE TABLE ledger_entries');",
            'eloquent create'       => 'LedgerEntry::create([]);',
            'eloquent firstOrCreate' => 'LedgerEntry::firstOrCreate([]);',
            'model instantiation'   => 'return (new LedgerEntry())->fill([]);',
        ];

        foreach ($writes as $label => $source) {
            $this->assertNotSame(
                [],
                self::ledgerWriteConstructs($source),
                "the scan must flag: {$label}"
            );
        }
    }

    /**
     * And it must NOT bite the lawful shapes — the exact reads the public
     * ledger surface and the demo integrity report perform, the DDL the
     * ledger-plane migration performs, and mentions in comments. These are
     * the false positives the string-presence version of this scan produced.
     */
    public function test_the_write_scan_passes_every_lawful_shape(): void
    {
        $reads = [
            'count read'          => "DB::table('ledger_entries')->count();",
            'tail read'           => "DB::table('ledger_entries')->orderByDesc('seq')->limit(50)->get();",
            'captured reader'     => "\$q = DB::table('ledger_entries')->orderBy('seq');\nreturn \$q->cursor();",
            'raw SELECT'          => "DB::selectOne('SELECT hash FROM ledger_entries ORDER BY seq DESC LIMIT 1');",
            'aggregate SELECT'    => "DB::select('SELECT currency_id, SUM(amount) FROM ledger_entries GROUP BY currency_id');",
            'comment mention'     => "// ledger_entries and issuance_events are append-only by trigger\n\$this->teardown();",
            'eloquent read'       => "LedgerEntry::where('seq', 1)->get();",
            'model table prop'    => "protected \$table = 'ledger_entries';",
            'DDL create'          => "Schema::create('ledger_entries', function (Blueprint \$table) {});",
            'DDL alter'           => "DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT c CHECK (amount > 0)');",
            'DDL trigger'         => "DB::statement('CREATE TRIGGER ledger_entries_immutable BEFORE DELETE OR UPDATE ON ledger_entries FOR EACH ROW EXECUTE FUNCTION f()');",
            'DDL truncate guard'  => "DB::statement('CREATE TRIGGER ledger_entries_no_truncate BEFORE TRUNCATE ON ledger_entries FOR EACH STATEMENT EXECUTE FUNCTION f()');",
            'DDL drop (rollback)' => "Schema::dropIfExists('ledger_entries');",
        ];

        foreach ($reads as $label => $source) {
            $this->assertSame(
                [],
                self::ledgerWriteConstructs($source),
                "the scan must NOT flag: {$label}"
            );
        }
    }

    /**
     * Write-construct matcher for the one-door scan. Returns a label per
     * matched construct so an offender report says WHAT it saw, not just
     * where.
     */
    private static function ledgerWriteConstructs(string $source): array
    {
        $found = [];
        $writeVerbs = 'insert|insertGetId|insertOrIgnore|upsert|update|updateOrInsert|increment|decrement|delete|truncate';

        // 1. A write chained onto the builder within one statement.
        if (preg_match(
            "/DB::table\\(\\s*['\"]ledger_entries['\"]\\s*\\)[^;]*?->\\s*({$writeVerbs})\\s*\\(/s",
            $source,
            $m
        )) {
            $found[] = "builder->{$m[1]}()";
        }

        // 2. The builder captured into a variable and written through later —
        //    the shape a single-expression regex cannot see.
        if (preg_match_all("/\\\$(\\w+)\\s*=\\s*DB::table\\(\\s*['\"]ledger_entries['\"]\\s*\\)/", $source, $vars)) {
            foreach (array_unique($vars[1]) as $var) {
                if (preg_match(
                    '/\$' . preg_quote($var, '/') . "\\s*->\\s*({$writeVerbs})\\s*\\(/",
                    $source,
                    $m
                )) {
                    $found[] = "captured builder \${$var}->{$m[1]}()";
                }
            }
        }

        // 3. Raw SQL DML. Deliberately NOT bare table-name presence: the
        //    ledger-plane migration lawfully says `ALTER TABLE ledger_entries`
        //    and `BEFORE DELETE OR UPDATE ON ledger_entries` (DDL), and
        //    LedgerService lawfully SELECTs. Only DML verbs directly against
        //    the table are writes.
        if (preg_match(
            '/\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+(?:ONLY\s+)?"?ledger_entries"?/i',
            $source,
            $m
        )) {
            $found[] = 'raw SQL ' . strtoupper(preg_replace('/\s+/', ' ', $m[1]));
        }

        // 4. Eloquent-side writes on the read-only model.
        if (preg_match(
            '/LedgerEntry::(create|forceCreate|insert|insertGetId|insertOrIgnore|upsert|updateOrCreate|updateOrInsert|firstOrCreate|firstOrNew|destroy|truncate)\b/',
            $source,
            $m
        )) {
            $found[] = "LedgerEntry::{$m[1]}()";
        }
        if (preg_match('/new\s+LedgerEntry\b/', $source)) {
            $found[] = 'new LedgerEntry (instantiation implies a save path)';
        }

        return $found;
    }

    public function test_direction_constants_are_pinned(): void
    {
        $this->assertSame('debit', LedgerService::DIRECTION_DEBIT);
        $this->assertSame('credit', LedgerService::DIRECTION_CREDIT);
    }

    // ─── 1. Value is conserved ───────────────────────────────────────────

    public function test_an_unbalanced_posting_is_rejected(): void
    {
        $cid = (string) Str::uuid();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unbalanced posting/');

        $this->ledger->post('transfer', [
            $this->leg($cid, 'debit', '100'),
            $this->leg($cid, 'credit', '90'),
        ]);
    }

    public function test_a_posting_must_balance_within_each_currency_separately(): void
    {
        // Two currencies that balance only when summed together is NOT
        // balanced — you cannot settle a debt in one money with another.
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();

        $this->expectException(InvalidArgumentException::class);

        $this->ledger->post('transfer', [
            $this->leg($a, 'debit', '100'),
            $this->leg($b, 'credit', '100'),
        ]);
    }

    public function test_a_zero_or_negative_leg_is_rejected(): void
    {
        $cid = (string) Str::uuid();

        foreach (['0', '-5'] as $bad) {
            try {
                $this->ledger->post('transfer', [
                    $this->leg($cid, 'debit', $bad),
                    $this->leg($cid, 'credit', $bad),
                ]);
                $this->fail("amount {$bad} must be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('greater than zero', $e->getMessage());
            }
        }
    }

    public function test_an_unknown_direction_is_rejected(): void
    {
        $cid = (string) Str::uuid();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be debit or credit/');

        $this->ledger->post('transfer', [
            $this->leg($cid, 'sideways', '10'),
            $this->leg($cid, 'credit', '10'),
        ]);
    }

    // ─── Art. V §5 — currency is root-reserved ───────────────────────────

    public function test_a_non_root_jurisdiction_may_not_issue_currency(): void
    {
        $child = new Jurisdiction(['slug' => 'a-province']);
        $child->parent_id = (string) Str::uuid();

        try {
            app(CurrencyService::class)->assertRoot($child);
            $this->fail('a jurisdiction with a parent must not issue currency');
        } catch (ConstitutionalViolation $e) {
            $this->assertSame('Art. V §5', $e->citation);
            $this->assertStringContainsString('most encompassing', $e->getMessage());
        }
    }

    public function test_the_root_may_issue_currency(): void
    {
        $root = new Jurisdiction(['slug' => 'earth-0-earth']);
        $root->parent_id = null;

        app(CurrencyService::class)->assertRoot($root);
        $this->assertTrue(true, 'the root issues without objection');
    }

    public function test_currency_unit_kinds_are_agnostic(): void
    {
        // The engine takes no position on what money IS — that is a
        // legislature's question. All five kinds must remain available.
        foreach ([
            Currency::KIND_ABSTRACT,
            Currency::KIND_FIAT,
            Currency::KIND_COMMODITY,
            Currency::KIND_SOCIAL_CREDIT,
            Currency::KIND_EXTERNAL_PEG,
        ] as $kind) {
            $this->assertIsString($kind);
        }
    }

    // ─── Live-stack pins ─────────────────────────────────────────────────

    public function test_a_balanced_posting_lands_and_the_chain_verifies(): void
    {
        $this->onLivePg(function () {
            [$currencyId, $from, $to] = $this->fixture();

            $group = $this->ledger->post('transfer', [
                $this->leg($currencyId, 'debit', '25.5', $from),
                $this->leg($currencyId, 'credit', '25.5', $to),
            ]);

            $this->assertCount(2, DB::table('ledger_entries')->where('entry_group', $group)->get());

            $this->assertTrue(
                $this->ledger->verifyChain(),
                'the ledger hash chain must verify after a posting'
            );

            // Balances moved, and moved opposite ways.
            $this->assertSame(0, bccomp((string) DB::table('treasury_accounts')->where('id', $from)->value('balance'), '-25.5', 6));
            $this->assertSame(0, bccomp((string) DB::table('treasury_accounts')->where('id', $to)->value('balance'), '25.5', 6));

            // This currency nets to zero across the whole ledger.
            $imbalance = $this->ledger->imbalanceByCurrency();
            $this->assertSame(0, bccomp($imbalance[$currencyId] ?? '0', '0', 6));
        });
    }

    /** 2. The past is fixed — enforced by the database, not by manners. */
    public function test_a_ledger_entry_can_never_be_updated_or_deleted(): void
    {
        $this->onLivePg(function () {
            [$currencyId, $from, $to] = $this->fixture();

            $group = $this->ledger->post('transfer', [
                $this->leg($currencyId, 'debit', '10', $from),
                $this->leg($currencyId, 'credit', '10', $to),
            ]);

            $seq = DB::table('ledger_entries')->where('entry_group', $group)->value('seq');

            // Each attempt gets its own SAVEPOINT: the trigger raises, which
            // aborts the enclosing postgres transaction, so without one the
            // second statement would fail with "current transaction is
            // aborted" instead of reporting the trigger's own refusal.
            foreach ([
                ['UPDATE ledger_entries SET amount = 1 WHERE seq = ?', 'updatable'],
                ['DELETE FROM ledger_entries WHERE seq = ?', 'deletable'],
            ] as $i => [$sql, $what]) {
                $sp = "sp_immutable_{$i}";
                DB::statement("SAVEPOINT {$sp}");

                try {
                    DB::statement($sql, [$seq]);
                    $this->fail("a ledger entry must not be {$what}");
                } catch (\Throwable $e) {
                    DB::statement("ROLLBACK TO SAVEPOINT {$sp}");
                    $this->assertStringContainsString('append-only', $e->getMessage());
                }
            }
        });
    }

    /**
     * Run a body against the real Postgres on a guarded connection that is
     * always rolled back — the house posture for DB-touching constitutional
     * pins (CaseLifecycleTest / Art4Section5Test).
     */
    private function onLivePg(callable $body): void
    {
        $conn = $this->livePg(self::LIVE_CONNECTION);

        $original = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);
        $conn->beginTransaction();

        try {
            $body();
        } finally {
            $conn->rollBack();
            DB::setDefaultConnection($original);
        }
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /** @return array{0:string,1:string,2:string} currency, from-account, to-account */
    private function fixture(): array
    {
        $root = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->first();

        if ($root === null) {
            $this->markTestSkipped('no root jurisdiction on this box — Station 0 fixture required');
        }

        $currencyId = (string) Str::uuid();
        DB::table('currencies')->insert([
            'id'              => $currencyId,
            'jurisdiction_id' => $root->id,
            'name'            => 'Test Unit',
            'code'            => 'T' . substr((string) Str::uuid(), 0, 6),
            'symbol'          => '¤',
            'precision'       => 2,
            'unit_kind'       => Currency::KIND_ABSTRACT,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // NOTE the distinct owner_id per account: treasury_accounts carries a
        // unique index on (owner_type, owner_id, currency_id) — one account
        // per owner per currency, which is the right rule and which an
        // earlier version of this fixture violated by giving both accounts
        // the root as owner.
        $ids = [];
        foreach ([$root->id, (string) Str::uuid()] as $i => $ownerId) {
            $id = (string) Str::uuid();
            DB::table('treasury_accounts')->insert([
                'id'          => $id,
                'owner_type'  => 'jurisdictions',
                'owner_id'    => $ownerId,
                'currency_id' => $currencyId,
                'balance'     => 0,
                'public'      => true,
                'label'       => 'test-' . ($i === 0 ? 'from' : 'to'),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $ids[] = $id;
        }

        return [$currencyId, $ids[0], $ids[1]];
    }

    /** @return array<string, mixed> */
    private function leg(string $currencyId, string $direction, string $amount, ?string $accountId = null): array
    {
        return [
            'account_type' => 'treasury_accounts',
            'account_id'   => $accountId ?? (string) Str::uuid(),
            'currency_id'  => $currencyId,
            'direction'    => $direction,
            'amount'       => $amount,
        ];
    }
}

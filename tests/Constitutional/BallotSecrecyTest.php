<?php

namespace Tests\Constitutional;

use App\Domain\Ballots\BallotBox;
use App\Domain\Ballots\BallotCrypto;
use App\Domain\Ballots\BallotReceipt;
use App\Domain\Ballots\BallotReceiptHolder;
use App\Domain\Ballots\DoubleVoteException;
use App\Domain\Ballots\EngineBallotBox;
use App\Domain\Engine\ConstitutionalViolation;
use App\Jobs\PublishBallotHashesJob;
use App\Models\Ballot;
use App\Models\BallotEnvelope;
use App\Models\Election;
use App\Models\ElectionRace;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * CONSTITUTIONAL PIN — Art. II (ballot secrecy): cryptographic separation
 * of voter identity from ballot. Replaces the Phase B placeholder
 * `test_ballot_envelope_never_links_to_ballot`.
 *
 * Three layers, matching design §B.5:
 *
 *  1. PURE crypto pins (no DB, always run): commitment hash + salted
 *     receipt verification, secretbox round trips, key wrap/unwrap under
 *     the app-key-derived KEK, canonical-ranking validation, publication
 *     root hash — and a source-grep proving BallotBox is the ONLY writer
 *     of the two secrecy tables.
 *
 *  2. LIVE SCHEMA pins (read-only information_schema queries against the
 *     real PostgreSQL — the phpunit sqlite :memory: connection has no
 *     schema, and RefreshDatabase is forbidden on the live dev DB, so a
 *     dedicated guarded pg connection is used; tests SKIP when pg is
 *     unreachable, e.g. outside the app container): the `ballots` column
 *     list contains nothing voter- or timestamp-shaped; no FK path exists
 *     between ballot_envelopes and ballots in either direction; nothing
 *     anywhere references ballots; ballots.id defaults to gen_random_uuid.
 *
 *  3. LIVE E2E pin (same guarded pg connection, every write inside one
 *     transaction that is ALWAYS rolled back — zero residue): commit →
 *     receipt verifies → double-vote rejected by the DB unique → decrypt
 *     round-trips → commit-time audit entries carry participation but
 *     never a hash, salt, or ranking → publication appends the sorted
 *     list + root → anchored chain verification stays green.
 *
 * If an edit breaks these tests, that edit is a constitutional violation —
 * fix the edit, never the test.
 */
class BallotSecrecyTest extends TestCase
{
    private const LIVE_CONNECTION = 'pgsql_ballot_secrecy';

    /** Columns `ballots` must have — and may ONLY have (design §A B-7). */
    private const BALLOT_COLUMNS = [
        'id',
        'race_id',
        'kind',
        'payload_encrypted',
        'salt',
        'ballot_hash',
        'cast_bucket',
        'counted',
    ];

    // ======================================================================
    // 1. Pure crypto pins (no DB)
    // ======================================================================

    public function test_receipt_commitment_verifies_and_binds_rankings_and_salt(): void
    {
        $rankings = [$this->uuid(), $this->uuid(), $this->uuid()];
        $salt     = BallotCrypto::newSaltHex();
        $hash     = BallotCrypto::commitmentHash($salt, BallotCrypto::canonicalRankings($rankings));

        $receipt = new BallotReceipt($hash, $salt);

        $this->assertTrue($receipt->verifies($rankings), 'Receipt must verify its own rankings.');

        // Any change to the rankings breaks the commitment …
        $this->assertFalse($receipt->verifies(array_reverse($rankings)), 'Order is part of the ballot.');
        $this->assertFalse($receipt->verifies([$rankings[0], $rankings[1]]), 'Truncation must not verify.');
        $this->assertFalse($receipt->verifies([$this->uuid()]), 'Different rankings must not verify.');

        // … and so does a different salt (the anti-brute-force ingredient).
        $other = new BallotReceipt($hash, BallotCrypto::newSaltHex());
        $this->assertFalse($other->verifies($rankings), 'A foreign salt must not verify.');
    }

    public function test_payload_encryption_round_trips_and_rejects_wrong_key(): void
    {
        $rankings  = [$this->uuid(), $this->uuid()];
        $canonical = BallotCrypto::canonicalRankings($rankings);
        $key       = BallotCrypto::generateDataKey();

        $sealed = BallotCrypto::encryptCanonical($canonical, $key);

        $this->assertNotSame($canonical, $sealed);
        $this->assertStringNotContainsString($rankings[0], $sealed, 'Ciphertext must not leak candidacy ids.');
        $this->assertSame($canonical, BallotCrypto::decryptToCanonical($sealed, $key));
        $this->assertSame($rankings, BallotCrypto::decryptRankings($sealed, $key));

        $this->expectException(RuntimeException::class);
        BallotCrypto::decryptToCanonical($sealed, BallotCrypto::generateDataKey());
    }

    public function test_key_wrap_round_trips_under_app_key_and_rejects_foreign_app_key(): void
    {
        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $kek    = BallotCrypto::kekFromAppKey($appKey);

        $dataKey = BallotCrypto::generateDataKey();
        $wrapped = BallotCrypto::wrapDataKey($dataKey, $kek);

        $this->assertSame($dataKey, BallotCrypto::unwrapDataKey($wrapped, $kek));
        $this->assertStringNotContainsString(base64_encode($dataKey), $wrapped, 'Wrapped form must not embed the raw key.');

        // KEK derivation is domain-separated — never the raw app key itself.
        $this->assertNotSame(base64_decode(substr($appKey, 7)), $kek);

        $this->expectException(RuntimeException::class);
        BallotCrypto::unwrapDataKey($wrapped, BallotCrypto::kekFromAppKey('base64:' . base64_encode(random_bytes(32))));
    }

    public function test_canonical_rankings_validation(): void
    {
        $a = $this->uuid();
        $b = $this->uuid();

        // Order is preserved (and meaningful).
        $this->assertNotSame(
            BallotCrypto::canonicalRankings([$a, $b]),
            BallotCrypto::canonicalRankings([$b, $a])
        );

        // Case-insensitive duplicate = same candidacy twice.
        try {
            BallotCrypto::canonicalRankings([$a, strtoupper($a)]);
            $this->fail('Repeated candidacy must be rejected.');
        } catch (InvalidArgumentException) {
        }

        foreach ([[], ['not-a-uuid'], [$a, 42], ['x' => $a]] as $bad) {
            try {
                BallotCrypto::canonicalRankings($bad);
                $this->fail('Malformed rankings must be rejected: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function test_publication_root_hash_is_canonical_over_the_sorted_list(): void
    {
        $hashes = [hash('sha256', 'a'), hash('sha256', 'b'), hash('sha256', 'c')];

        $root = PublishBallotHashesJob::rootHash($hashes);

        // Input order never matters — the root is over the SORTED list.
        $this->assertSame($root, PublishBallotHashesJob::rootHash(array_reverse($hashes)));

        // Any membership change changes the root.
        $this->assertNotSame($root, PublishBallotHashesJob::rootHash(array_slice($hashes, 0, 2)));
        $this->assertNotSame($root, PublishBallotHashesJob::rootHash([...$hashes, hash('sha256', 'd')]));

        sort($hashes, SORT_STRING);
        $this->assertSame(hash('sha256', implode('', $hashes)), $root);
    }

    /**
     * Design §B.5.1: BallotBox is the ONLY writer of the secrecy tables.
     * Greps app/ for any other code unit that creates/inserts into
     * `ballots` or `ballot_envelopes`.
     */
    public function test_ballot_box_is_the_only_writer_of_the_secrecy_tables(): void
    {
        $allowed = str_replace('\\', '/', app_path('Domain/Ballots/BallotBox.php'));

        $rogue = [];

        $patterns = [
            'Ballot::create',
            'Ballot::insert',
            'Ballot::forceCreate',
            'Ballot::upsert',
            'BallotEnvelope::create',
            'BallotEnvelope::insert',
            'BallotEnvelope::forceCreate',
            'BallotEnvelope::upsert',
            "table('ballots')",
            'table("ballots")',
            "table('ballot_envelopes')",
            'table("ballot_envelopes")',
            '->ballots()->create',
            '->ballots()->insert',
            '->envelopes()->create',
            '->envelopes()->insert',
            'new Ballot(',
            'new BallotEnvelope(',
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if ($path === $allowed) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            foreach ($patterns as $pattern) {
                if (str_contains($source, $pattern)) {
                    $rogue[] = "{$path} contains \"{$pattern}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $rogue,
            "Rogue secrecy-table writers found (only BallotBox may write ballots/ballot_envelopes):\n" . implode("\n", $rogue)
        );
    }

    // ======================================================================
    // 2. Live schema pins (read-only; skipped when pg unreachable)
    // ======================================================================

    public function test_ballots_table_has_no_voter_or_timestamp_columns(): void
    {
        $pg = $this->livePg();

        $columns = array_map(
            fn ($row) => $row->column_name,
            $pg->select(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'ballots'"
            )
        );

        // The EXACT anonymous column set — nothing more, nothing less.
        $this->assertEqualsCanonicalizing(self::BALLOT_COLUMNS, $columns);

        // Belt and suspenders: nothing voter- or wall-clock-shaped.
        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/user|voter|envelope|identity|created_at|updated_at|deleted_at|ip_|session/i',
                $column,
                "ballots.{$column} is voter- or timestamp-shaped — Art. II violation."
            );
        }

        // The only time on the table is the hour bucket.
        $timeColumns = $pg->select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'ballots'
               AND data_type LIKE 'timestamp%'"
        );
        $this->assertSame(['cast_bucket'], array_map(fn ($r) => $r->column_name, $timeColumns));

        // Random PK — no sequence, no ordered uuid default (design B-7).
        $default = $pg->selectOne(
            "SELECT column_default FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'ballots' AND column_name = 'id'"
        );
        $this->assertStringContainsString('gen_random_uuid', (string) $default->column_default);
    }

    public function test_no_fk_or_column_path_between_envelopes_and_ballots(): void
    {
        $pg = $this->livePg();

        $fks = $pg->select(
            "SELECT tc.table_name AS from_table, ccu.table_name AS to_table
             FROM information_schema.table_constraints tc
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name
              AND ccu.constraint_schema = tc.constraint_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_schema = 'public'
               AND (tc.table_name IN ('ballots', 'ballot_envelopes')
                    OR ccu.table_name IN ('ballots', 'ballot_envelopes'))"
        );

        foreach ($fks as $fk) {
            // Neither table may reference the other, in either direction.
            $this->assertFalse(
                in_array($fk->from_table, ['ballots', 'ballot_envelopes'], true)
                    && in_array($fk->to_table, ['ballots', 'ballot_envelopes'], true)
                    && $fk->from_table !== $fk->to_table,
                "FK path {$fk->from_table} → {$fk->to_table} links the secrecy tables."
            );

            // Nothing anywhere references ballots (no re-linking side table).
            $this->assertNotSame('ballots', $fk->to_table, "{$fk->from_table} references ballots — re-linking path.");

            if ($fk->from_table === 'ballots') {
                $this->assertSame(
                    'election_races',
                    $fk->to_table,
                    'ballots may only reference election_races — nothing voter-shaped.'
                );
            }
        }

        // Envelope side: no content/commitment columns.
        $envelopeColumns = array_map(
            fn ($row) => $row->column_name,
            $pg->select(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'ballot_envelopes'"
            )
        );

        foreach ($envelopeColumns as $column) {
            $this->assertDoesNotMatchRegularExpression(
                '/hash|salt|payload|receipt|ballot_id|content|ranking/i',
                $column,
                "ballot_envelopes.{$column} is content-shaped — nothing on the envelope may reach the ballot."
            );
        }

        // No shared join column beyond the legitimate race/kind pair.
        $shared = array_intersect($envelopeColumns, self::BALLOT_COLUMNS);
        $this->assertEqualsCanonicalizing(['id', 'race_id', 'kind'], array_values($shared));
    }

    // ======================================================================
    // 3. Live end-to-end pin (one transaction, ALWAYS rolled back)
    // ======================================================================

    public function test_commit_decrypt_double_vote_audit_discipline_and_publication(): void
    {
        $conn = $this->livePg();

        $originalDefault = DB::getDefaultConnection();
        DB::setDefaultConnection(self::LIVE_CONNECTION);

        $conn->beginTransaction();

        try {
            $jurisdictionId = $conn->table('jurisdictions')->whereNull('deleted_at')->value('id');
            $this->assertNotNull($jurisdictionId, 'Live DB has no jurisdictions — seed it first.');

            $seqBefore = app(AuditService::class)->latestSeq();

            // ── Throwaway election + race + voters (rolled back below) ────
            $election = Election::create([
                'jurisdiction_id' => $jurisdictionId,
                'kind'            => Election::KIND_GENERAL,
                'status'          => Election::STATUS_RANKED_OPEN,
                'voting_method'   => 'stv_droop',
            ]);

            $race = ElectionRace::create([
                'election_id'     => $election->id,
                'jurisdiction_id' => $jurisdictionId,
                'seat_kind'       => ElectionRace::SEAT_KIND_TYPE_A,
                'seats'           => 5,
                'finalist_count'  => 15,
                'status'          => Election::STATUS_RANKED_OPEN,
            ]);

            $voters = [];
            for ($i = 0; $i < 3; $i++) {
                $voters[] = User::create([
                    'name'              => "Ballot Secrecy Throwaway {$i}",
                    'email'             => 'ballot-secrecy-' . Str::uuid() . '@test.invalid',
                    'password'          => Str::random(32),
                    'terms_accepted_at' => now(),
                ]);
            }

            $candidacies = [$this->uuid(), $this->uuid(), $this->uuid(), $this->uuid(), $this->uuid()];

            $rankingSets = [
                [$candidacies[0], $candidacies[1], $candidacies[2]],
                [$candidacies[2], $candidacies[3]],
                [$candidacies[4], $candidacies[0], $candidacies[3], $candidacies[1]],
            ];

            // ── Commit 3 ballots ──────────────────────────────────────────
            $box = app(BallotBox::class);

            /** @var BallotReceipt[] $receipts */
            $receipts = [];
            foreach ($rankingSets as $i => $rankings) {
                $receipts[$i] = $box->commit($voters[$i], $race, $rankings);
            }

            $this->assertSame(3, BallotEnvelope::query()->where('race_id', $race->id)->count());
            $this->assertSame(3, Ballot::query()->where('race_id', $race->id)->count());

            // Receipts verify their own rankings, and only their own.
            foreach ($receipts as $i => $receipt) {
                $this->assertTrue($receipt->verifies($rankingSets[$i]));
                $this->assertFalse($receipt->verifies($rankingSets[($i + 1) % 3]));
            }

            // Ballot rows: hour-truncated bucket, random v4 ids.
            foreach (Ballot::query()->where('race_id', $race->id)->get() as $ballot) {
                $this->assertSame('00:00', $ballot->cast_bucket->format('i:s'), 'cast_bucket must be hour-truncated.');
                $this->assertSame('4', $ballot->id[14], 'ballot id must be a RANDOM v4 uuid (ordered uuids encode time).');
            }

            // ── Double vote: rejected by the DB unique, nothing written ───
            try {
                $box->commit($voters[0], $race, [$candidacies[1]]);
                $this->fail('Second ballot by the same voter must raise DoubleVoteException.');
            } catch (DoubleVoteException $e) {
                $this->assertSame('Art. II §2', $e->citation);
            }

            $this->assertSame(3, BallotEnvelope::query()->where('race_id', $race->id)->count());
            $this->assertSame(3, Ballot::query()->where('race_id', $race->id)->count());

            // ── Decrypt round trip: same multiset of rankings ─────────────
            $decrypted = iterator_to_array($box->decryptForCount($race), false);

            $canonicalize = function (array $sets): array {
                $encoded = array_map(fn ($r) => json_encode($r), $sets);
                sort($encoded, SORT_STRING);

                return $encoded;
            };

            $this->assertSame($canonicalize($rankingSets), $canonicalize($decrypted));

            // ── Commit-time audit discipline (§B.5.6) ─────────────────────
            $entries = $conn->table('audit_log')
                ->where('seq', '>', $seqBefore)
                ->orderBy('seq')
                ->get();

            $commitEntries = $entries->where('event', 'ballot.committed');
            $this->assertCount(3, $commitEntries, 'One participation entry per commit.');

            foreach ($commitEntries as $entry) {
                $payload = json_decode($entry->payload, true);
                $this->assertEqualsCanonicalizing(
                    ['race_id', 'envelope_id'],
                    array_keys($payload),
                    'Commit entries carry participation only: {race_id, envelope_id}.'
                );
            }

            // Regex sweep over EVERY entry appended so far in this test:
            // no ballot hash, no salt, no ranking content, anywhere.
            foreach ($entries as $entry) {
                foreach ($receipts as $receipt) {
                    $this->assertStringNotContainsStringIgnoringCase($receipt->ballotHash, $entry->payload);
                    $this->assertStringNotContainsStringIgnoringCase($receipt->salt, $entry->payload);
                }
                foreach ($candidacies as $candidacyId) {
                    $this->assertStringNotContainsStringIgnoringCase($candidacyId, $entry->payload);
                }
            }

            // ── Publication: sorted list + root → chain, idempotent ───────
            $job = new PublishBallotHashesJob($race->id);
            $job->handle(app(AuditService::class));
            $job->handle(app(AuditService::class)); // idempotent re-run

            $publications = $conn->table('audit_log')
                ->where('event', PublishBallotHashesJob::EVENT)
                ->where('payload->race_id', $race->id)
                ->get();

            $this->assertCount(1, $publications, 'Exactly one publication per race, ever.');

            $published = json_decode($publications->first()->payload, true);

            $expectedHashes = array_map(fn ($r) => $r->ballotHash, $receipts);
            sort($expectedHashes, SORT_STRING);

            $this->assertSame(3, $published['ballot_count']);
            $this->assertSame($expectedHashes, $published['ballot_hashes'], 'Published list = every receipt hash, sorted.');
            $this->assertSame(PublishBallotHashesJob::rootHash($expectedHashes), $published['root_hash']);

            // ── Engine seam (BallotBoxDelegate): chain-safe payload,
            //    receipt out-of-band, NO self-appended entry ──────────────
            $voter4 = User::create([
                'name'              => 'Ballot Secrecy Throwaway 3',
                'email'             => 'ballot-secrecy-' . Str::uuid() . '@test.invalid',
                'password'          => Str::random(32),
                'terms_accepted_at' => now(),
            ]);

            $holder   = new BallotReceiptHolder;
            $delegate = new EngineBallotBox($box, $holder);

            $ranking4 = [$candidacies[1], $candidacies[0]];
            $payload4 = $delegate->commit($voter4, $race, $ranking4);

            $this->assertEqualsCanonicalizing(
                ['race_id', 'envelope_id'],
                array_keys($payload4),
                'Delegate return (the engine-recorded payload) must be participation only.'
            );

            $receipt4 = $holder->take();
            $this->assertInstanceOf(BallotReceipt::class, $receipt4);
            $this->assertTrue($receipt4->verifies($ranking4), 'Out-of-band receipt must verify.');
            $this->assertNull($holder->take(), 'Receipt holder is read-once.');

            // The engine path appends nothing itself — still exactly 3
            // commit entries (the engine would add the 4th from $payload4).
            $this->assertSame(
                3,
                $conn->table('audit_log')->where('seq', '>', $seqBefore)->where('event', 'ballot.committed')->count(),
                'commitForEngine must not self-append — the engine records the handler payload.'
            );
            $this->assertSame(4, Ballot::query()->where('race_id', $race->id)->count());

            // Ballots are never system-filed.
            try {
                $delegate->commit(null, $race, [$candidacies[2]]);
                $this->fail('A null actor must not be able to commit a ballot.');
            } catch (ConstitutionalViolation $e) {
                $this->assertSame('Art. II §2', $e->citation);
            }

            // ── Chain integrity over everything this test appended ────────
            $firstNewSeq = (int) $entries->first()->seq;
            $this->assertTrue(app(AuditService::class)->verifyChain($firstNewSeq));
        } finally {
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
        }
    }

    // ======================================================================
    // Plumbing
    // ======================================================================

    /**
     * Dedicated live-pg connection: clones the configured pgsql connection
     * but pins the real database name (phpunit.xml overrides DB_DATABASE to
     * :memory: for the sqlite default). Skips when unreachable — these
     * pins then fall to the WI-B2 tinker verification checklist.
     */
    private function livePg(): Connection
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql not loaded — live-schema pins run inside the app container.');
        }

        config([
            'database.connections.' . self::LIVE_CONNECTION => array_merge(
                config('database.connections.pgsql'),
                ['database' => env('LIVE_PG_DATABASE', 'fair_constitution')]
            ),
        ]);

        try {
            $connection = DB::connection(self::LIVE_CONNECTION);
            $connection->getPdo();

            return $connection;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Live PostgreSQL unreachable — run inside the app container. (' . $e->getMessage() . ')');
        }
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }
}

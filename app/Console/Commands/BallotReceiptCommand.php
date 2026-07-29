<?php

namespace App\Console\Commands;

use App\Domain\Ballots\BallotCrypto;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * The CLI twin of the client-side ballot commit/reveal (the bespoke sibling of
 * engine:file, because the commitment is voter-computed crypto, not a filing).
 * It computes a commitment LOCALLY from a ranked or referendum ballot, and it
 * verifies a {salt, hash} receipt against an intended ballot — the voter's
 * self-audit that their receipt commits to what they meant to vote.
 *
 * It does NOT cast. Casting rides the engine, exactly like the web path:
 * `engine:file F-IND-007 ...` (ranked) / `F-IND-008` (referendum) — the engine
 * is the ONLY writer of ballots (BallotBox), and it computes the sealed payload
 * server-side under the app key. This command never touches the ballots table.
 *
 *   php artisan ballot:receipt commit --rankings=<uuid>,<uuid>,<uuid>
 *   php artisan ballot:receipt commit --referendum=<uuid> --choice=yes
 *   php artisan ballot:receipt verify --salt=<64hex> --hash=<ballot_hash> --rankings=<uuid>,...
 */
class BallotReceiptCommand extends Command
{
    protected $signature = 'ballot:receipt
        {mode : commit | verify}
        {--rankings= : comma-separated candidacy UUIDs, most-preferred first (ranked ballot)}
        {--referendum= : referendum question UUID (referendum ballot)}
        {--choice= : yes|no (with --referendum)}
        {--salt= : the 64-hex commitment salt (verify mode)}
        {--hash= : the ballot_hash to verify against (verify mode)}';

    protected $description = 'Compute or verify a ballot commitment locally (voter self-audit; does NOT cast — casting rides engine:file)';

    public function handle(): int
    {
        $canonical = $this->canonical();

        if ($canonical === null) {
            return self::FAILURE;
        }

        return match ($this->argument('mode')) {
            'commit' => $this->commit($canonical),
            'verify' => $this->verify($canonical),
            default  => tap(self::FAILURE, fn () => $this->error('mode must be "commit" or "verify".')),
        };
    }

    private function commit(string $canonical): int
    {
        $salt = BallotCrypto::newSaltHex();
        $hash = BallotCrypto::commitmentHash($salt, $canonical);

        $this->info('salt:        '.$salt);
        $this->info('ballot_hash: '.$hash);
        $this->line('Keep the salt private. To CAST, file through the engine (it computes the sealed payload server-side):');
        $this->line('  php artisan engine:file F-IND-007 --actor=<uuid> --payload=\'{...}\'');
        $this->warn('A {salt, hash} receipt PROVES a vote (a vote-selling channel) — receipt-freeness is out of scope pending a cryptographer review.');

        return self::SUCCESS;
    }

    private function verify(string $canonical): int
    {
        $salt = (string) $this->option('salt');
        $hash = strtolower(preg_replace('/\s+/', '', (string) $this->option('hash')) ?? '');

        if ($salt === '' || $hash === '') {
            $this->error('verify needs --salt=<64hex> and --hash=<ballot_hash>.');

            return self::FAILURE;
        }

        try {
            $matches = hash_equals($hash, BallotCrypto::commitmentHash($salt, $canonical));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($matches) {
            $this->info('MATCH — this receipt commits to exactly that ballot.');

            return self::SUCCESS;
        }

        $this->error('NO MATCH — this receipt does not commit to that ballot (wrong salt, order, or selection).');

        return self::FAILURE;
    }

    /** Build the canonical byte string from either a ranked or a referendum ballot. */
    private function canonical(): ?string
    {
        $referendum = $this->option('referendum');
        $rankings = $this->option('rankings');

        try {
            if ($referendum !== null && $referendum !== '') {
                return BallotCrypto::canonicalReferendum((string) $referendum, (string) $this->option('choice'));
            }

            if ($rankings !== null && $rankings !== '') {
                $list = array_values(array_filter(
                    array_map('trim', explode(',', (string) $rankings)),
                    static fn (string $s) => $s !== '',
                ));

                return BallotCrypto::canonicalRankings($list);
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return null;
        }

        $this->error('Pass --rankings=<uuid,uuid,...> (ranked) or --referendum=<uuid> --choice=yes|no (referendum).');

        return null;
    }
}

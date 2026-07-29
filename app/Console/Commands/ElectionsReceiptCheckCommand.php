<?php

namespace App\Console\Commands;

use App\Models\Ballot;
use Illuminate\Console\Command;

/**
 * The CLI twin of the public POST /receipt-check (BallotController::receiptCheck).
 * Anyone may check whether a ballot receipt hash exists in the anonymized
 * ballots record — the least-trusting voter benefits most from a verification
 * path that needs no browser and no session. It surfaces ONLY the two fields a
 * ballot row exposes: the hour-truncated cast bucket and whether it was counted.
 * Nothing on a ballot links to a voter (Art. II ballot secrecy).
 *
 *   php artisan elections:receipt-check <64-hex-hash>
 */
class ElectionsReceiptCheckCommand extends Command
{
    protected $signature = 'elections:receipt-check {hash : the 64-hex ballot receipt hash}';

    protected $description = 'Check a ballot receipt hash against the anonymized ballot record (twin of POST /receipt-check)';

    public function handle(): int
    {
        $hash = strtolower(preg_replace('/\s+/', '', (string) $this->argument('hash')) ?? '');

        if (! preg_match('/^[0-9a-f]{64}$/', $hash)) {
            $this->error('Not a receipt hash — receipts are 64 hexadecimal characters.');

            return self::FAILURE;
        }

        $ballot = Ballot::query()->where('ballot_hash', $hash)->first(['cast_bucket', 'counted']);

        if ($ballot === null) {
            $this->warn('Not found — check for typos; hashes are 64 characters.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found. Cast %s; counted: %s.',
            $ballot->cast_bucket?->toIso8601String() ?? 'unknown',
            $ballot->counted ? 'yes' : 'no',
        ));
        $this->line('This proves a ballot with this receipt exists — it reveals nothing about who cast it or how (Art. II).');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\AuditService;
use Illuminate\Console\Command;

/**
 * audit:verify — re-walk the constitutional audit chain link by link,
 * recomputing every hash (hash(n) = sha256(hash(n-1) || canonical
 * payload(n))) from the genesis row to the head. Tamper-evidence in one
 * command: any mutated payload or broken linkage reports the exact seq.
 *
 * Usage:
 *   php artisan audit:verify
 *   php artisan audit:verify --from-seq=1000   # anchor on the row before seq 1000
 */
class AuditVerifyCommand extends Command
{
    protected $signature = 'audit:verify
                            {--from-seq= : Start verification at this seq (the preceding row anchors the walk)}';

    protected $description = 'Verify the audit_log hash chain end to end; reports the first broken seq, if any';

    public function handle(AuditService $audit): int
    {
        $fromSeq = $this->option('from-seq') !== null ? (int) $this->option('from-seq') : null;

        $result = $audit->verifyChain($fromSeq);

        if ($result !== true) {
            $this->error("Audit chain BROKEN at seq {$result} — entry does not verify against its predecessor.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Audit chain OK — %d entries verified (head seq %d).',
            $audit->count(),
            $audit->latestSeq(),
        ));

        return self::SUCCESS;
    }
}

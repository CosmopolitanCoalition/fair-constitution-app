<?php

namespace App\Console\Commands;

use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * support:list — the CLI triage door (UI↔CLI parity, §10 item 10; parity row
 * #2). The read half of the support queue: the census found support reports had
 * NO read/triage door on either surface — the web queue (/support/tickets) is
 * one door, this is its terminal twin. Reads the SAME rows the queue reads;
 * default `--status=open` mirrors the queue's "needs attention" default.
 */
class SupportListCommand extends Command
{
    protected $signature = 'support:list
                            {--status=open : open | all | new/triaged/in_progress/resolved/closed/wont_fix}
                            {--category= : filter by subject (the routed six)}
                            {--user= : filter by reporter — email or UUID}
                            {--limit=50 : max rows}';

    protected $description = 'List support reports — the CLI triage door (the read half of the support queue)';

    public function handle(): int
    {
        $query = SupportReport::query()->with('reporter:id,name,email')->latest('updated_at');

        $status = (string) $this->option('status');
        if ($status === 'open') {
            $query->whereIn('status', SupportReport::OPEN_STATUSES);
        } elseif ($status !== 'all' && in_array($status, SupportReport::STATUSES, true)) {
            $query->where('status', $status);
        }

        if ($category = $this->option('category')) {
            $query->where('category', $category);
        }

        if ($userRef = $this->option('user')) {
            $user = Str::isUuid($userRef)
                ? User::query()->find($userRef)
                : User::query()->where('email', $userRef)->first();

            if ($user === null) {
                $this->error("No such user: {$userRef}");

                return self::FAILURE;
            }
            $query->where('reporter_id', $user->id);
        }

        $rows = $query->limit(max(1, (int) $this->option('limit')))->get();

        if ($rows->isEmpty()) {
            $this->info('  No reports match.');

            return self::SUCCESS;
        }

        $this->table(
            ['Ref', 'Status', 'Kind', 'Routed to', 'Subject', 'Reporter'],
            $rows->map(fn (SupportReport $r) => [
                $r->public_id,
                $r->status,
                $r->category,
                $r->route_target,
                Str::limit((string) $r->subject, 40) ?: '—',
                $r->reporter?->email ?? '(unattributed)',
            ])->all()
        );
        $this->line('  '.$rows->count().' shown.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * support:report — the CLI twin of POST /support/report (UI↔CLI parity, §10
 * item 10; parity row #2). Files a report in the ruled routed six, writing
 * route_target via the SAME SupportReport::routeFor the web store uses — so the
 * routing guard travels with the pair (an unknown category is refused, abuse
 * routes to moderation off-queue). NOT an engine filing — a plain, attributed
 * model write; it routes and tracks, it removes nothing.
 *
 * The web store attributes to the signed-in user; a CLI has no current user, so
 * --user names the reporter (email or UUID). Omit it for an operator/system
 * report (reporter_id is nullable).
 */
class SupportReportCommand extends Command
{
    protected $signature = 'support:report
                            {category : bug|translation|accessibility|content|abuse|idea}
                            {--user= : the reporter — email or UUID (omit for an operator/system report)}
                            {--subject= : a short one-line summary}
                            {--body= : the report body (required)}
                            {--ref= : the page or context the report is about}';

    protected $description = 'File a support report — the CLI half of POST /support/report (the routed six)';

    public function handle(): int
    {
        $category = (string) $this->argument('category');
        if (! in_array($category, SupportReport::CATEGORIES, true)) {
            $this->error('Unknown category: '.$category);
            $this->line('  One of: '.implode(', ', SupportReport::CATEGORIES));

            return self::FAILURE;
        }

        $body = trim((string) $this->option('body'));
        if ($body === '') {
            $this->error('--body is required.');

            return self::FAILURE;
        }

        $reporterId = null;
        if ($userRef = $this->option('user')) {
            $user = Str::isUuid($userRef)
                ? User::query()->find($userRef)
                : User::query()->where('email', $userRef)->first();

            if ($user === null) {
                $this->error("No such user: {$userRef} — give an email or a UUID, or omit --user.");

                return self::FAILURE;
            }
            $reporterId = $user->id;
        }

        $ref = $this->option('ref');
        $ref = $ref !== null
            ? mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $ref)), 0, 300)
            : null;

        $report = SupportReport::create([
            'category' => $category,
            'subject' => $this->option('subject') ?: null,
            'body' => $body,
            'ref' => $ref ?: null,
            'reporter_id' => $reporterId,
            'status' => SupportReport::STATUS_OPEN,
            'route_target' => SupportReport::routeFor($category),
        ]);

        $this->info("  Report filed — reference {$report->public_id}");
        $this->line("  category: {$report->category}  ·  routed to: {$report->route_target}");
        $this->line('  reporter: '.($reporterId ?? '(unattributed)'));

        return self::SUCCESS;
    }
}

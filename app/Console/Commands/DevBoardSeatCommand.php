<?php

namespace App\Console\Commands;

use App\Http\Middleware\DevToolsEnabled;
use App\Services\Dev\DevBoardSeatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seat (or --unseat) a user on a legislature's active election board — the CLI
 * twin of POST /dev/board/seat.
 *
 * It is a STANDALONE command, deliberately NOT an option on `dev:assume`. The
 * parity census proposed hanging it off dev:assume, but that service is pinned to
 * NEVER seat anyone (AssumeNeverCreatesOrSeatsTest scans its source for exactly
 * this kind of write): assuming a role FINDS a seat an election produced, while
 * this MANUFACTURES a board seat for a walkthrough. Two different acts; folding
 * them together would erase the invariant. So they share nothing but intent.
 *
 * The gate is the SAME triple lock as the web tool — `DevToolsEnabled::allowed()`
 * (local + sandbox + toggle) — so this door cannot be the lenient one. Because a
 * CLI has no "current user", `--user` is required and names whose seat this is.
 */
class DevBoardSeatCommand extends Command
{
    protected $signature = 'dev:board-seat
                            {legislature : the legislature UUID whose active board to seat on}
                            {--user= : the user UUID to seat (required — a CLI has no current user)}
                            {--unseat : vacate the seat instead of taking it}';

    protected $description = "Seat or unseat a user on a legislature's active election board (dev walkthroughs)";

    public function handle(DevBoardSeatService $service): int
    {
        if (! DevToolsEnabled::allowed()) {
            $this->error('REFUSED — the dev toolbox is off here. This seats a board member directly, which is');
            $this->line('  lawful only on a LOCAL, SANDBOX world with cga.impersonation on — the same gate the');
            $this->line('  /dev/board control carries. On any other world a board seat comes from an election.');

            return self::FAILURE;
        }

        $userId = (string) $this->option('user');

        if ($userId === '') {
            $this->error('--user is required: a CLI has no current user, so name whose seat this is.');

            return self::FAILURE;
        }

        if (! DB::table('users')->where('id', $userId)->exists()) {
            $this->error("No such user: {$userId}");

            return self::FAILURE;
        }

        $legislatureId = (string) $this->argument('legislature');

        $result = $this->option('unseat')
            ? $service->unseat($userId, $legislatureId)
            : $service->seat($userId, $legislatureId);

        if (! $result['ok']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'board %s — user %s %s',
            $result['board_id'],
            $userId,
            $result['seated'] ? 'seated' : 'unseated'
        ));

        return self::SUCCESS;
    }
}

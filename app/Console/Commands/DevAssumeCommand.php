<?php

namespace App\Console\Commands;

use App\Http\Middleware\DevTimeControlsEnabled;
use App\Services\Dev\AssumeService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * D4's CLI twin (UI↔CLI parity, ruling 2026-07-28 §10 item 10).
 *
 *   php artisan dev:assume smr-1-san-marino R-09
 *
 * Runs the SAME find/relocate composition as POST /dev/assume — one
 * AssumeService, two doors that cannot drift. A terminal has no web
 * session to switch, so the BECOME half here is the printed handoff:
 * who you resolved, and the one call that makes the browser be them.
 * Parity of capability, never of exposure — the same
 * DevTimeControlsEnabled gate refuses here first.
 */
class DevAssumeCommand extends Command
{
    protected $signature = 'dev:assume
        {place : Jurisdiction id or slug}
        {role : The role to assume (R-03/R-04 resident/voter · R-06 candidate · R-08 board · R-09 legislator · R-10 speaker · R-19/R-20 judge · R-21 advocate)}';

    protected $description = 'Playtest control: find (or dev-relocate) a user holding a role in a place — the CLI half of /dev/assume';

    public function handle(AssumeService $assume): int
    {
        if ($reason = DevTimeControlsEnabled::refusalReason()) {
            $this->error($reason);

            return self::FAILURE;
        }

        $place = $assume->resolvePlace((string) $this->argument('place'));

        if ($place === null) {
            $this->error('No such place — give a jurisdiction id or slug.');

            return self::FAILURE;
        }

        try {
            ['user' => $user, 'how' => $how] = $assume->findOrRelocate(
                (string) $place->id,
                (string) $this->argument('role'),
            );
        } catch (RuntimeException $e) {
            // The honest refusal is the command's whole answer.
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '  %s — %s holds %s in %s.',
            $user->name,
            $how === 'found' ? 'already' : 'relocated there and now',
            (string) $this->argument('role'),
            $place->name,
        ));
        $this->line("  {$user->email}  ·  {$user->id}");
        $this->line('  Become them in the browser: the Demo flyout persona switcher, or');
        $this->line("  POST /dev/login-as {\"user_id\": \"{$user->id}\"}");

        return self::SUCCESS;
    }
}

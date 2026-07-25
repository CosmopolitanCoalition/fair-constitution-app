<?php

namespace App\Console\Concerns;

use App\Support\GameMode;
use App\Support\InstanceClass;

/**
 * The synthetic-data guard for every command that mints people, governments or
 * civic records that no human consented to.
 *
 * THE RULE: synthetic data may be written ONLY where the world has declared
 * itself not-real. Two declarations qualify, and they are world properties set
 * at founding — never code flags, never env vars
 * ([[feedback_no_dev_exceptions_and_test_discipline]]):
 *
 *   instance_class = scale_demo   the Attained — a full-scale illustration
 *   game_mode      = sandbox      a declared playground where the dev toolbox is legitimate
 *
 * Anything else — including an un-founded world, a DB that will not answer, and
 * every ordinary production instance — REFUSES. The default is refusal because
 * the failure is one-directional: a demo that refuses to seed costs a command
 * re-run, while a production instance that accepts synthetic residents has
 * poisoned its own consent record and, under Full Faith & Credit, its peers'.
 *
 * Before this trait existed, none of the 54 artisan commands carried any such
 * check: `elections:demo` would mint 40 permanent users against whatever
 * database `.env` happened to point at. See
 * docs/plans/simworld/SIM_SCALING_PLAN.md §11.
 */
trait GuardsSyntheticData
{
    /**
     * Call FIRST in handle(). Returns false when the command must not run — the
     * caller returns self::FAILURE immediately, having printed the reason.
     */
    protected function guardSyntheticData(): bool
    {
        if (InstanceClass::isScaleDemo() || GameMode::isSandbox()) {
            return true;
        }

        $class = InstanceClass::current();
        $mode = GameMode::current() ?? 'not yet chosen';

        $this->error('REFUSED — this world has not declared itself synthetic-safe.');
        $this->line("  instance_class = <fg=yellow>{$class}</>");
        $this->line("  game_mode      = <fg=yellow>{$mode}</>");
        $this->newLine();
        $this->line('  This command mints residents, governments or civic records that no');
        $this->line('  human consented to. That is lawful only on a world that has declared');
        $this->line('  itself an illustration or a playground:');
        $this->newLine();
        $this->line('    instance_class = scale_demo   (the Attained — a full-scale demo instance)');
        $this->line('    game_mode      = sandbox      (a declared playground)');
        $this->newLine();
        $this->line('  Both are chosen at FOUNDING and are world properties, not flags. If this');
        $this->line('  really is a demo instance, set instance_class; if it is a real world,');
        $this->line('  this refusal is the rail working.');

        return false;
    }
}

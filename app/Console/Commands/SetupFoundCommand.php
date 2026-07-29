<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use App\Models\User;
use App\Support\GameMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * setup:found — headless founding (UI↔CLI parity, ruling 10; the CLI half of
 * the setup wizard's START path). Same underlying writes, same refusals:
 *
 *   · one founder ever — refuses when any user exists (the wizard's 409)
 *   · the solo/join fork settles atomically NULL → solo (a box that already
 *     chose JOIN must not be re-founded over its mirror state)
 *   · game mode is a FOUNDING property — refused once setup completes,
 *     exactly like the wizard's saveGameMode
 *
 * The founder is the operator account and accepts the terms by creating the
 * instance (the wizard's own words). The cosmic address + map data remain
 * wizard steps — this command founds the ACCOUNT plane so a cloud box can be
 * driven to the wizard already owned, which is what headless bring-up needs.
 */
class SetupFoundCommand extends Command
{
    protected $signature = 'setup:found
        {--name= : The founder\'s display name}
        {--email= : The founder\'s email (also the operator login)}
        {--password= : The founder\'s password — omitted = prompted hidden (preferred; argv is visible in process lists)}
        {--game-mode= : production or sandbox — the founding choice that gates the dev toolbox}
        {--instance-name= : This node\'s display name}';

    protected $description = 'Found this instance headlessly: founder + operator account, game mode, node name (CLI half of the setup wizard)';

    public function handle(): int
    {
        // ── Refusal 1: one founder ever ──────────────────────────────────────
        if (User::query()->exists()) {
            $this->error('A founder account already exists.');

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: $this->ask('Founder name'));
        $email = (string) ($this->option('email') ?: $this->ask('Founder email'));
        $password = (string) ($this->option('password') ?: $this->secret('Founder password (min 8 chars)'));

        if ($name === '' || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $this->error('Founding needs a name, a valid email, and a password of at least 8 characters.');

            return self::FAILURE;
        }

        $settings = InstanceSettings::current();

        // ── Refusal 2: the solo/join fork ────────────────────────────────────
        // Same atomic NULL → mode flip as the wizard's setMode; a box that
        // already chose JOIN is someone's mirror and must not be re-founded.
        if ($settings->setup_mode === null) {
            $won = DB::table('instance_settings')
                ->whereNull('setup_mode')
                ->whereNull('deleted_at')
                ->update(['setup_mode' => 'solo']);
            if (! $won) {
                $this->error('Setup mode is already chosen.');

                return self::FAILURE;
            }
        } elseif ($settings->setup_mode !== 'solo') {
            $this->error("This box already chose the '{$settings->setup_mode}' path — founding is the START path only.");

            return self::FAILURE;
        }

        // ── Refusal 3: game mode locks at setup completion ───────────────────
        $mode = $this->option('game-mode');
        if ($mode !== null) {
            if (! in_array($mode, [GameMode::PRODUCTION, GameMode::SANDBOX], true)) {
                $this->error('game-mode must be production or sandbox.');

                return self::FAILURE;
            }
            if ($settings->isSetupComplete()) {
                $this->error('Game mode is set at founding and locked once setup is complete.');

                return self::FAILURE;
            }
        }

        // ── The founding transaction — identical to the wizard's ─────────────
        DB::transaction(function () use ($name, $email, $password, $settings, $mode) {
            User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'terms_accepted_at' => now(),
                'is_operator' => true,
            ]);

            // G-OP: the founder is ALSO the first OPERATOR — a separate plane.
            app(\App\Services\Identity\OperatorIdentityService::class)->register($email, $password);

            if ($mode !== null) {
                $settings->game_mode = $mode;
            }
            $instanceName = $this->option('instance-name');
            if (is_string($instanceName) && $instanceName !== '') {
                $settings->instance_name = $instanceName;
            }
            $settings->save();
        });

        GameMode::flush();

        $this->info('✓ Founded. The founder is the operator; finish the world in the setup wizard (/setup).');
        $this->line('  founder    '.$email);
        $this->line('  game mode  '.((string) (InstanceSettings::current()->fresh()->game_mode ?? 'not chosen yet')));

        return self::SUCCESS;
    }
}

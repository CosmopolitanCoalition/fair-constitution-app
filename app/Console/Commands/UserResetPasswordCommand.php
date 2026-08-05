<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Operator password reset — the recovery path that works WHILE locked out
 * (2026-08-05, operator locked out of the game box). A self-hosted instance
 * has no SMTP, so the email-link flow has no channel; the operator's console
 * IS the trusted channel. Email lookup is case-insensitive.
 *
 *   php artisan user:reset-password jd@example.com
 *   php artisan user:reset-password jd@example.com --password='ChosenOne1!'
 *
 * With no --password a strong random one is generated and printed ONCE.
 */
class UserResetPasswordCommand extends Command
{
    protected $signature = 'user:reset-password
                            {email : The account email (any casing)}
                            {--password= : New password; a random one is generated if omitted}';

    protected $description = 'Reset a user password from the console (case-insensitive email lookup)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        $user = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        if ($user === null) {
            $this->error("No user found with email '{$email}' (lookup is case-insensitive).");

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(14));
        if (mb_strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return self::FAILURE;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->info("Password reset for {$user->email}.");
        if (! $this->option('password')) {
            $this->line("Generated password (shown once): {$password}");
        }
        $this->line('If the login page shows a throttle message, it clears itself within a minute.');

        return self::SUCCESS;
    }
}

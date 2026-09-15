<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Engine\ConstitutionalEngine;
use App\Models\ResidencyClaim;
use App\Models\User;
use App\Services\ResidencyService;
use App\Services\RoleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * a11y:sweep-account — a throwaway resident used only by the accessibility
 * sweep to render signed-in pages as a resident sees them.
 *
 * It creates (or, with --reset, resets) a11y-sweep@example.test, a verified
 * user with an ACTIVE residency in New York County (slug usa-3-new-york). The
 * residency is established through the app's own pipeline (F-IND-003 declare,
 * F-IND-005 pings, F-IND-006 verify) so the gates read a real
 * residency_confirmations row, exactly as for any resident.
 *
 * The random 32-character password is printed ONCE to stdout and never stored
 * in the repo. The command is idempotent: a second run without --reset keeps
 * the account and its residency and does not rotate the password. Pass --reset
 * to rotate the password and rebuild the residency.
 */
final class A11ySweepAccountCommand extends Command
{
    protected $signature = 'a11y:sweep-account {--reset : Rotate the password and rebuild the residency}';

    protected $description = 'Create or reset the throwaway resident for the a11y signed-in sweep (idempotent).';

    private const EMAIL = 'a11y-sweep@example.test';

    private const JURISDICTION_SLUG = 'usa-3-new-york';

    public function handle(
        ConstitutionalEngine $engine,
        ResidencyService $residency,
        RoleService $roles,
    ): int {
        $jur = DB::table('jurisdictions')->where('slug', self::JURISDICTION_SLUG)->first(['id', 'name']);
        if ($jur === null) {
            $this->error('Jurisdiction not found: ' . self::JURISDICTION_SLUG);

            return self::FAILURE;
        }
        $jurisdictionId = (string) $jur->id;

        $reset = (bool) $this->option('reset');
        $existing = User::query()->where('email', self::EMAIL)->first();

        $password = null; // printed only when set here
        if ($existing === null) {
            $password = $this->newPassword();
            $user = User::create([
                'name' => 'A11y Sweep',
                'display_name' => 'A11y Sweep',
                'email' => self::EMAIL,
                'password' => $password, // hashed by the model cast
                'terms_accepted_at' => now(), // status defaults to 'registered' — residency, not status, makes a resident
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->info('Created ' . self::EMAIL);
        } else {
            $user = $existing;
            if ($reset) {
                $password = $this->newPassword();
                $user->forceFill([
                    'password' => $password, // hashed by the model cast
                    'email_verified_at' => now(),
                ])->save();
                $this->info('Reset ' . self::EMAIL);
            } else {
                $this->info('Exists ' . self::EMAIL . ' (password unchanged; pass --reset to rotate)');
            }
        }

        $this->ensureResidency($engine, $residency, $roles, $user, $jurisdictionId, $reset);

        $claim = ResidencyClaim::query()
            ->where('user_id', $user->id)
            ->where('status', ResidencyClaim::STATUS_ACTIVE)
            ->where('jurisdiction_id', $jurisdictionId)
            ->first();
        $confirmed = DB::table('residency_confirmations')
            ->where('user_id', (string) $user->id)
            ->where('is_active', true)
            ->exists();

        $this->line('');
        $this->line('email:     ' . self::EMAIL);
        if ($password !== null) {
            $this->line('password:  ' . $password . '   (printed once; not stored)');
        }
        $this->line('residency: ' . $jur->name . ' (' . self::JURISDICTION_SLUG . ') active_claim=' . ($claim !== null ? 'yes' : 'no') . ' confirmed=' . ($confirmed ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function newPassword(): string
    {
        // 32 URL-safe characters, no shell-hostile symbols.
        return substr(bin2hex(random_bytes(24)), 0, 32);
    }

    /**
     * Establish an ACTIVE residency at $jurisdictionId through the real
     * pipeline, mirroring the dev residency grant. Idempotent: a matching
     * active claim short-circuits. With $reset the prior claims and
     * confirmations are cleared first, then the pipeline reruns.
     */
    private function ensureResidency(
        ConstitutionalEngine $engine,
        ResidencyService $residency,
        RoleService $roles,
        User $user,
        string $jurisdictionId,
        bool $reset,
    ): void {
        $active = ResidencyClaim::query()
            ->where('user_id', $user->id)
            ->where('status', ResidencyClaim::STATUS_ACTIVE)
            ->first();

        if (! $reset && $active !== null && (string) $active->jurisdiction_id === $jurisdictionId) {
            return; // already a resident exactly there
        }

        if ($active !== null || $reset) {
            DB::transaction(function () use ($user): void {
                DB::table('residency_confirmations')
                    ->where('user_id', (string) $user->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'updated_at' => now()]);

                ResidencyClaim::query()
                    ->where('user_id', $user->id)
                    ->whereIn('status', [ResidencyClaim::STATUS_ACTIVE, ...ResidencyClaim::MONITORING_STATUSES])
                    ->update(['status' => ResidencyClaim::STATUS_SUPERSEDED, 'superseded_at' => now()]);
            });
            $roles->flushUser((string) $user->id);
        }

        $engine->file('F-IND-003', $user, [
            'jurisdiction_id' => $jurisdictionId,
            'ping_consent' => true,
        ]);

        $claim = ResidencyClaim::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ResidencyClaim::MONITORING_STATUSES)
            ->firstOrFail();

        $residency->simulatePings($user, $residency->thresholdDays($claim));
        $claim->refresh();
        $residency->verify($claim);
        $roles->flushUser((string) $user->id);
    }
}

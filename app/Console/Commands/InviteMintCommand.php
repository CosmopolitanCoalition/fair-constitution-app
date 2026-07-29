<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Invites\InviteService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * invite:mint — the CLI twin of POST /invites (UI↔CLI parity, ruling §10 item 10).
 *
 *   php artisan invite:mint jd@example.com commons --jurisdiction=<uuid> --space=square
 *   php artisan invite:mint <user-uuid> proceeding --path=legislatures/<uuid>
 *   php artisan invite:mint <user-uuid> space --room=<space-uuid>
 *
 * Mints a shareable `handle.secret` invite through the SAME InviteService::mint the
 * web POST uses — so the reachability guard travels with the pair by construction:
 * a destination the inviter cannot reach throws and the command prints the refusal,
 * exactly as the controller returns 422. That guard is resolveDestination's
 * same-origin / whitelisted-proceeding check (the open-redirect / SSRF guard) AND,
 * for the `space` kind, that {user} is actually a member of --room. An invite grants
 * NO power — it is a pointer + attribution to a place already open under Art. I /
 * Art. II §2, and for a private room that place is open to members only.
 *
 * A CLI has no current user, so {user} names the inviter (the dev:board-seat idiom).
 * The plaintext secret is shown ONCE and never persisted — the web's once-only
 * contract.
 */
class InviteMintCommand extends Command
{
    protected $signature = 'invite:mint
                            {user : the inviter — email or user UUID}
                            {kind : call|commons|proceeding|space}
                            {--jurisdiction= : jurisdiction UUID (commons / call)}
                            {--space= : square|halls (commons / call)}
                            {--room= : private room UUID (space kind)}
                            {--path= : same-origin proceeding path, e.g. legislatures/{id} (proceeding)}
                            {--label= : optional display label}
                            {--max-uses= : optional cap on redemptions (default: unlimited while live)}
                            {--ttl-days= : optional lifetime in days (default 14)}';

    protected $description = 'Mint a shareable person-to-person invite link — the CLI half of POST /invites';

    public function handle(InviteService $invites): int
    {
        $ref = (string) $this->argument('user');
        $user = Str::isUuid($ref)
            ? User::query()->find($ref)
            : User::query()->where('email', $ref)->first();

        if ($user === null) {
            $this->error("No such user: {$ref} — give an email or a user UUID.");

            return self::FAILURE;
        }

        $spec = array_filter([
            'kind'            => (string) $this->argument('kind'),
            'jurisdiction_id' => $this->option('jurisdiction'),
            'space'           => $this->option('space'),
            'space_id'        => $this->option('room'),
            'path'            => $this->option('path'),
            'label'           => $this->option('label'),
            'max_uses'        => $this->option('max-uses') !== null ? (int) $this->option('max-uses') : null,
            'ttl_days'        => $this->option('ttl-days') !== null ? (int) $this->option('ttl-days') : null,
        ], static fn ($v) => $v !== null && $v !== '');

        try {
            [$plaintext, $invite] = $invites->mint($user, $spec);
        } catch (InvalidArgumentException $e) {
            // The reachability guard's refusal is the command's whole answer.
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('  Invite minted — the link is shown once (the secret is never stored):');
        $this->line('  '.url('/i/'.$plaintext));
        $this->newLine();
        $this->line("  handle:  {$invite->handle}");
        $this->line("  kind:    {$invite->kind}");
        $this->line('  label:   '.($invite->label ?? '—'));
        $this->line("  inviter: {$user->email}");
        $this->line('  expires: '.($invite->expires_at?->toDayDateTimeString() ?? 'never'));

        return self::SUCCESS;
    }
}

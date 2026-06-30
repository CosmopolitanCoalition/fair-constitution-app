<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\Invite;
use App\Models\User;
use App\Services\Invites\InviteService;
use Illuminate\Http\Request;

/**
 * Share-to-signup glue for the auth controllers. When a guest lands on /i/{token}
 * (InviteController::land) the destination is stashed as `url.intended` and the invite id as
 * `pending_invite`; here, once that guest signs up or logs in, we redeem the invite (attribution)
 * and surface a "you'll continue to X" preview on the auth forms. Redemption is FAIL-OPEN — a bad
 * or expired invite never blocks the account from being created or the session from starting.
 */
trait RedeemsPendingInvite
{
    /** Redeem the session's pending invite (if any) for the freshly-authenticated user. */
    protected function redeemPendingInvite(Request $request, User $user): void
    {
        $inviteId = $request->session()->pull('pending_invite');

        if (! is_string($inviteId) || $inviteId === '') {
            return;
        }

        $invite = Invite::find($inviteId);

        if ($invite !== null) {
            $invites = app(InviteService::class);
            $invites->consume($invite, $user);
            $invites->grantAccess($invite, $user); // a `space` invite admits them to the private room
        }
    }

    /**
     * Inertia props the Register/Login pages use to show where the visitor will continue. The
     * destination lives in the SERVER session (survives the register↔login hop on its own), so the
     * pages only need to display it — they don't have to thread it through the URL.
     *
     * @return array{intendedUrl: ?string, invitePreview: ?array{label: ?string, inviter: ?string, kind: string}}
     */
    protected function continuationProps(Request $request): array
    {
        $intended = $request->session()->get('url.intended');

        $preview = null;
        $inviteId = $request->session()->get('pending_invite');
        if (is_string($inviteId) && $inviteId !== '') {
            $invite = Invite::find($inviteId);
            if ($invite !== null) {
                $preview = [
                    'label'   => $invite->label,
                    'inviter' => $invite->inviter?->display_name ?? $invite->inviter?->name,
                    'kind'    => $invite->kind,
                ];
            }
        }

        return [
            'intendedUrl'   => is_string($intended) ? $intended : null,
            'invitePreview' => $preview,
        ];
    }
}

<?php

namespace App\Http\Controllers\Invites;

use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\SocialSpace;
use App\Services\Invites\InviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Throwable;

/**
 * Person-to-person invites — the share-to-signup growth loop.
 *
 *   land()  GET /i/{token}  (PUBLIC) — a friend opens the link: if signed in, redeem +
 *           continue to the destination; if a guest, preview it and offer sign up / log in
 *           (the destination is stashed so they land there afterward). An invalid/expired
 *           link still shows the front door — it never dead-ends a would-be member.
 *   store() POST /invites   (AUTH)   — mint a shareable link for a destination the inviter
 *           can already reach; returns the URL once (the secret is never persisted).
 *
 * An invite is a pointer + attribution, never a privilege: it lands you somewhere already
 * open (Art. I commons / Art. II §2 public proceedings) and grants no residency, role, or power.
 */
class InviteController extends Controller
{
    public function __construct(private readonly InviteService $invites) {}

    /** GET /i/{token} — PUBLIC. */
    public function land(Request $request, string $token): RedirectResponse|Response
    {
        $invite = $this->invites->resolve($token);

        if ($invite === null) {
            // Invalid / expired / revoked — still let them in the front door (never a dead end).
            return Inertia::render('Invite/Landing', ['invite' => null]);
        }

        // Carry the destination across signup/login, and remember which invite to redeem. Only the
        // id is stashed (possession was just proven) — the secret never re-enters the session.
        $request->session()->put('url.intended', $invite->path());
        $request->session()->put('pending_invite', (string) $invite->getKey());

        $user = $request->user();
        if ($user !== null) {
            $this->invites->consume($invite, $user);
            $this->invites->grantAccess($invite, $user); // a `space` invite admits them to the private room
            $request->session()->forget('pending_invite');

            return redirect($invite->path());
        }

        return Inertia::render('Invite/Landing', [
            'invite' => [
                'label'   => $invite->label,
                'kind'    => $invite->kind,
                'inviter' => $invite->inviter?->display_name ?? $invite->inviter?->name,
                'path'    => $invite->path(),
            ],
            'preview' => $this->preview($invite),
        ]);
    }

    /**
     * A small, honest preview of where a LIVE invite leads — the guest landing's "room card"
     * (v3 civic/join.html contract). For a `space` invite it reflects the real room (title,
     * member count, privacy); for the open kinds the server-built label IS the destination's
     * name. Best-effort and FAIL-SOFT: any resolution hiccup yields null and the landing still
     * renders — the preview is decoration, the front door must never break.
     *
     * @return array{title:string,memberCount:?int,isPrivate:bool}|null
     */
    private function preview(Invite $invite): ?array
    {
        try {
            if ($invite->kind === Invite::KIND_SPACE) {
                $space = SocialSpace::query()->find((string) ($invite->destination['space_id'] ?? ''));
                if ($space === null) {
                    return null;
                }

                return [
                    'title'       => (string) $space->title,
                    'memberCount' => $space->memberships()->count(),
                    'isPrivate'   => (bool) $space->is_private,
                ];
            }

            // call / commons / proceeding — the label was server-built at mint from the destination.
            $title = trim((string) $invite->label);

            return $title === '' ? null : [
                'title'       => $title,
                'memberCount' => null,
                'isPrivate'   => false,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** POST /invites — AUTH. Mint a shareable link; the service enforces the destination rules. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind'            => ['required', Rule::in([Invite::KIND_CALL, Invite::KIND_COMMONS, Invite::KIND_PROCEEDING, Invite::KIND_SPACE])],
            'jurisdiction_id' => ['sometimes', 'nullable', 'uuid'],
            'space_id'        => ['sometimes', 'nullable', 'uuid'],
            'space'           => ['sometimes', Rule::in(['square', 'halls'])],
            'path'            => ['sometimes', 'nullable', 'string', 'max:512'],
            'label'           => ['sometimes', 'nullable', 'string', 'max:160'],
            'max_uses'        => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'ttl_days'        => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ]);

        try {
            [$plaintext, $invite] = $this->invites->mint($request->user(), $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'url'        => url('/i/'.$plaintext),
            'handle'     => $invite->handle,
            'kind'       => $invite->kind,
            'label'      => $invite->label,
            'expires_at' => $invite->expires_at?->toIso8601String(),
        ]);
    }
}

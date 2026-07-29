<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Dev\AssumeService;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * D4 — POST /dev/assume {jurisdiction, role}: one composed act where the
 * walkthrough used to need three (find a user, maybe grant residency,
 * log in as them). Pick a place and a role, be that person.
 *
 * FIND → (maybe) RELOCATE → BECOME. The first two live in AssumeService
 * with its two rules (never creates users, never seats anyone — a
 * refusal is an answer); this door adds the session switch, exactly the
 * LoginAsController idiom: login() migrates the session itself, no
 * second regenerate, and the client must follow with a FULL page load.
 *
 * Gated by DevTimeControlsEnabled — the strong gate, not the toolbox one
 * — because the relocation half WRITES residency records on demand. Fine
 * on a demo mesh; fabrication on a node any real node trusts. The audit
 * marker files BEFORE the switch, so a half-dead run still says who was
 * assuming whom.
 */
class AssumeController extends Controller
{
    public function __construct(
        private readonly AssumeService $assume,
        private readonly AuditService $audit,
        private readonly RoleService $roles,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'jurisdiction' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(AssumeService::FINDABLE_ROLES)],
        ]);

        $place = $this->assume->resolvePlace($data['jurisdiction']);

        if ($place === null) {
            return response()->json(['error' => 'No such place — give a jurisdiction id or slug.'], 404);
        }

        try {
            ['user' => $user, 'how' => $how] = $this->assume->findOrRelocate((string) $place->id, $data['role']);
        } catch (RuntimeException $e) {
            // The honest refusal: nobody fits, nobody may be made to fit.
            return response()->json(['error' => $e->getMessage()], 409);
        }

        $previous = $request->user();

        // The marker goes down BEFORE the switch, mirroring the chamber
        // cast: if the run dies here, the chain still says a developer was
        // assuming a persona.
        $this->audit->append(
            module: 'dev',
            event: 'assume.role',
            payload: [
                'jurisdiction_id' => (string) $place->id,
                'jurisdiction' => $place->name,
                'role' => $data['role'],
                'how' => $how,
                'target_user_id' => (string) $user->id,
                'previous_user_id' => $previous !== null ? (string) $previous->id : null,
                'note' => 'PLAYTEST CONTROL — a developer assumed this persona. Roles stay derived.',
            ],
            ref: 'DEV-ASSUME',
            actorId: $previous !== null ? (string) $previous->getKey() : null,
            jurisdictionId: (string) $place->id,
        );

        // login() migrates the session (id rotation + auth id persist) — a
        // second regenerate() would orphan the cookie jar's id. Same as
        // LoginAsController, same client contract: full reload after.
        Auth::guard('web')->login($user);

        return response()->json([
            'user' => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email],
            'jurisdiction' => ['id' => (string) $place->id, 'name' => $place->name, 'slug' => $place->slug],
            'role' => $data['role'],
            'how' => $how,
            'roles' => $this->roles->rolesFor($user),
        ]);
    }
}

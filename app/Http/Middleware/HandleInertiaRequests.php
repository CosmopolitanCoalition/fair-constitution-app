<?php

namespace App\Http\Middleware;

use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Http\Controllers\Dev\ImpersonationController;
use App\Models\CosmicAddress;
use App\Models\InstanceSettings;
use App\Models\Jurisdiction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

/**
 * WI-3/WI-4/WI-5 — Inertia shared props (DESIGN_frontend_port.md §C3).
 *
 * Shape:
 *   auth.user           { id, name, display_name, email, locale } | null
 *   auth.roles          derived role codes via the ResolvesRoles binding
 *                       (WI-5: RoleService — R-01..R-04 derived from real
 *                       residency state, never client-settable). Guests
 *                       share the mockups' 'R-00' visitor code.
 *   auth.impersonating  false, or { active: true, realName } while the
 *                       session carries the WI-4 impersonator_id.
 *   jurisdiction        { current, chain, cosmicPrefix } — current = the
 *                       user's deepest active residency association
 *                       ({id,name,slug,adm_level} | null), chain = its
 *                       ancestors root-first, cosmicPrefix = the enabled
 *                       cosmic-address path ("Observable Universe · … ·
 *                       Earth") for the JurisdictionSwitcher.
 *   instance            { name, setupComplete } — server-side successor of
 *                       AppLayout's fetch('/api/setup/state') round trip.
 *   locale              app locale (chrome i18n arrives with the layout WI).
 *   flash.status        one-shot status line (e.g. post-registration).
 *
 * Role/instance/jurisdiction props are lazy closures: Inertia resolves
 * them per response and partial reloads can skip them.
 */
class HandleInertiaRequests extends Middleware
{
    /** The root template loaded on the first page visit. */
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id'           => $user->id,
                    'name'         => $user->name,
                    'display_name' => $user->display_name,
                    'email'        => $user->email,
                    'locale'       => $user->locale,
                ] : null,
                'roles' => fn () => $user
                    ? app(ResolvesRoles::class)->rolesFor($user)
                    : ['R-00'],
                'impersonating' => fn () => $this->impersonationProps($request),
            ],
            'jurisdiction' => fn () => $this->jurisdictionProps($user),
            'instance'     => fn () => $this->instanceProps(),
            // Live roadmap phases — the sidebar renders items from later
            // phases as "Planned · Phase X" until their phase ships here.
            'app'          => ['phasesLive' => ['A', 'B']],
            'locale'       => fn () => app()->getLocale(),
            'flash'        => [
                'status' => fn () => $request->session()->get('status'),
                // FE-B5 — the one-shot ballot receipt (§D.3): flashed by
                // BallotController@store, rendered exactly once by the
                // redirect-back response, then gone — session flash is
                // single-pull by construction. Never persisted anywhere
                // voter-linked; no GET endpoint ever returns it again.
                'receipt_hash' => fn () => $request->session()->get('receipt_hash'),
                'receipt_salt' => fn () => $request->session()->get('receipt_salt'),
            ],
        ]);
    }

    /**
     * WI-4 — { active: true, realName } while impersonating, else false.
     * realName is the OPERATOR behind the curtain (auth.user is already
     * the impersonated person).
     */
    private function impersonationProps(Request $request): array|false
    {
        $impersonatorId = $request->session()->get(ImpersonationController::SESSION_KEY);

        if ($impersonatorId === null) {
            return false;
        }

        return [
            'active'   => true,
            'realName' => User::find($impersonatorId)?->name,
        ];
    }

    /**
     * WI-5 — the shell's jurisdiction context (§C3): the user's deepest
     * active residency association, its ancestor chain root-first, and the
     * cosmic-address prefix. Null-safe for guests and for the
     * pre-migration bootstrap flow (tables may not exist yet).
     */
    private function jurisdictionProps(?User $user): array
    {
        $current = null;
        $chain   = [];

        if ($user !== null && Schema::hasTable('residency_confirmations')) {
            // Deepest = depth 0 (the declared boundary); legacy rows without
            // depth fall back to the highest adm_level.
            $row = DB::table('residency_confirmations as rc')
                ->join('jurisdictions as j', 'j.id', '=', 'rc.jurisdiction_id')
                ->where('rc.user_id', (string) $user->id)
                ->where('rc.is_active', true)
                ->whereNull('j.deleted_at')
                ->orderByRaw('rc.depth ASC NULLS LAST')
                ->orderByDesc('j.adm_level')
                ->first(['j.id', 'j.name', 'j.slug', 'j.adm_level']);

            if ($row !== null) {
                $current = [
                    'id'        => (string) $row->id,
                    'name'      => $row->name,
                    'slug'      => $row->slug,
                    'adm_level' => (int) $row->adm_level,
                ];

                // Root-first ancestors (excluding current) — ≤ 7 levels.
                $chain = Jurisdiction::find($row->id)?->ancestors ?? [];
            }
        }

        return [
            'current'      => $current,
            'chain'        => $chain,
            'cosmicPrefix' => $this->cosmicPrefix(),
        ];
    }

    /**
     * "Observable Universe · … · Earth" from the enabled cosmic-address
     * leaf world's path (same source as /api/cosmic-addresses/default-path;
     * the Multiverse root is a UI header, not a path segment).
     */
    private function cosmicPrefix(): ?string
    {
        if (! Schema::hasTable('cosmic_addresses')) {
            return null;
        }

        $leaf = CosmicAddress::query()
            ->where('type', 'world')
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->first();

        if ($leaf === null) {
            return null;
        }

        $labels = collect($leaf->pathFromRoot())
            ->reject(fn ($row) => ($row['type'] ?? null) === 'multiverse')
            ->pluck('label')
            ->filter();

        return $labels->isEmpty() ? null : $labels->implode(' · ');
    }

    /**
     * Instance identity + setup state, shared on every page.
     *
     * Guarded for the pre-migration bootstrap flow (/setup/bootstrap renders
     * before instance_settings exists). Read-only on purpose: the
     * InstanceSettings::current() singleton accessor firstOrCreate()s, and
     * middleware must never write on a GET.
     */
    private function instanceProps(): array
    {
        if (! Schema::hasTable('instance_settings')) {
            return [
                'name'          => config('app.name'),
                'setupComplete' => false,
            ];
        }

        $settings = InstanceSettings::query()->first();

        return [
            'name'          => $settings?->instance_name ?: config('app.name'),
            'setupComplete' => $settings?->isSetupComplete() ?? false,
        ];
    }
}

<?php

namespace App\Http\Middleware;

use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Models\InstanceSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

/**
 * WI-3 — Inertia shared props (DESIGN_frontend_port.md §C3).
 *
 * Shape:
 *   auth.user           { id, name, display_name, email, locale } | null
 *   auth.roles          derived role codes via the ResolvesRoles binding
 *                       (Phase A: StubRoleResolver → ['R-01']; real
 *                       derivation lands in WI-5 by swapping the binding).
 *                       Guests share the mockups' 'R-00' visitor code.
 *   auth.impersonating  false until the WI-4 dev impersonation tool.
 *   instance            { name, setupComplete } — server-side successor of
 *                       AppLayout's fetch('/api/setup/state') round trip.
 *   locale              app locale (chrome i18n arrives with the layout WI).
 *   flash.status        one-shot status line (e.g. post-registration).
 *
 * Role/instance props are lazy closures: Inertia resolves them per response
 * and partial reloads can skip them.
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
                'impersonating' => false, // WI-4
            ],
            'instance' => fn () => $this->instanceProps(),
            'locale'   => fn () => app()->getLocale(),
            'flash'    => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ]);
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

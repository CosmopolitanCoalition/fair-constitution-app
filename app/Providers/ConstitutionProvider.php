<?php

namespace App\Providers;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Forms\Contracts\BallotBoxDelegate;
use App\Domain\Forms\Contracts\CertificationPipeline;
use App\Domain\Forms\Contracts\ElectionSchedulingDelegate;
use App\Domain\Forms\Contracts\ResidencyHandlerDelegate;
use App\Domain\Forms\NoopBallotBoxDelegate;
use App\Domain\Forms\NoopCertificationPipeline;
use App\Domain\Forms\NoopElectionSchedulingDelegate;
use App\Models\User;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use App\Services\ResidencyService;
use App\Services\RoleService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the constitutional engine (WI-2 skeleton; WI-5 real bindings).
 *
 * WI-5 replaced the Phase A stubs without touching the engine:
 *  - ResolvesRoles → RoleService              (was StubRoleResolver)
 *  - ResidencyHandlerDelegate → ResidencyService (was NoopResidencyDelegate)
 *
 * Phase B (WI-B4) adds the election-handler seams, no-op until their
 * owners merge (the orchestrator rebinds — same pattern as WI-5):
 *  - BallotBoxDelegate → BallotBox            (WI-B2)
 *  - ElectionSchedulingDelegate → ElectionLifecycleService (WI-B3)
 *  - CertificationPipeline → seating pipeline (WI-B5)
 *
 * RoleService is a singleton so the derivation is request-cached (one
 * fact-query pass per user per request); ResidencyService::* writers and
 * the Phase B candidacy/endorsement handlers flush it when facts change.
 */
class ConstitutionProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
        $this->app->singleton(ConstitutionalValidator::class);
        $this->app->singleton(ConstitutionalEngine::class);

        $this->app->singleton(RoleService::class);
        $this->app->bind(ResolvesRoles::class, fn ($app) => $app->make(RoleService::class));

        $this->app->singleton(ResidencyService::class);
        $this->app->bind(ResidencyHandlerDelegate::class, fn ($app) => $app->make(ResidencyService::class));

        // Phase B handler seams — real bindings (WI-B2/WI-B3 merged):
        //  - EngineBallotBox commits through the engine without a second chain
        //    entry; the receipt travels out-of-band via the read-once holder
        //    (scoped: one holder per request, never cached across requests).
        //  - CertificationPipeline stays no-op until WI-B5 lands the seating
        //    pipeline.
        $this->app->scoped(\App\Domain\Ballots\BallotReceiptHolder::class);
        $this->app->bind(BallotBoxDelegate::class, \App\Domain\Ballots\EngineBallotBox::class);
        $this->app->bind(ElectionSchedulingDelegate::class, fn ($app) => $app->make(\App\Services\ElectionLifecycleService::class));
        $this->app->bind(CertificationPipeline::class, NoopCertificationPipeline::class);
    }

    public function boot(): void
    {
        // Derived-role gates for later phases ('role:R-xx' middleware uses
        // the same vocabulary — see App\Http\Middleware\EnsureRole).
        Gate::define('associated', fn (User $user) => in_array('R-03', $this->app->make(ResolvesRoles::class)->rolesFor($user), true));
        Gate::define('voter', fn (User $user) => in_array('R-04', $this->app->make(ResolvesRoles::class)->rolesFor($user), true));
    }
}

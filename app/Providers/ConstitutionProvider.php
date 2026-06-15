<?php

namespace App\Providers;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Services\Identity\AttestationGate;
use App\Domain\Forms\Contracts\BallotBoxDelegate;
use App\Domain\Forms\Contracts\CertificationPipeline;
use App\Domain\Forms\Contracts\ElectionSchedulingDelegate;
use App\Domain\Forms\Contracts\ResidencyHandlerDelegate;
use App\Domain\Forms\NoopBallotBoxDelegate;
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
 *  - ResolvesRoles → AttestationGate → RoleService (G-ID dual-stack; was StubRoleResolver)
 *  - ResidencyHandlerDelegate → ResidencyService (was NoopResidencyDelegate)
 *
 * Phase B (WI-B4) added the election-handler seams; all three now carry
 * their real implementations:
 *  - BallotBoxDelegate → EngineBallotBox      (WI-B2)
 *  - ElectionSchedulingDelegate → ElectionLifecycleService (WI-B3)
 *  - CertificationPipeline → CertificationService (WI-B5)
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
        // G-ID dual-stack: AttestationGate IS the ResolvesRoles, but for local
        // session users it delegates straight to the live RoleService derivation
        // (Art. I — never a stored snapshot). Zero behaviour change; the attested
        // path activates only on forwarded-write requests (G4).
        $this->app->bind(ResolvesRoles::class, fn ($app) => $app->make(AttestationGate::class));

        $this->app->singleton(ResidencyService::class);
        $this->app->bind(ResidencyHandlerDelegate::class, fn ($app) => $app->make(ResidencyService::class));

        // Phase B handler seams — real bindings (WI-B2/WI-B3/WI-B5 merged):
        //  - EngineBallotBox commits through the engine without a second chain
        //    entry; the receipt travels out-of-band via the read-once holder
        //    (scoped: one holder per request, never cached across requests).
        //  - CertificationService is the WI-B5 seating pipeline: F-ELB-004
        //    seats winners + terms (CLK-10 lockstep), flips the legislature
        //    active, arms CLK-01/CLK-10, opens election N+1; F-ELB-006
        //    dispatches the audit re-tabulation.
        $this->app->scoped(\App\Domain\Ballots\BallotReceiptHolder::class);
        $this->app->bind(BallotBoxDelegate::class, \App\Domain\Ballots\EngineBallotBox::class);
        $this->app->bind(ElectionSchedulingDelegate::class, fn ($app) => $app->make(\App\Services\ElectionLifecycleService::class));
        $this->app->bind(CertificationPipeline::class, fn ($app) => $app->make(\App\Services\CertificationService::class));

        // Phase C votes-laws seam: the chamber vote engine consults the
        // committee roster through this contract. The chamber-ops
        // committees substrate is live (2026_06_21 migrations) — bound to
        // the real roster over committee_seats; NoopCommitteeRoster stays
        // as the documented fallback stub.
        $this->app->bind(
            \App\Domain\Forms\Contracts\CommitteeRoster::class,
            \App\Services\Legislature\EloquentCommitteeRoster::class
        );
    }

    public function boot(): void
    {
        // Derived-role gates for later phases ('role:R-xx' middleware uses
        // the same vocabulary — see App\Http\Middleware\EnsureRole).
        Gate::define('associated', fn (User $user) => in_array('R-03', $this->app->make(ResolvesRoles::class)->rolesFor($user), true));
        Gate::define('voter', fn (User $user) => in_array('R-04', $this->app->make(ResolvesRoles::class)->rolesFor($user), true));
        // FE-B7 — the board console gate: R-08 derives from a seated row on
        // an active board, or from the operator driving an active BOOTSTRAP
        // board (RoleService::hasActiveBoardSeat).
        Gate::define('access-board', fn (User $user) => in_array('R-08', $this->app->make(ResolvesRoles::class)->rolesFor($user), true));
    }
}

<?php

namespace App\Providers;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Engine\Contracts\ResolvesRoles;
use App\Domain\Engine\StubRoleResolver;
use App\Domain\Forms\Contracts\ResidencyHandlerDelegate;
use App\Domain\Forms\NoopResidencyDelegate;
use App\Services\AuditService;
use App\Services\ConstitutionalValidator;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the constitutional engine skeleton (WI-2).
 *
 * Phase A stub bindings, each replaced in its later work item without
 * touching the engine:
 *  - ResolvesRoles → StubRoleResolver            (real RoleService: WI-5)
 *  - ResidencyHandlerDelegate → NoopResidencyDelegate
 *                                                (real ResidencyService: WI-5)
 */
class ConstitutionProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
        $this->app->singleton(ConstitutionalValidator::class);
        $this->app->singleton(ConstitutionalEngine::class);

        $this->app->bind(ResolvesRoles::class, StubRoleResolver::class);
        $this->app->bind(ResidencyHandlerDelegate::class, NoopResidencyDelegate::class);
    }
}

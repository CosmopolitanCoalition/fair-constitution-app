<?php

namespace App\Providers;

use App\Services\Matrix\Scan\LocalHashListScanProvider;
use App\Services\Matrix\Scan\MediaScanProvider;
use App\Services\Matrix\Translation\LocalStubTranslationProvider;
use App\Services\Matrix\Translation\TranslationProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // M-S media-scan seam (K3-I.4): the default provider is the fully-offline local hash list (the
        // privacy rail). An operator swaps in a cloud / IWF-NCMEC provider by re-binding this interface.
        $this->app->bind(MediaScanProvider::class, LocalHashListScanProvider::class);

        // K3-K translation seam: the default is the fully-offline local stub (isCloud()=false), so a
        // fresh instance translates with NO third party and NO rail exception. An operator may bind a
        // cloud provider — the TranslationGate's privacy rail still forbids it on private rooms.
        $this->app->bind(TranslationProvider::class, LocalStubTranslationProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

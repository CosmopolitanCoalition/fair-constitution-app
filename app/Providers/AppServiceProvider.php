<?php

namespace App\Providers;

use App\Services\Matrix\Scan\LocalHashListScanProvider;
use App\Services\Matrix\Scan\MediaScanProvider;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

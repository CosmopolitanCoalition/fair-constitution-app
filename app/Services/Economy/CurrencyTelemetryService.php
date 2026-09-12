<?php

namespace App\Services\Economy;

/** Compatibility reader for the latest complete account-only currency report. */
class CurrencyTelemetryService
{
    public function __construct(private IssuanceService $issuance) {}

    /** Null means no completed report; a page request never aggregates the world. */
    public function snapshot(string $currencyId): ?array
    {
        return app(CurrencyReportService::class)->read($currencyId)['data'];
    }
}

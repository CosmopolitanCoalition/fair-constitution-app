<?php

namespace App\Jobs;

use App\Services\Economy\CurrencyReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One short checkpointed chunk, then return the worker to the shared pool. */
class RefreshCurrencyReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    // Default also covers queued payloads created before revisions existed.
    public ?int $revision = null;

    public function __construct(public string $currencyId, public string $runId, ?int $revision = null)
    {
        $this->revision = $revision;
    }

    public function handle(CurrencyReportService $reports): void
    {
        if ($this->revision === null) {
            $reports->dispatch($this->currencyId, $this->runId);
            return;
        }
        if ($reports->advance($this->currencyId, $this->runId, revision: $this->revision)) {
            $reports->dispatch($this->currencyId, $this->runId);
        }
    }

    public function failed(?\Throwable $error): void
    {
        if ($this->revision !== null) {
            app(CurrencyReportService::class)->fail($this->currencyId, $this->runId, $this->revision);
        }
    }
}

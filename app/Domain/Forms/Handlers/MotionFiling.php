<?php

namespace App\Domain\Forms\Handlers;

use App\Domain\Forms\Contracts\FormHandler;
use App\Models\CaseFiling;
use App\Models\User;
use App\Services\Judiciary\CaseFilingService;

/**
 * F-ADV-002 — Motion Filing. Actor R-21. Appends a `case_filings` row
 * filing_kind='motion'; the attach-window gate allows motions before and
 * during `heard`. The motion awaits a judge ruling (granted/denied + written
 * reasons), which appends a follow-up filing row — never edits this one.
 */
class MotionFiling implements FormHandler
{
    use Concerns\DocketsAdvocateFilings;

    public function __construct(
        private readonly CaseFilingService $filings,
    ) {}

    public function module(): string
    {
        return 'judiciary';
    }

    public function event(): string
    {
        return 'motion.filed';
    }

    public function requiredRoles(): array
    {
        return ['R-21'];
    }

    public function systemOnly(): bool
    {
        return false;
    }

    public function handle(?User $actor, array $payload): array
    {
        return $this->docketAdvocateFiling($actor, $payload, CaseFiling::KIND_MOTION, 'F-ADV-002');
    }
}

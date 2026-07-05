<?php

namespace App\Support;

use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Schema;

/**
 * Founding setup context — the node-level analogue of the district-mapper's
 * BoardProvenance::inSetupContext.
 *
 * A node is FOUNDING while its own setup is not yet complete AND it is the
 * origin (not a read-only mirror of some host). During founding the operator is
 * the sole constitutional authority: there is no seated government to answer to,
 * no mesh peers whose zones a role could touch, and often not even a jurisdiction
 * yet. So the operator SELF-ASSERTS every operator role directly (governed
 * channels included) — no dual-meter consent, no jurisdiction scope required.
 *
 * Once setup completes (a government is founded) and/or the node joins a mesh,
 * governed roles revert to the normal qualify → request → dual-meter-consent
 * path for any FUTURE change; roles self-asserted during founding stay enabled.
 *
 * This is a principled CONTEXT the constitution implies, not a dev flag
 * ([[feedback_no_dev_exceptions_and_test_discipline]]).
 */
class FoundingContext
{
    public static function isFounding(): bool
    {
        try {
            if (! Schema::hasTable('instance_settings')) {
                return false;
            }
            $settings = InstanceSettings::query()->first();
            if ($settings === null) {
                return false;
            }
            return ! $settings->isSetupComplete() && ! $settings->isMirror();
        } catch (\Throwable $e) {
            // Fail closed — never claim founding on an uncertain read.
            return false;
        }
    }
}

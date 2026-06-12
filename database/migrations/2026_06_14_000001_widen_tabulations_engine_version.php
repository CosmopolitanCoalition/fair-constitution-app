<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WI-B5 — widen `tabulations.engine_version` varchar(16) → varchar(32).
 *
 * B-8 sized the column before WI-B1 fixed the engine identifier:
 * `VoteCountingService::ENGINE_VERSION = 'stv-droop-wig/1.0.0'` is 19
 * characters and is hashed into every `record_hash`, so it must be stored
 * verbatim (a truncated snapshot could never be re-verified against the
 * sealed hash). Purely a type widening — additive against the live dev DB
 * (the column is unwritten until the first tabulation runs).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tabulations ALTER COLUMN engine_version TYPE varchar(32)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tabulations ALTER COLUMN engine_version TYPE varchar(16)');
    }
};

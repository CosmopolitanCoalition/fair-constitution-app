<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support lifecycle (ruling §10 item 7 — the routed six) — additive columns on
 * support_reports, applied on top of the flattened baseline (the table lives in
 * database/schema/pgsql-schema.sql). Real-dated after lane 13's joint-ledger
 * batch, additive-only, honest down().
 *
 *   subject       the short summary the intake collects separate from the body
 *   route_target  where a category routes (operators | translation | moderation
 *                 | backlog | courts) — recorded so the queue/detail can show
 *                 "routed to …" without recomputing, and so abuse can sit
 *                 off-queue on the moderation & legal path
 *   severity      the triage dial (low | normal | high | critical), set on
 *                 review — nullable; a fresh report has none
 *
 * The category *values* move from the old six (bug/question/conduct/legal/
 * appeal/other) to the routed six (bug/translation/accessibility/content/abuse/
 * idea) in the MODEL (app-layer validation, not a DB enum). No data remap here:
 * the table is empty (verified 0 rows). Were rows present, conduct→abuse,
 * legal→abuse (moderation route), appeal→courts route per the ruling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_reports', function (Blueprint $table) {
            $table->string('subject', 160)->nullable();
            $table->string('route_target', 32)->nullable();
            $table->string('severity', 16)->nullable();
            $table->index('route_target');
        });
    }

    public function down(): void
    {
        Schema::table('support_reports', function (Blueprint $table) {
            $table->dropIndex(['route_target']);
            $table->dropColumn(['subject', 'route_target', 'severity']);
        });
    }
};

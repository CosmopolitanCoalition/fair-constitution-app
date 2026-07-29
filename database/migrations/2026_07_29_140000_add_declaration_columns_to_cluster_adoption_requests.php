<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The demo-mesh declaration carriage, keyless-queue half (ruling §10 item 4,
 * completing 7b09915). Additive columns on cluster_adoption_requests, applied
 * on top of the flattened baseline (the table lives in
 * database/schema/pgsql-schema.sql). Real-dated after lane 6's support-lifecycle
 * columns (2026_07_29_130000), additive-only, honest down() proven while the
 * table is cheap (0 rows on a fresh box; the flagged gap the desk cleared).
 *
 * WHY: 7b09915 made the SIGNED declarations (instance_class + game_mode) travel
 * on the handshake and on the KEYED adoption path, where admitMirror pins them
 * straight onto the mirror's peer row. The KEYLESS path could not: a request
 * sits PENDING in this table until the host operator vouches it, and there was
 * no column to hold what the applicant declared across that wait — so a
 * queue-admitted mirror recorded no demo-ness and the dev-time rail read it as
 * REAL. These two columns carry the declaration through the pending queue so
 * approveRequest can pin it, exactly as the keyed path already does.
 *
 *   declared_instance_class  the applicant's signed instance_class at request
 *                            time (production | scale_demo), normalized before
 *                            store; NULL only for a pre-ruling applicant that
 *                            declared none — undeclared reads as real, fail
 *                            closed
 *   declared_game_mode       the applicant's signed game_mode (production |
 *                            sandbox); NULL = undeclared, the same fail-closed
 *                            direction (GameMode::normalize keeps absence null,
 *                            unlike the class)
 *
 * No data remap: additive nullable columns on an append-only queue. The values
 * are app-layer validated (InstanceClass / GameMode normalize on both write and
 * read), not a DB enum — consistent with how the peer metadata stores them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cluster_adoption_requests', function (Blueprint $table) {
            $table->string('declared_instance_class', 16)->nullable();
            $table->string('declared_game_mode', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cluster_adoption_requests', function (Blueprint $table) {
            $table->dropColumn(['declared_instance_class', 'declared_game_mode']);
        });
    }
};

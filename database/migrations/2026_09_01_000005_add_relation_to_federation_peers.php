<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G1) — discriminate a peer edge. `relation` is the PEER's role to us:
 *   - `sovereign` — a normal Phase F peer (co-equal authority). ALL existing
 *     rows take this default, so Phase F behaviour is unchanged.
 *   - `host`      — a host WE read-only-mirror.
 *   - `mirror`    — a mirror of US.
 *
 * (Our reciprocal role is recorded on `cluster_memberships.role`.) Additive: the
 * default backfills every Phase F peer to `sovereign`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('federation_peers', function (Blueprint $table) {
            $table->string('relation', 16)->default('sovereign')
                ->comment("Phase G: the peer's role to us — sovereign | host (a host we mirror) | mirror (a mirror of us)");
        });

        DB::statement("ALTER TABLE federation_peers ADD CONSTRAINT federation_peers_relation_check CHECK (relation IN ('sovereign','host','mirror'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE federation_peers DROP CONSTRAINT IF EXISTS federation_peers_relation_check');

        Schema::table('federation_peers', function (Blueprint $table) {
            $table->dropColumn('relation');
        });
    }
};

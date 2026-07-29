<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Art. III §5 defense-in-depth: the CGC IP register is append-only and
 * irreversible. The baseline already ships the real wall — the
 * cgc_ip_register_block_mutation() trigger raises on UPDATE/DELETE — but
 * CgcIpPublicDomainTest also pins that the APP ROLE holds no mutation
 * grants, and no migration had ever revoked them (the pin was born failing
 * on every fresh install; found by lane 4's Wave 1 checkpoint triage,
 * 2026-07-29). Additive, REAL-dated, applies on top of the baseline.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* The connected role is the app role; quote it as an identifier. */
        $role = '"'.str_replace('"', '', (string) DB::connection()->getConfig('username')).'"';

        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.cgc_ip_register FROM PUBLIC');
        DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON public.cgc_ip_register FROM {$role}");
    }

    public function down(): void
    {
        /* Deliberately empty: re-granting mutation rights on a constitutional
           append-only register is never a rollback anyone should perform. */
    }
};

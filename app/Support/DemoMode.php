<?php

namespace App\Support;

/**
 * DEMO MODE — the box-level posture of a scale_demo instance (operator rulings
 * 2026-09-10, App Progress Rubric questions Ldemo-mode / Ldemo-identity):
 *
 *   A. Demo mode is a BOX-LEVEL fact: instance_class = scale_demo, stamped at
 *      founding (InstanceClass). There is no per-user or per-session switch.
 *   C. Demo actions are REAL WRITES with a COMPENSATING PURGE at session end
 *      ("I like this because this will allow multiuser interaction"): every
 *      user sees every other user's acts while their sessions live; when a
 *      session ends (logout, or expiry) the engine reverses that session's
 *      unchanged, unreferenced row writes in reverse order and appends ONE `demo.session.voided`
 *      entry to the audit chain. Conflicting later edits are preserved and
 *      named in that entry. The chain itself is never edited.
 *
 * What "going through the motions" means on a demo box:
 *   - a role gate (FormHandler::requiredRoles) and the role-training gate do
 *     not block a filing; the bypass is recorded in the act's audit payload
 *     (`_demo.bypassed`), so the record says what was waived;
 *   - the mutation lands for real (rows, audit entry, public record);
 *   - DemoSessionService voids it when the session ends.
 *
 * What persists across a demo user's sessions: WHO they are. The identity
 * layer (users, residency, location pings) is excluded from capture, so a
 * returning demo user keeps their account and residency and only their civic
 * acts are gone. See CAPTURE_EXCLUDED.
 *
 * Production boxes never enter this path: DemoMode::active() is false unless
 * InstanceClass::isScaleDemo(), which fails CLOSED (see InstanceClass).
 */
final class DemoMode
{
    /** The PostgreSQL setting the engine sets (transaction-local) for a demo filing. */
    public const GUC = 'cga.demo_session';

    /** The session key that binds an HTTP session to its demo_sessions row. */
    public const SESSION_KEY = 'demo_session_id';

    /**
     * Tables the row-capture trigger is NEVER installed on. Three groups:
     *   - the chain and its mirrors (immutable by construction),
     *   - the identity layer (who you are persists; what you did does not),
     *   - infrastructure and ETL tables (no civic act writes them).
     * Tables without a primary key are skipped by the installer regardless.
     *
     * @var list<string>
     */
    public const CAPTURE_EXCLUDED = [
        // the chain + its mirrors
        'audit_log', 'audit_checkpoints', 'audit_chain_reconciliations', 'public_records',
        // demo bookkeeping (capturing these would recurse)
        'demo_sessions', 'demo_session_writes',
        // the identity layer
        'users', 'residency_claims', 'residency_confirmations', 'location_pings', 'education_progress',
        'password_reset_tokens', 'sessions',
        // the instance itself + federation plane
        'instance_settings', 'federation_peers', 'federation_transports', 'federation_transport_health',
        'peer_upgrade_proposals', 'peer_upgrade_consents',
        // framework + queue infrastructure
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations',
        // ETL / lattice / ledgers (bulk pipelines, console-only writers)
        'spatial_ref_sys', 'geoboundary_metadata', 'cga_nc_pixels', 'cga_nc_strips',
        'jurisdiction_adjacency', 'jurisdiction_adjacency_parents', 'jurisdiction_centroids',
        'jurisdiction_simplified', 'district_shape_stats', 'provision_ledger', 'provision_timings',
        'apportionment_ledger', 'sim_timings', 'demo_time_advances',
    ];

    /** True when this box is a scale_demo instance and session capture is on. */
    public static function active(): bool
    {
        return InstanceClass::isScaleDemo()
            && (bool) config('cga.demo_session_capture', true);
    }
}

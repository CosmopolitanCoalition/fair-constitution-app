<?php

namespace App\Http\Controllers\Civic;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\Candidacy;
use App\Services\RepresentativesResolver;
use App\Services\ResidencyService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * WI-8 — GET /civic/record: the user's own slice of the audit chain
 * (my-record contract) + the F-IND-002 personal-settings panel.
 *
 * Phase 2 (mockups-v3-wiring): /civic/record is now the ONE person page —
 * the unified tabbed profile (mockups/v3 profile-v2.js contract). One
 * person, every role: Overview · Record · Candidacy (only when standing) ·
 * Representatives · Achievements (designed empty state) · Wallet (planned)
 * · Settings (the F-IND-002 panel, unchanged).
 *
 * The record is READ from audit_log (actor_user_id = me) — it is the same
 * hash-chained ledger the system keeps, filtered, never a parallel copy.
 * It can never contain ballot content or raw locations because those are
 * structurally never written to the chain (commitments and count-bumps
 * only — AuditService docblock).
 *
 * POST /civic/record/profile files F-IND-002 through the engine; the
 * handler applies the whitelisted fields to the user row inside the
 * engine transaction.
 */
class MyRecordController extends Controller
{
    /** Locales offered in the personal-settings panel (mirrors onboarding). */
    public const LOCALES = ['en', 'es', 'ar', 'zh-Hans', 'hi'];

    /** Profile tabs (profile-v2.js tabsFor(), self view). Invalid ?tab= → 'overview'. */
    public const TABS = [
        'overview',
        'record',
        'candidacy',
        'representatives',
        'achievements',
        'wallet',
        'settings',
    ];

    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly ResidencyService $residency,
        private readonly RepresentativesResolver $representatives,
    ) {
    }

    public function show(Request $request): Response
    {
        $user   = $request->user();
        $userId = (string) $user->id;

        $entries = AuditEntry::query()
            ->where('actor_user_id', $userId)
            ->orderByDesc('seq')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (AuditEntry $entry) => [
                'seq'            => $entry->seq,
                'occurred_at'    => $entry->occurred_at?->toIso8601String(),
                'module'         => $entry->module,
                'event'          => $entry->event,
                'ref'            => $entry->ref,
                'hash'           => $entry->hash,
                'rejected'       => $entry->rejected,
                'blocked_reason' => $entry->blocked_reason,
            ]);

        // Associations with their confirmation facts (RoleService's chip
        // helper deliberately omits days_confirmed; the table needs it).
        $associations = DB::table('residency_confirmations as rc')
            ->join('jurisdictions as j', 'j.id', '=', 'rc.jurisdiction_id')
            ->where('rc.user_id', $userId)
            ->where('rc.is_active', true)
            ->whereNull('j.deleted_at')
            ->orderBy('j.adm_level')
            ->orderBy('j.name')
            ->get(['j.id', 'j.name', 'j.slug', 'j.adm_level', 'rc.depth', 'rc.days_confirmed', 'rc.confirmed_at'])
            ->map(fn ($row) => [
                'id'             => (string) $row->id,
                'name'           => $row->name,
                'slug'           => $row->slug,
                'adm_level'      => (int) $row->adm_level,
                'depth'          => $row->depth !== null ? (int) $row->depth : null,
                'days_confirmed' => $row->days_confirmed !== null ? (int) $row->days_confirmed : null,
                'confirmed_at'   => $row->confirmed_at,
            ])
            ->all();

        // Live recount while monitoring (mirrors Home/Residency — the stored
        // count only updates on sweep/verification).
        $claim          = $this->residency->openClaimFor($user);
        $qualifyingDays = 0;
        if ($claim !== null) {
            $qualifyingDays = $claim->isMonitoring()
                ? $this->residency->qualifyingDays($claim)
                : (int) $claim->qualifying_days;
        }

        // Candidacies — every one the user ever filed (terminal states stay
        // on the public record); the Candidacy tab renders only when > 0.
        $candidacies = Candidacy::query()
            ->where('user_id', $userId)
            ->with([
                'election:id,kind,status,jurisdiction_id',
                'election.jurisdiction:id,name',
                'race:id,election_id,jurisdiction_id,seat_kind,seats',
                'race.jurisdiction:id,name',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Candidacy $candidacy) => [
                'id'                 => (string) $candidacy->id,
                'election_id'        => (string) $candidacy->election_id,
                'race_label'         => $this->raceLabel($candidacy),
                'status'             => $candidacy->status,
                'platform_statement' => $candidacy->platform_statement,
                'position_tags'      => $candidacy->position_tags ?? [],
            ])
            ->values()
            ->all();

        // Validated ?tab= — anything off the contract falls back to overview.
        $tab = $request->query('tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'overview';

        return Inertia::render('Civic/MyRecord', [
            'surface'         => SurfaceMeta::for('civic/my-record'),
            'tab'             => $tab,
            'representatives' => $this->representatives->forUser($user),
            'candidacies'     => $candidacies,
            'entries'      => $entries,
            'associations' => $associations,
            'stats'        => [
                'record_entries' => DB::table('audit_log')->where('actor_user_id', $userId)->count(),
                'associations'   => count($associations),
                'qualifying_days'=> $qualifyingDays,
                'ballots_cast'   => 0, // Phase B
            ],
            'profile' => [
                'display_name' => $user->display_name,
                'locale'       => $user->locale,
                'timezone'     => $user->timezone,
                'languages'    => $user->languages ?? [],
            ],
            'localeOptions'   => self::LOCALES,
            'languageOptions' => RegisteredUserController::LANGUAGES,
        ]);
    }

    /** F-IND-002 — personal settings, through the engine. */
    public function updateProfile(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => ['nullable', 'string', 'max:255'],
            'locale'       => ['nullable', 'string', Rule::in(self::LOCALES)],
            'timezone'     => ['nullable', 'string', 'timezone:all'],
            'languages'    => ['sometimes', 'array', 'max:' . count(RegisteredUserController::LANGUAGES)],
            'languages.*'  => ['string', Rule::in(RegisteredUserController::LANGUAGES)],
        ]);

        // File only fields that actually differ from the user row — the
        // chain records exactly what changed, never a no-op echo.
        $user    = $request->user();
        $payload = [];

        foreach (['display_name', 'locale', 'timezone'] as $field) {
            if (($validated[$field] ?? null) !== null && $validated[$field] !== $user->{$field}) {
                $payload[$field] = $validated[$field];
            }
        }

        if (($validated['languages'] ?? null) !== null
            && array_values($validated['languages']) !== array_values($user->languages ?? [])) {
            $payload['languages'] = array_values($validated['languages']);
        }

        if ($payload === []) {
            return back()->with('status', 'No changes — nothing was filed.');
        }

        $this->engine->file('F-IND-002', $user, $payload);

        return back()->with('status', 'Profile updated — the change is on your record.');
    }

    /**
     * Human race label for a candidacy card — jurisdiction + election kind
     * (+ seats when the race row is loaded). Degrades gracefully when the
     * election/race rows are gone (soft-deleted history).
     */
    private function raceLabel(Candidacy $candidacy): string
    {
        $jurisdiction = $candidacy->race?->jurisdiction?->name
            ?? $candidacy->election?->jurisdiction?->name;

        $kind  = $candidacy->election?->kind;
        $seats = $candidacy->race?->seats;

        $parts = array_filter([
            $jurisdiction,
            ($kind !== null ? str_replace('_', ' ', $kind) . ' ' : '') . 'election',
            $seats !== null ? $seats . ' seat' . ($seats === 1 ? '' : 's') : null,
        ]);

        return implode(' · ', $parts);
    }
}

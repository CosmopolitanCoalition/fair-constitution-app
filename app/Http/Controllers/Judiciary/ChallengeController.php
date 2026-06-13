<?php

namespace App\Http\Controllers\Judiciary;

use App\Domain\Engine\ConstitutionalEngine;
use App\Domain\Forms\FormRegistry;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\ChamberVote;
use App\Models\ChamberVoteProposal;
use App\Models\ConstitutionalChallenge;
use App\Models\ConstitutionalFinding;
use App\Models\Executive;
use App\Models\JudicialSeat;
use App\Models\Judiciary;
use App\Models\Law;
use App\Models\PublicRecord;
use App\Models\RemedyRecommendation;
use App\Services\RoleService;
use App\Support\SurfaceMeta;
use App\Support\TextDiff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * FE-E5 — Constitutional challenge (PHASE_E_DESIGN_frontend.md §B.4;
 * surface judiciary/constitutional-challenge) — THE Phase E exit-criterion
 * surface. The whole page IS the Art4Section5Tracker component: the
 * F-IND-016 filing composer (R-03, any inhabitant) → the finding (F-JDG-004)
 * → the remedy + its two judge-set windows (F-JDG-005, CLK-11/CLK-12) → the
 * three Art. IV §5 paths [legislative amendment · F-LEG-035 supermajority
 * override · the F-JDG-006 judicial_remedy LawDiff with PRESERVED full-text
 * history].
 *
 *   GET  /constitutional-challenges            — index (the viewer's deepest
 *        associated judiciary; the latest open/recent challenge, else the
 *        F-IND-016 empty-state composer).
 *   GET  /constitutional-challenges/{challenge}— show one challenge.
 *   POST /constitutional-challenges            — F-IND-016 filing (engine).
 *
 * PUBLIC READ (Art. II §2 / Art. V §2 — Full Faith & Credit gives public
 * Acts, Records, and Judicial proceedings): findings, remedies, and every
 * member's override position are public record; filing gates R-03 via the
 * engine 422, never a page 403 (CandidacyRegistration / PetitionController
 * posture). Every threshold, the override `required` count, the CLK-11/
 * CLK-12 due dates, and the `applied` boolean are ENGINE SNAPSHOTS read off
 * the constitutional_challenges / remedy_recommendations / chamber_votes /
 * law_versions rows — this controller renders the record, never decides the
 * path or recomputes a threshold.
 */
class ChallengeController extends Controller
{
    public function __construct(
        private readonly ConstitutionalEngine $engine,
        private readonly RoleService $roles,
    ) {}

    // =========================================================================
    // GET /constitutional-challenges
    // =========================================================================

    public function index(Request $request): Response
    {
        $user = $request->user();
        $associations = $user !== null ? $this->roles->associationsFor($user) : [];
        $chainIds = array_column($associations, 'id');

        $judiciary = $this->resolveJudiciary($chainIds);

        // The active challenge: prefer a live (open-window / in-flight) one in
        // the viewer's chain, else the most recent of any state (public read).
        $challenge = $this->latestChallenge($judiciary, $chainIds);

        return $this->renderSurface($judiciary, $challenge, $associations);
    }

    // =========================================================================
    // GET /constitutional-challenges/{challenge}
    // =========================================================================

    public function show(Request $request, ConstitutionalChallenge $challenge): Response
    {
        $user = $request->user();
        $associations = $user !== null ? $this->roles->associationsFor($user) : [];

        $challenge->loadMissing('judiciary', 'challengedLaw', 'finding', 'remedy');

        return $this->renderSurface($challenge->judiciary, $challenge, $associations);
    }

    // =========================================================================
    // POST /constitutional-challenges — F-IND-016 (the exit-criterion entry)
    // =========================================================================

    /**
     * F-IND-016 — file a constitutional challenge. The right is ABSOLUTE
     * (Art. IV §5.1 / Art. I): R-03 (jurisdictional association) is the only
     * gate, enforced by the engine authorize stage; an un-associated POST
     * surfaces as errors.constitution (the 422 citation verbatim), never a
     * page 403. A parked (no-court) filing is ACCEPTED at `filed`.
     */
    public function file(Request $request): RedirectResponse
    {
        $this->engine->file('F-IND-016', $request->user(), [
            'challenged_law_id' => (string) $request->input('challenged_law_id', ''),
            'jurisdiction_id' => $request->input('jurisdiction_id') ?: null,
            'claim_text' => (string) $request->input('claim_text', ''),
            'claimed_basis' => (string) $request->input('claimed_basis', ''),
            'cited_authority_law_id' => $request->input('cited_authority_law_id') ?: null,
            'constitutional_citation' => $request->input('constitutional_citation') ?: null,
        ]);

        return back()->with(
            'status',
            'Constitutional challenge filed — any inhabitant may file; no standing gatekeeper beyond '
            .'jurisdictional association. The court hears it and, on a finding, the Art. IV §5 windows open '
            .'(F-IND-016 · Art. IV §5).'
        );
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /** @param  list<array<string, mixed>>  $associations */
    private function renderSurface(?Judiciary $judiciary, ?ConstitutionalChallenge $challenge, array $associations): Response
    {
        $chainIds = array_column($associations, 'id');

        return Inertia::render('Judiciary/ConstitutionalChallenge', [
            'surface' => SurfaceMeta::for('judiciary/constitutional-challenge'),
            'judiciary' => $judiciary !== null ? [
                'id' => (string) $judiciary->id,
                'name' => $judiciary->court_name,
            ] : null,
            'challenge' => $challenge !== null ? $this->challengeProps($challenge) : null,
            'machine' => config('cga.state_machines.constitutional_challenge', []),
            // The viewer's association chain → the F-IND-016 scale options
            // (you challenge a law binding in a jurisdiction you inhabit).
            'fileForm' => [
                'lawOptions' => $this->challengeableLaws($chainIds),
                'scaleOptions' => array_map(fn (array $a) => [
                    'id' => $a['id'],
                    'name' => $a['name'],
                    'adm_level' => $a['adm_level'],
                ], $associations),
                'bases' => [
                    ['value' => ConstitutionalChallenge::BASIS_CONSTITUTION, 'label' => 'Contradicts the Constitution'],
                    ['value' => ConstitutionalChallenge::BASIS_OTHER_LAW, 'label' => 'Contradicts another (superior) law'],
                ],
            ],
            'isAssociated' => $chainIds !== [],
            'can' => [
                // R-03 = any active residency association. The engine is the
                // boundary (422); this only drives the form's enabled state.
                'fileChallenge' => $chainIds !== [],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Resolution helpers (public-read posture — deepest associated judiciary)
    // -------------------------------------------------------------------------

    /** The deepest associated jurisdiction's judiciary (public read), or null. */
    private function resolveJudiciary(array $chainIds): ?Judiciary
    {
        if ($chainIds === []) {
            return null;
        }

        return Judiciary::query()
            ->join('jurisdictions as j', 'j.id', '=', 'judiciaries.jurisdiction_id')
            ->whereIn('judiciaries.jurisdiction_id', $chainIds)
            ->whereNull('judiciaries.deleted_at')
            ->whereNull('j.deleted_at')
            ->orderByDesc('j.adm_level')
            ->select('judiciaries.*')
            ->first();
    }

    /**
     * The challenge to render: the most recent in the viewer's chain, with a
     * live (window-open / in-flight) one preferred over a closed one.
     */
    private function latestChallenge(?Judiciary $judiciary, array $chainIds): ?ConstitutionalChallenge
    {
        if ($chainIds === []) {
            return null;
        }

        $live = [
            ConstitutionalChallenge::STATUS_FILED,
            ConstitutionalChallenge::STATUS_UNDER_REVIEW,
            ConstitutionalChallenge::STATUS_FINDING_ISSUED,
            ConstitutionalChallenge::STATUS_REMEDY_RECOMMENDED,
            ConstitutionalChallenge::STATUS_LEGISLATIVE_WINDOW_OPEN,
        ];

        return ConstitutionalChallenge::query()
            ->whereIn('jurisdiction_id', $chainIds)
            ->with('judiciary', 'challengedLaw', 'finding', 'remedy')
            ->orderByRaw('CASE WHEN status IN ('.implode(',', array_fill(0, count($live), '?')).') THEN 0 ELSE 1 END', $live)
            ->orderByDesc('filed_at')
            ->first();
    }

    // -------------------------------------------------------------------------
    // Tracker hydration — every field is a row snapshot (Art4Section5Tracker)
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function challengeProps(ConstitutionalChallenge $challenge): array
    {
        $law = $challenge->challengedLaw;
        $finding = $challenge->finding;
        $remedy = $challenge->remedy;

        $writingJudge = $this->writingJudge($challenge, $finding);
        $isMajor = $finding?->full_court ?? false;

        return [
            'id' => (string) $challenge->id,
            'name' => $law !== null
                ? sprintf('Challenge to Act %s', $law->act_number)
                : 'Constitutional challenge',
            'law' => $law !== null ? [
                'id' => (string) $law->id,
                'name' => $law->title !== null ? "Act {$law->act_number} — {$law->title}" : "Act {$law->act_number}",
                'href' => $this->lawHref($law),
            ] : null,
            'filed_by_label' => $this->filerLabel($challenge),
            'filed_at' => $challenge->filed_at?->toDateString(),
            'court' => $challenge->judiciary !== null ? ['name' => $challenge->judiciary->court_name] : null,
            'is_major' => $isMajor,
            'full_court_size' => $isMajor
                ? $this->fullCourtSize($challenge, $finding)
                : null,
            'writing_judge' => $writingJudge !== null ? ['name' => $writingJudge] : null,
            'state' => $challenge->status,
            'resolution' => $this->resolution($challenge),

            'finding' => $finding !== null ? [
                'form_card' => $this->formCard('F-JDG-004'),
                'text' => $finding->opinion_text,
            ] : null,

            'remedy' => $remedy !== null ? $this->remedyProps($remedy) : null,

            'override' => $this->overrideProps($challenge, $remedy),

            'bill_href' => $this->amendmentBillHref($challenge),

            'remedy_diff' => $this->remedyDiff($challenge, $finding, $remedy, $law),
            'judicial_remedy_form_card' => $this->formCard('F-JDG-006'),

            'enforcement' => $this->enforcementLink($challenge),
        ];
    }

    /** F-JDG-005 — the remedy + both judge-set windows (engine snapshots). */
    private function remedyProps(RemedyRecommendation $remedy): array
    {
        return [
            'form_card' => $this->formCard('F-JDG-005'),
            'text' => $remedy->recommended_text ?? $remedy->rationale_text,
            'timeframe_days' => (int) $remedy->remedy_timeframe_days,
            'timeframe_due_on' => $remedy->remedy_due_at?->toDateString(),
            'clk' => 'CLK-12',
            'veto_window_days' => (int) $remedy->veto_window_days,
            'veto_closes_on' => $remedy->veto_closes_at?->toDateString(),
            'veto_clk' => 'CLK-11',
            'tz' => 'dates shown in your timezone · stored as UTC',
        ];
    }

    /**
     * Path B — the F-LEG-035 supermajority override (§5.4). The vote rides a
     * chamber_vote_proposal (KIND_JUDICIARY_OVERRIDE, payload.challenge_id);
     * its chamber_vote_tallies row carries the PROTECTED `required_yes` /
     * `serving` snapshots. Never re-derived here.
     */
    private function overrideProps(ConstitutionalChallenge $challenge, ?RemedyRecommendation $remedy): array
    {
        $form = $this->formCard('F-LEG-035');

        $proposal = ChamberVoteProposal::query()
            ->where('proposal_kind', ChamberVoteProposal::KIND_JUDICIARY_OVERRIDE)
            ->where('payload->challenge_id', (string) $challenge->id)
            ->orderByDesc('created_at')
            ->first();

        $vote = $proposal?->vote_id !== null
            ? ChamberVote::query()->with('tallies')->find((string) $proposal->vote_id)
            : null;

        if ($vote !== null) {
            $lane = $vote->tallies->firstWhere('lane', 'all') ?? $vote->tallies->first();

            $serving = $lane !== null ? (int) $lane->serving : (int) ($vote->serving_snapshot ?? 0);
            $required = $lane !== null ? (int) $lane->required_yes : 0;
            $yes = $lane !== null ? (int) $lane->yes : 0;

            return [
                'form_card' => $form,
                'vote' => [
                    'serving' => $serving,
                    'requiredYes' => $required,
                    'tallies' => $lane !== null ? [
                        'yes' => (int) $lane->yes,
                        'no' => (int) $lane->no,
                        'abstain' => (int) $lane->abstain,
                    ] : null,
                    'outcome' => $this->voteOutcome($vote),
                ],
                'required' => $required,
                'serving' => $serving,
                'yes' => $yes,
                'closed' => $vote->status === ChamberVote::STATUS_CLOSED,
            ];
        }

        // No override vote opened yet → render the ThresholdMeter fallback off
        // the serving snapshot the remedy/clock arming carried, if any. The
        // engine owns `required`; absent a vote we leave it null so the
        // component shows an honest "pending" meter rather than a guessed ceil.
        return [
            'form_card' => $form,
            'vote' => null,
            'required' => null,
            'serving' => null,
            'yes' => 0,
            'closed' => false,
        ];
    }

    /**
     * Path C — the judicial_remedy LawDiff (§5.5). Before the remedy applies
     * the diff PREVIEWS current-text → recommended-text; after it applies the
     * diff is the real prior-version → judicial_remedy-version delta, and the
     * preserved-history link lands on the version's public record.
     */
    private function remedyDiff(
        ConstitutionalChallenge $challenge,
        ?ConstitutionalFinding $finding,
        ?RemedyRecommendation $remedy,
        ?Law $law,
    ): ?array {
        if ($remedy === null || $finding === null) {
            return null;
        }

        $offendingLawId = (string) $finding->offending_law_id;
        $applied = $challenge->status === ConstitutionalChallenge::STATUS_JUDICIAL_REMEDY_APPLIED
            || ($challenge->resolution_path === ConstitutionalChallenge::PATH_JUDICIAL_REMEDY);

        if ($applied) {
            // The real append: source='judicial_remedy' version vs its prior.
            $remedyVersion = DB::table('law_versions')
                ->where('law_id', $offendingLawId)
                ->where('source', \App\Models\LawVersion::SOURCE_JUDICIAL_REMEDY)
                ->orderByDesc('version_no')
                ->first();

            if ($remedyVersion !== null) {
                $prior = DB::table('law_versions')
                    ->where('law_id', $offendingLawId)
                    ->where('version_no', '<', $remedyVersion->version_no)
                    ->orderByDesc('version_no')
                    ->first();

                return [
                    'segments' => TextDiff::segments(
                        (string) ($prior->text ?? ''),
                        (string) $remedyVersion->text,
                    ),
                    'applied' => true,
                    'version_no' => (int) $remedyVersion->version_no,
                    'prior_version_no' => $prior !== null ? (int) $prior->version_no : null,
                    'history_href' => $this->versionHistoryHref($offendingLawId),
                ];
            }
        }

        // Preview: the in-force text → the court's recommended text.
        $currentText = (string) (DB::table('law_versions')
            ->where('law_id', $offendingLawId)
            ->orderByDesc('version_no')
            ->value('text') ?? '');

        $proposed = $remedy->remedy_kind === RemedyRecommendation::KIND_REMOVE
            ? sprintf('[STRUCK by judicial remedy — Art. IV §5.5] Act %s is removed for irreconcilable '
                .'constitutional contradiction.', $law?->act_number ?? '')
            : (string) ($remedy->recommended_text ?? '');

        return [
            'segments' => TextDiff::segments($currentText, $proposed),
            'applied' => false,
            'version_no' => null,
            'prior_version_no' => $law !== null ? (int) $law->current_version_no : null,
            'history_href' => $this->versionHistoryHref($offendingLawId),
        ];
    }

    // -------------------------------------------------------------------------
    // Small field resolvers
    // -------------------------------------------------------------------------

    /**
     * Map the model status → the tracker's `resolution` axis
     * ('window_open'|'amended'|'overridden'|'applied'). Pre-window states
     * stay 'window_open' (the finding/remedy render; the paths preview).
     */
    private function resolution(ConstitutionalChallenge $challenge): string
    {
        return match ($challenge->resolution_path) {
            ConstitutionalChallenge::PATH_LEGISLATIVE_AMENDMENT => 'amended',
            ConstitutionalChallenge::PATH_LEGISLATURE_OVERRIDE => 'overridden',
            ConstitutionalChallenge::PATH_JUDICIAL_REMEDY => 'applied',
            default => 'window_open',
        };
    }

    private function voteOutcome(ChamberVote $vote): string
    {
        if ($vote->status === ChamberVote::STATUS_OPEN) {
            return 'pending';
        }

        return match ($vote->outcome) {
            ChamberVote::OUTCOME_ADOPTED => 'adopted',
            ChamberVote::OUTCOME_FAILED => 'failed',
            ChamberVote::OUTCOME_TIED => 'tied',
            default => 'pending',
        };
    }

    /** The challenge writer = the finding-writing judge, else the presiding seat. */
    private function writingJudge(ConstitutionalChallenge $challenge, ?ConstitutionalFinding $finding): ?string
    {
        // The finding's panel_snapshot may name the writer; else fall back to
        // any seated judge of the court (display label only).
        $snapshot = $finding?->panel_snapshot ?? [];

        if (is_array($snapshot) && isset($snapshot['writing_judge'])) {
            return (string) $snapshot['writing_judge'];
        }

        $seat = JudicialSeat::query()
            ->where('judiciary_id', (string) $challenge->judiciary_id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->with('user:id,name,display_name')
            ->orderBy('seat_number')
            ->first();

        return $seat?->user?->display_name ?? $seat?->user?->name;
    }

    /** Engine snapshot: the full-court size = seated judges that heard it. */
    private function fullCourtSize(ConstitutionalChallenge $challenge, ?ConstitutionalFinding $finding): int
    {
        $snapshot = $finding?->panel_snapshot ?? [];

        if (is_array($snapshot) && isset($snapshot['size'])) {
            return (int) $snapshot['size'];
        }

        return JudicialSeat::query()
            ->where('judiciary_id', (string) $challenge->judiciary_id)
            ->where('status', JudicialSeat::STATUS_SEATED)
            ->count();
    }

    private function filerLabel(ConstitutionalChallenge $challenge): string
    {
        $jurisdiction = $challenge->jurisdiction_id !== null
            ? DB::table('jurisdictions')->where('id', (string) $challenge->jurisdiction_id)->value('name')
            : null;

        return $jurisdiction !== null
            ? "a {$jurisdiction} resident"
            : 'an inhabitant of the jurisdiction';
    }

    /** Path A — the amendment bill tagged to this challenge (WF-LEG-06). */
    private function amendmentBillHref(ConstitutionalChallenge $challenge): ?string
    {
        $billId = Bill::query()
            ->where('targets_challenge_id', (string) $challenge->id)
            ->orderByDesc('created_at')
            ->value('id');

        return $billId !== null ? "/bills/{$billId}" : null;
    }

    /** WF-EXE-07 — the executive that enforces the outcome, if one exists. */
    private function enforcementLink(ConstitutionalChallenge $challenge): ?array
    {
        $executiveId = Executive::query()
            ->where('jurisdiction_id', (string) $challenge->jurisdiction_id)
            ->whereIn('status', [Executive::STATUS_DELEGATED, Executive::STATUS_ELECTED])
            ->value('id');

        return $executiveId !== null
            ? ['href' => "/executives/{$executiveId}/actions"]
            : null;
    }

    /** A law links to its enacting bill page, else the public record. */
    private function lawHref(Law $law): string
    {
        return $law->enacting_bill_id !== null
            ? "/bills/{$law->enacting_bill_id}"
            : '/system/public-records';
    }

    /**
     * The preserved-history landing for a law: its public record (the version
     * list lives there) anchored to the audit-chain seq when one is recorded —
     * `audit:verify` runs green over the new judicial_remedy LawVersion hash.
     */
    private function versionHistoryHref(string $lawId): string
    {
        $seq = PublicRecord::query()
            ->where('subject_type', 'law')
            ->where('subject_id', $lawId)
            ->orderByDesc('audit_seq')
            ->value('audit_seq');

        return $seq !== null ? "/system/audit-chain?seq={$seq}" : '/system/public-records';
    }

    // -------------------------------------------------------------------------
    // Forms & filing options
    // -------------------------------------------------------------------------

    /**
     * A reference FormCard record {id, name, alias, citation} from the
     * canonical FormRegistry — NOT an interactive form (the tracker renders
     * F-JDG-004/005/006 + F-LEG-035 as the court's record cards).
     *
     * @return array{id: string, name: string, alias: ?string, citation: ?string}
     */
    private function formCard(string $id): array
    {
        $meta = FormRegistry::meta($id);
        $drift = array_keys($meta['catalog_drift'] ?? []);

        return [
            'id' => $meta['id'],
            'name' => $meta['name'],
            'alias' => $drift[0] ?? null,
            'citation' => $this->surfaceFormCitation($id),
        ];
    }

    /** Pull the per-form citation off the surface registry (single source). */
    private function surfaceFormCitation(string $id): ?string
    {
        foreach (config('cga.surfaces.judiciary/constitutional-challenge.forms', []) as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry['citation'] ?? null;
            }
        }

        return null;
    }

    /**
     * In-force / amended laws the viewer can challenge (those binding in a
     * jurisdiction the viewer inhabits, or any ancestor — the same subtree
     * the service accepts). The list is a convenience; the engine re-checks
     * the subtree on file.
     *
     * @return list<array{id: string, label: string, jurisdiction_id: string}>
     */
    private function challengeableLaws(array $chainIds): array
    {
        if ($chainIds === []) {
            return [];
        }

        return Law::query()
            ->whereIn('jurisdiction_id', $chainIds)
            ->whereIn('status', [Law::STATUS_IN_FORCE, Law::STATUS_AMENDED])
            ->orderByDesc('enacted_at')
            ->limit(100)
            ->get(['id', 'act_number', 'title', 'jurisdiction_id'])
            ->map(fn (Law $law) => [
                'id' => (string) $law->id,
                'label' => $law->title !== null ? "Act {$law->act_number} — {$law->title}" : "Act {$law->act_number}",
                'jurisdiction_id' => (string) $law->jurisdiction_id,
            ])
            ->values()
            ->all();
    }
}

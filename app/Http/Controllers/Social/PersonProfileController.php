<?php

namespace App\Http\Controllers\Social;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Elections\ElectionController;
use App\Http\Presenters\CandidacyPanel;
use App\Models\AuditEntry;
use App\Models\Candidacy;
use App\Models\Endorsement;
use App\Models\SocialFollow;
use App\Models\SocialMembership;
use App\Models\SocialProfile;
use App\Models\SocialSpace;
use App\Models\User;
use App\Services\JourneyService;
use App\Services\Social\PrivateRoomService;
use App\Support\SurfaceMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * THE PUBLIC PERSON PROFILE — the ?who= page (v3 contract
 * mockups/v3/assets/js/profile-v2.js; v3.2 ruling 0a "one person = one
 * profile"). One person, any role: candidacy and office are TABS of the
 * same profile, never separate identities. The retired
 * electoral/candidate-profile contract forwards here
 * (?candidate=<candidacy> → ?who=<person>&tab=candidacy), and the app's
 * old /candidates/{candidacy} route now redirects to this page.
 *
 *   GET    /people?who=<user uuid | @handle>[&tab=][&candidacy=]  public
 *   POST   /people/{user}/follow                                  auth
 *   DELETE /people/{user}/follow                                  auth
 *
 * Rails, in force on this page above all pages:
 *  - Art. I pseudonymity end-to-end: names resolve display_name (the
 *    F-IND-002 choice) → social pseudonym → Resident-hash. users.name
 *    (legal) never appears — no governance-surface fallback here.
 *  - The public/private line is the constitution's, not rank: candidacy
 *    and office tabs are public because seeking/holding public power is
 *    public; bio, follow counts and achievements show only when the
 *    subject's own social profile says visibility=public ("your choice to
 *    show"). 'jurisdiction' visibility is treated as not-public in this
 *    slice (the shared-chain check is a flagged follow-up), never as a
 *    rights gate either way.
 *  - PI-6: no composite score anywhere — achievements arrive as a list;
 *    nothing on this page counts, ranks or percentages a person.
 *  - Follows are LOCAL-ONLY (SocialFollow docblock): a plain row, never
 *    audited, never federated, never published.
 */
class PersonProfileController extends Controller
{
    /** Profile tabs (profile-v2.js tabsFor(), public view). Invalid ?tab= → 'overview'. */
    public const TABS = ['overview', 'record', 'candidacy', 'office', 'achievements'];

    public function __construct(
        private readonly CandidacyPanel $candidacyPanel,
        private readonly JourneyService $journeys,
        private readonly ConstitutionalEngine $engine,
        private readonly PrivateRoomService $rooms,
    ) {
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $who = trim(self::queryString($request, 'who'));

        // Legacy candidate-profile contract: ?candidate=<candidacy uuid>
        // forwards to the person's Candidacy tab (v3.2 item 0a).
        if ($who === '' && Str::isUuid(self::queryString($request, 'candidate'))) {
            $candidacy = Candidacy::query()->find((string) $request->query('candidate'));

            if ($candidacy !== null) {
                return redirect()->route('people.show', [
                    'who' => (string) $candidacy->user_id,
                    'tab' => 'candidacy',
                    'candidacy' => (string) $candidacy->id,
                ]);
            }
        }

        // No subject: your own public profile is the natural landing when
        // signed in; a guest gets the my-record door via login.
        if ($who === '') {
            return $request->user() !== null
                ? redirect()->route('people.show', ['who' => (string) $request->user()->getKey()])
                : redirect()->route('login');
        }

        $subject = $this->resolveSubject($who);

        abort_if($subject === null, 404);

        $viewer = $request->user();
        $isSelf = $viewer !== null && (string) $viewer->getKey() === (string) $subject->getKey();

        $profile = SocialProfile::query()->where('user_id', (string) $subject->getKey())->first();
        $isPublicProfile = $profile !== null && $profile->visibility === SocialProfile::VISIBILITY_PUBLIC;

        $candidacies = $this->candidaciesFor($subject);
        $offices = $this->officesFor($subject);

        // Tab availability mirrors the mockup: candidacy/office tabs exist
        // only when there is something to show; ?tab= off the contract (or
        // pointing at an absent tab) falls back to overview.
        $tabs = ['overview', 'record'];
        if ($candidacies !== []) {
            $tabs[] = 'candidacy';
        }
        if ($offices !== []) {
            $tabs[] = 'office';
        }
        if ($isSelf || $isPublicProfile) {
            $tabs[] = 'achievements';
        }

        $tab = $request->query('tab');
        $tab = in_array($tab, $tabs, true) ? $tab : 'overview';

        return Inertia::render('Social/PersonProfile', [
            'surface' => SurfaceMeta::for('social/profile'),
            'tab' => $tab,
            'tabs' => $tabs,
            'isSelf' => $isSelf,
            // The message door: you can DM anyone but yourself, once signed
            // in. Reached FROM a profile you found through someone's public
            // acts — never a directory search (Art. I: no user directory).
            'canMessage' => $viewer !== null && ! $isSelf,
            // Raw editable values for the self-edit door (F-IND-002) — self only.
            'editable' => $isSelf ? [
                'display_name' => $subject->display_name,
                'handle'       => $profile?->handle,
                'bio'          => $profile?->bio,
                'visibility'   => $profile?->visibility ?? SocialProfile::VISIBILITY_PUBLIC,
            ] : null,
            'person' => $this->personFor($subject, $profile, $offices, $isSelf, $isPublicProfile),
            'follow' => [
                'canFollow' => $viewer !== null && ! $isSelf,
                'isFollowing' => $viewer !== null && ! $isSelf && SocialFollow::query()
                    ->where('follower_user_id', (string) $viewer->getKey())
                    ->where('target_type', SocialFollow::TARGET_USER)
                    ->where('target_id', (string) $subject->getKey())
                    ->exists(),
            ],
            'candidacies' => $candidacies,
            'candidacyPanel' => $this->focusedPanel($request, $subject, $candidacies, $viewer),
            'offices' => $offices,
            'record' => $this->recordFor($subject, $isSelf, $isPublicProfile),
            // null = the subject has not chosen to show them (distinct from []).
            'achievements' => ($isSelf || $isPublicProfile) ? $this->journeys->achievementsFor($subject) : null,
        ]);
    }

    /** POST /people/{user}/follow — a LOCAL-ONLY row; never audited, never published. */
    public function follow(Request $request, User $user): RedirectResponse
    {
        abort_if((string) $request->user()->getKey() === (string) $user->getKey(), 422, 'You cannot follow yourself.');

        $existing = SocialFollow::withTrashed()
            ->where('follower_user_id', (string) $request->user()->getKey())
            ->where('target_type', SocialFollow::TARGET_USER)
            ->where('target_id', (string) $user->getKey())
            ->first();

        if ($existing !== null) {
            $existing->restore();
        } else {
            SocialFollow::query()->create([
                'follower_user_id' => (string) $request->user()->getKey(),
                'target_type' => SocialFollow::TARGET_USER,
                'target_id' => (string) $user->getKey(),
            ]);
        }

        return back()->with('status', 'Following — a private note to yourself; it appears on no record.');
    }

    /** DELETE /people/{user}/follow. */
    public function unfollow(Request $request, User $user): RedirectResponse
    {
        SocialFollow::query()
            ->where('follower_user_id', (string) $request->user()->getKey())
            ->where('target_type', SocialFollow::TARGET_USER)
            ->where('target_id', (string) $user->getKey())
            ->delete();

        return back()->with('status', 'Unfollowed.');
    }

    /**
     * POST /people/profile — the self-edit door (F-IND-002 extension, W4
     * §②). Files your public-profile changes through the engine: display
     * name (the user row, chained as today) plus handle / bio / visibility
     * (the social profile, values withheld from the chain — see the handler).
     * Only fields that actually differ are filed — no no-op echo.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Normalise the handle before the format + uniqueness rules run, so
        // "MyHandle" and "myhandle" collide as they should (we store lower).
        if (is_string($request->input('handle'))) {
            $request->merge(['handle' => mb_strtolower(trim($request->input('handle')))]);
        }

        $validated = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'handle'       => [
                'sometimes', 'nullable', 'string',
                'regex:/^[a-z0-9][a-z0-9_-]{2,63}$/',
                Rule::unique('social_profiles', 'handle')
                    ->ignore((string) $user->getKey(), 'user_id')
                    ->whereNull('deleted_at'),
            ],
            'bio'          => ['sometimes', 'nullable', 'string', 'max:2000'],
            'visibility'   => ['sometimes', Rule::in([
                SocialProfile::VISIBILITY_PUBLIC,
                SocialProfile::VISIBILITY_JURISDICTION,
                SocialProfile::VISIBILITY_PRIVATE,
            ])],
        ]);

        $profile = SocialProfile::query()->where('user_id', (string) $user->getKey())->first();
        $payload = [];

        if (array_key_exists('display_name', $validated)
            && $validated['display_name'] !== null
            && $validated['display_name'] !== $user->display_name) {
            $payload['display_name'] = $validated['display_name'];
        }

        if (array_key_exists('handle', $validated) && $validated['handle'] !== null
            && $validated['handle'] !== $profile?->handle) {
            $payload['handle'] = $validated['handle'];
        }

        if (array_key_exists('bio', $validated)
            && trim((string) ($validated['bio'] ?? '')) !== (string) ($profile?->bio ?? '')) {
            $payload['bio'] = trim((string) ($validated['bio'] ?? ''));
        }

        if (array_key_exists('visibility', $validated)
            && $validated['visibility'] !== $profile?->visibility) {
            $payload['visibility'] = $validated['visibility'];
        }

        if ($payload === []) {
            return back()->with('status', 'No changes — nothing was filed.');
        }

        $this->engine->file('F-IND-002', $user, $payload);

        return back()->with('status', 'Profile updated.');
    }

    /**
     * POST /people/{user}/message — open (or reopen) a direct message with
     * this person (W4 §②). A DM is a two-person private room; it reuses the
     * rooms plane, so it appears in both inboxes and both can talk. Bypassing
     * the invite link is legitimate here: you reached this person through
     * their public acts, not a directory the constitution forbids.
     */
    public function message(Request $request, User $user): RedirectResponse
    {
        $me = $request->user();

        abort_if((string) $me->getKey() === (string) $user->getKey(), 422, 'You cannot message yourself.');

        $space = $this->findDirect($me, $user);

        if ($space === null) {
            $space = $this->rooms->create($me, 'Direct message');
            $this->rooms->admit($space, $user);
        }

        return redirect('/civic/rooms/' . $space->id)
            ->with('status', 'Conversation opened.');
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * An existing direct message between exactly two people — a private
     * group room whose entire membership is {me, them}. Keeps "message this
     * person" idempotent (one DM per pair, not a new room per click).
     */
    private function findDirect(User $me, User $them): ?SocialSpace
    {
        $meId = (string) $me->getKey();
        $themId = (string) $them->getKey();

        $candidateIds = SocialMembership::query()
            ->whereIn('user_id', [$meId, $themId])
            ->whereNull('deleted_at')
            ->groupBy('space_id')
            ->havingRaw('count(distinct user_id) = 2')
            ->pluck('space_id');

        foreach ($candidateIds as $spaceId) {
            $total = SocialMembership::query()
                ->where('space_id', (string) $spaceId)
                ->whereNull('deleted_at')
                ->count();

            if ($total !== 2) {
                continue; // a larger group that happens to contain both — not a DM
            }

            $space = SocialSpace::query()
                ->whereKey((string) $spaceId)
                ->where('space_type', SocialSpace::TYPE_GROUP)
                ->where('is_private', true)
                ->whereNull('deleted_at')
                ->first();

            if ($space !== null) {
                return $space;
            }
        }

        return null;
    }

    /**
     * A query value that is only ever a string: bracketed params
     * (?who[]=x) arrive as arrays, and a (string) cast on an array is a
     * promoted ErrorException — a guest-triggerable 500 on a public route.
     */
    private static function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }

    /**
     * ?who= accepts a user uuid or a social handle (with or without '@').
     *
     * The handle branch resolves PUBLIC profiles only: a hidden handle is
     * not a public address. Resolving a private/jurisdiction-visibility
     * handle would confirm the handle→person mapping the subject chose to
     * hide, even with the handle nulled in the response — the lookup
     * itself is the leak. (Uuid lookups still work: a uuid names the
     * person, not their pseudonym.)
     */
    private function resolveSubject(string $who): ?User
    {
        if (Str::isUuid($who)) {
            return User::query()->find($who);
        }

        $handle = ltrim(mb_strtolower($who), '@');

        if ($handle === '' || mb_strlen($handle) > 64) {
            return null;
        }

        $profile = SocialProfile::query()
            ->whereRaw('lower(handle) = ?', [$handle])
            ->where('visibility', SocialProfile::VISIBILITY_PUBLIC)
            ->first();

        return $profile === null ? null : User::query()->find((string) $profile->user_id);
    }

    private function personFor(User $subject, ?SocialProfile $profile, array $offices, bool $isSelf, bool $isPublicProfile): array
    {
        $display = CandidacyPanel::displayName($subject);

        $showSocial = $isSelf || $isPublicProfile;

        // The named person→neighborhood mapping is Art. I location-adjacent
        // data — stricter than /reach, which k-anonymizes mere COUNTS. It
        // ships only when the subject chose a public face (or to themself).
        // Candidacy and office jurisdictions stay public on their own tabs:
        // those are the jurisdictions of the PUBLIC ACT, not the home chain.
        $home = $showSocial ? DB::table('residency_confirmations as rc')
            ->join('jurisdictions as j', 'j.id', '=', 'rc.jurisdiction_id')
            ->where('rc.user_id', (string) $subject->getKey())
            ->where('rc.is_active', true)
            ->whereNull('j.deleted_at')
            ->orderByDesc('j.adm_level')
            ->first(['j.id', 'j.name']) : null;

        return [
            'id' => (string) $subject->getKey(),
            'display' => $display,
            'initials' => $this->initials($display),
            'handle' => $showSocial ? $profile?->handle : null,
            'bio' => $showSocial ? $profile?->bio : null,
            'visibility' => $isSelf ? $profile?->visibility : null,
            'office' => $offices[0]['title'] ?? null,
            'home' => $home === null ? null : ['id' => (string) $home->id, 'name' => $home->name],
            // Counts of FOLLOWS (social plane, the subject's chosen public
            // face) — not a civic score. PI-6 governs civic records; these
            // render only when the subject's profile is public.
            'followCounts' => $showSocial && $profile !== null ? [
                'followers' => SocialFollow::query()
                    ->where('target_type', SocialFollow::TARGET_USER)
                    ->where('target_id', (string) $subject->getKey())
                    ->count(),
                'following' => SocialFollow::query()
                    ->where('follower_user_id', (string) $subject->getKey())
                    ->where('target_type', SocialFollow::TARGET_USER)
                    ->count(),
            ] : null,
        ];
    }

    /** @return list<array{id: string, election_id: string, race_label: string, status: string}> */
    private function candidaciesFor(User $subject): array
    {
        return Candidacy::query()
            ->where('user_id', (string) $subject->getKey())
            ->with([
                'election:id,kind,status,jurisdiction_id',
                'election.jurisdiction:id,name',
                'race:id,election_id,jurisdiction_id,district_id,seat_kind,seats,finalist_count,status',
                'race.jurisdiction:id,name',
                'race.district',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Candidacy $c) => [
                'id' => (string) $c->id,
                'election_id' => (string) $c->election_id,
                'race_label' => $c->race !== null
                    ? ElectionController::raceLabel($c->race)
                    : ($c->election?->jurisdiction?->name ?? 'Awaiting race binding (F-ELB-002)'),
                'status' => $c->status,
            ])
            ->values()
            ->all();
    }

    /** The focused candidacy's full panel: ?candidacy=<uuid>, else the newest. */
    private function focusedPanel(Request $request, User $subject, array $candidacies, ?User $viewer): ?array
    {
        if ($candidacies === []) {
            return null;
        }

        $requested = self::queryString($request, 'candidacy');
        $ids = array_column($candidacies, 'id');
        $focus = in_array($requested, $ids, true) ? $requested : $ids[0];

        $model = Candidacy::query()->find($focus);

        return $model === null ? null : $this->candidacyPanel->for($model, $viewer) + ['focus' => $focus];
    }

    /**
     * Offices held — public power is public (Art. II/III/IV). Legislature
     * seats, executive seats, judicial seats; each row links its
     * institution's own page.
     *
     * @return list<array{kind: string, title: string, jurisdiction: ?string, status: string, since: ?string, until: ?string, is_speaker: bool, href: ?string}>
     */
    private function officesFor(User $subject): array
    {
        $userId = (string) $subject->getKey();

        $legislative = DB::table('legislature_members as lm')
            ->join('legislatures as l', 'l.id', '=', 'lm.legislature_id')
            ->join('jurisdictions as j', 'j.id', '=', 'l.jurisdiction_id')
            ->where('lm.user_id', $userId)
            ->whereIn('lm.status', ['elected', 'seated'])
            ->whereNull('lm.deleted_at')
            ->whereNull('l.deleted_at')
            ->orderBy('j.adm_level')
            ->get(['l.id as legislature_id', 'j.name as jurisdiction', 'lm.status', 'lm.seat_type', 'lm.seated_on', 'lm.term_ends_on', 'lm.is_speaker'])
            ->map(fn ($row) => [
                'kind' => 'legislature',
                'title' => ($row->is_speaker ? 'Speaker · ' : 'Representative · ').$row->jurisdiction.' legislature',
                'jurisdiction' => $row->jurisdiction,
                'status' => $row->status,
                'since' => $row->seated_on,
                'until' => $row->term_ends_on,
                'is_speaker' => (bool) $row->is_speaker,
                'href' => '/legislatures/'.$row->legislature_id,
            ]);

        $executive = DB::table('executive_members as em')
            ->join('executives as e', 'e.id', '=', 'em.executive_id')
            ->join('jurisdictions as j', 'j.id', '=', 'e.jurisdiction_id')
            ->where('em.user_id', $userId)
            ->where('em.status', 'seated')
            ->whereNull('em.deleted_at')
            ->whereNull('e.deleted_at')
            ->orderBy('j.adm_level')
            ->get(['j.name as jurisdiction', 'em.role', 'em.joined_at', 'e.type'])
            ->map(fn ($row) => [
                'kind' => 'executive',
                'title' => ($row->role === 'advisor' ? 'Executive advisor · ' : 'Executive · ').$row->jurisdiction,
                'jurisdiction' => $row->jurisdiction,
                'status' => 'seated',
                'since' => $row->joined_at,
                'until' => null,
                'is_speaker' => false,
                'href' => null,
            ]);

        $judicial = DB::table('judicial_seats as js')
            ->join('judiciaries as jd', 'jd.id', '=', 'js.judiciary_id')
            ->join('jurisdictions as j', 'j.id', '=', 'jd.jurisdiction_id')
            ->where('js.user_id', $userId)
            ->where('js.status', 'seated')
            ->whereNull('js.deleted_at')
            ->whereNull('jd.deleted_at')
            ->orderBy('j.adm_level')
            ->get(['j.name as jurisdiction', 'jd.court_name', 'js.term_starts_on', 'js.term_ends_on'])
            ->map(fn ($row) => [
                'kind' => 'judicial',
                'title' => 'Judge · '.$row->court_name.' · '.$row->jurisdiction,
                'jurisdiction' => $row->jurisdiction,
                'status' => 'seated',
                'since' => $row->term_starts_on,
                'until' => $row->term_ends_on,
                'is_speaker' => false,
                'href' => null,
            ]);

        return [...$legislative->all(), ...$executive->all(), ...$judicial->all()];
    }

    /**
     * The public Record tab: milestone civic actions off the audit chain,
     * the confirmed associations (subject's-choice gated), and
     * endorsements GIVEN that the endorser chose to make public.
     *
     * The actions slice teaches "residency, participation, offices,
     * filings" (the shipped Learn copy), so it spans the public-act
     * modules — elections, residency, and acts of public office
     * (legislature / judiciary / executive). 'organizations' stays OUT:
     * org membership is private association (Art. I), only endorsements
     * chosen public appear. Location-adjacent events stay off entirely —
     * pings, relocation, TRAVELLING declarations: "this person is away
     * from home, timestamped" is exactly what Art. I refuses to publish
     * about a private person.
     */
    private function recordFor(User $subject, bool $isSelf, bool $isPublicProfile): array
    {
        $userId = (string) $subject->getKey();

        $actions = AuditEntry::query()
            ->where('actor_user_id', $userId)
            ->where('rejected', false)
            ->whereIn('module', ['elections', 'residency', 'legislature', 'judiciary', 'executive'])
            ->where('event', 'not like', '%ping%')
            ->where('event', 'not like', '%travel%')
            ->where('event', 'not like', '%relocat%')
            ->orderByDesc('seq')
            ->limit(20)
            ->get()
            ->map(fn (AuditEntry $entry) => [
                'seq' => $entry->seq,
                'date' => $entry->occurred_at?->toIso8601String(),
                'label' => $entry->event.($entry->ref !== null ? " · {$entry->ref}" : ''),
            ])
            ->all();

        // The full named residency chain is the "your choice to show"
        // class, same gate as the head-card home (Art. I).
        $associations = ($isSelf || $isPublicProfile) ? DB::table('residency_confirmations as rc')
            ->join('jurisdictions as j', 'j.id', '=', 'rc.jurisdiction_id')
            ->where('rc.user_id', $userId)
            ->where('rc.is_active', true)
            ->whereNull('j.deleted_at')
            ->orderBy('j.adm_level')
            ->get(['j.id', 'j.name', 'j.adm_level'])
            ->map(fn ($row) => ['id' => (string) $row->id, 'name' => $row->name, 'adm_level' => (int) $row->adm_level])
            ->all() : [];

        $given = Endorsement::query()
            // Qualified by hand — candidacies also carries withdrawn_at, so
            // the active() scope would be ambiguous across this join.
            ->where('endorsements.is_active', true)
            ->whereNull('endorsements.withdrawn_at')
            ->where('endorsements.is_public', true)
            ->where('endorsements.endorser_type', Endorsement::ENDORSER_USER)
            ->where('endorsements.endorser_id', $userId)
            ->join('candidacies as c', 'c.id', '=', 'endorsements.candidate_id')
            ->orderByDesc('endorsements.endorsed_at')
            ->get(['c.id as candidacy_id', 'c.user_id as candidate_user_id', 'endorsements.endorsed_at']);

        $names = CandidacyPanel::displayNames($given->pluck('candidate_user_id')->map(fn ($id) => (string) $id)->all());

        return [
            'actions' => $actions,
            'associations' => $associations,
            'endorsementsGiven' => $given->map(fn ($row) => [
                'candidacy_id' => (string) $row->candidacy_id,
                'user_id' => (string) $row->candidate_user_id,
                'name' => $names[(string) $row->candidate_user_id] ?? 'Candidate',
                'endorsed_at' => $row->endorsed_at,
            ])->all(),
        ];
    }

    private function initials(string $display): string
    {
        $clean = ltrim($display, '@');
        $parts = preg_split('/[\s\-_]+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $letters = array_map(fn (string $p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return $letters === [] ? '?' : implode('', $letters);
    }
}

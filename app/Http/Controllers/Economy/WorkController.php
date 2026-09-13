<?php

namespace App\Http\Controllers\Economy;

use App\Domain\Engine\ConstitutionalViolation;
use App\Http\Controllers\Controller;
use App\Services\Economy\LaborBoardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Worker consent and employer review share postings, never private account bindings. */
class WorkController extends Controller
{
    public function __construct(private readonly LaborBoardService $work) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user(), 403);
        $input = $request->validate([
            'tab' => ['nullable', 'in:applications,hiring'], 'organization' => ['nullable', 'uuid'], 'posting' => ['nullable', 'uuid'],
        ]);
        $tab = $input['tab'] ?? 'applications';
        $cursors = [];
        foreach (['organizations', 'postings', 'applications'] as $name) $cursors[$name] = $this->cursor($request, $name.'_cursor');
        $uid = (string) $request->user()->getKey();
        $params = array_filter(['tab' => $tab, 'organization' => $input['organization'] ?? null, 'posting' => $input['posting'] ?? null]);
        foreach (array_keys($cursors) as $name) if ($request->filled($name.'_cursor')) $params[$name.'_cursor'] = $request->query($name.'_cursor');
        $paginate = fn ($query, string $name, array $columns) => $query->select($columns)->orderByDesc('id')->cursorPaginate(20, ['*'], $name.'_cursor', $cursors[$name])
            ->withPath('/economy/work')->appends($params);
        $empty = ['data' => [], 'next' => null, 'previous' => null];
        $props = ['tab' => $tab, 'organizations' => $empty, 'organization' => null, 'postings' => $empty, 'posting' => null, 'applications' => $empty];

        if ($tab === 'hiring') {
            $orgs = $paginate(DB::table('organizations')->where('agent_user_id', $uid)->where('status', 'active')->whereNull('deleted_at'), 'organizations', ['id', 'name']);
            $props['organizations'] = $this->page($orgs, fn ($org) => ['id' => (string) $org->id, 'name' => $org->name,
                'href' => '/economy/work?'.http_build_query(['tab' => 'hiring', 'organization' => $org->id])]);
            if (! empty($input['organization'])) {
                $org = $this->work->assertEmployer($input['organization'], $request->user());
                $props['organization'] = ['id' => (string) $org->id, 'name' => $org->name];
                $postings = $paginate(DB::table('work_postings')->where('organization_id', $org->id)->whereNull('deleted_at'), 'postings', ['id', 'organization_id', 'title', 'terms', 'rate', 'currency_id', 'status']);
                $props['postings'] = $this->page($postings, fn ($posting) => $this->postingRow($posting));
                if (! empty($input['posting'])) {
                    $posting = DB::table('work_postings')->where('organization_id', $org->id)->where('id', $input['posting'])->whereNull('deleted_at')->first();
                    abort_if($posting === null, 404);
                    $props['posting'] = $this->postingRow($posting);
                    $applications = $paginate($this->applications()->where('a.posting_id', $posting->id), 'applications', $this->applicationColumns());
                    $props['applications'] = $this->page($applications, fn ($row) => $this->applicationRow($row, true));
                }
            } elseif (! empty($input['posting'])) {
                abort(422, 'Choose the organization before reviewing a posting.');
            }
        } else {
            // The only restricted lookup resolves the signed-in person's own wallets.
            // No account or binding column is selected or sent to a page.
            $owned = DB::table('economic_account_bindings')->select('account_id')->where('owner_type', 'users')->where('owner_id', $uid);
            if (! (clone $owned)->exists()) return Inertia::render('Economy/Work', $props);
            $applications = $paginate($this->applications()->whereIn('a.applicant_account_id', $owned), 'applications', $this->applicationColumns());
            $props['applications'] = $this->page($applications, fn ($row) => $this->applicationRow($row, false));
        }
        return Inertia::render('Economy/Work', $props);
    }

    public function postJob(Request $request, string $organization): RedirectResponse
    {
        abort_unless($request->user(), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'], 'terms' => ['required', 'string', 'max:10000'],
            'rate' => ['nullable', 'string', 'regex:/^\d{1,18}(\.\d{1,6})?$/'], 'currency_id' => ['nullable', 'uuid', 'exists:currencies,id'],
        ]);
        return $this->action(function () use ($request, $organization, $data) {
            $posting = $this->work->postFor($request->user(), $organization, trim($data['title']), trim($data['terms']), $data['rate'] ?? null, $data['currency_id'] ?? null);
            // The new UUID may sort onto any page. Open it directly so the
            // employer can immediately review the opportunity they published.
            return redirect('/economy/work?'.http_build_query(['tab' => 'hiring', 'organization' => $organization, 'posting' => $posting]));
        }, 'Work posting published.');
    }

    public function closePosting(Request $request, string $posting): RedirectResponse
    {
        abort_unless($request->user(), 403);
        return $this->action(fn () => $this->work->closePosting($posting, $request->user()), 'Posting closed. Pending applicants can still withdraw.');
    }

    public function offer(Request $request, string $application): RedirectResponse
    {
        abort_unless($request->user(), 403);
        $data = $request->validate(['offer_terms' => ['required', 'string', 'max:10000']]);
        return $this->action(fn () => $this->work->offer($application, $request->user(), $data['offer_terms']), 'Offer recorded. The applicant must explicitly accept these terms.');
    }

    public function decline(Request $request, string $application): RedirectResponse
    {
        abort_unless($request->user(), 403);
        return $this->action(fn () => $this->work->decline($application, $request->user()), 'Application declined.');
    }

    public function accept(Request $request, string $application): RedirectResponse
    {
        abort_unless($request->user(), 403);
        return $this->action(fn () => $this->work->accept($application, $request->user()), 'Offer accepted. Your agreement is ready for the organization’s countersignature.');
    }

    public function withdraw(Request $request, string $application): RedirectResponse
    {
        abort_unless($request->user(), 403);
        return $this->action(fn () => $this->work->withdraw($application, $request->user()), 'Application withdrawn.');
    }

    private function action(callable $action, string $message): RedirectResponse
    {
        try { $result = $action(); }
        catch (\InvalidArgumentException|\RuntimeException $error) {
            // Only deliberate workflow refusals become inline text. Infrastructure
            // exceptions may include SQL/bindings and must use the normal handler.
            if ($error instanceof ConstitutionalViolation || ! in_array($error::class, [\RuntimeException::class, \InvalidArgumentException::class], true)) throw $error;
            return back()->withErrors(['work' => $error->getMessage()]);
        }
        return ($result instanceof RedirectResponse ? $result : back())->with('status', $message);
    }

    private function applications()
    {
        return DB::table('work_applications as a')->join('work_postings as p', 'p.id', '=', 'a.posting_id')
            ->join('organizations as o', 'o.id', '=', 'p.organization_id');
    }

    private function applicationColumns(): array
    {
        return ['a.id as id', 'a.note', 'a.status', 'a.offered_at', 'a.offer_terms', 'a.org_contract_id', 'a.created_at',
            'p.title', 'p.status as posting_status', 'p.deleted_at as posting_deleted_at', 'o.name as organization_name',
            'o.status as organization_status', 'o.deleted_at as organization_deleted_at'];
    }

    private function applicationRow(object $row, bool $hiring): array
    {
        $pending = $row->status === 'applied' && $row->org_contract_id === null;
        $open = $row->posting_status === 'open' && $row->posting_deleted_at === null
            && $row->organization_status === 'active' && $row->organization_deleted_at === null;
        return ['id' => (string) $row->id, 'title' => $row->title, 'organization_name' => $row->organization_name,
            'note' => $row->note, 'status' => $row->status, 'created_at' => $row->created_at,
            'offered_at' => $row->offered_at, 'offer_terms' => $row->offer_terms, 'posting_status' => $row->posting_status,
            'canAccept' => ! $hiring && $pending && $open && $row->offered_at !== null && ! empty($row->offer_terms),
            'canWithdraw' => ! $hiring && $pending, 'canOffer' => $hiring && $pending && $open && $row->offered_at === null,
            'canDecline' => $hiring && $pending && $open,
            'agreementHref' => $row->org_contract_id ? '/economy/agreements/'.$row->org_contract_id : null];
    }

    private function postingRow(object $posting): array
    {
        return ['id' => (string) $posting->id, 'title' => $posting->title, 'terms' => $posting->terms,
            'rate' => $posting->rate, 'currency_id' => $posting->currency_id, 'status' => $posting->status,
            'href' => '/economy/work?'.http_build_query(['tab' => 'hiring', 'organization' => $posting->organization_id, 'posting' => $posting->id])];
    }

    private function page($page, callable $map): array
    {
        return ['data' => array_map($map, $page->items()), 'next' => $page->nextPageUrl(), 'previous' => $page->previousPageUrl()];
    }

    private function cursor(Request $request, string $name): ?Cursor
    {
        $data = $request->validate([$name => ['nullable', 'string', 'max:1024']]);
        $encoded = $data[$name] ?? null;
        if ($encoded === null || $encoded === '') return null;
        $decoded = json_decode(base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true);
        if (! is_array($decoded) || count($decoded) !== 2 || ! is_bool($decoded['_pointsToNextItems'] ?? null)
            || ! is_string($decoded['id'] ?? null) || ! Str::isUuid($decoded['id'])) {
            throw ValidationException::withMessages([$name => 'This page link is invalid. Open the work workspace again.']);
        }
        return new Cursor(['id' => $decoded['id']], $decoded['_pointsToNextItems']);
    }
}

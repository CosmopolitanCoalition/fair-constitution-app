<?php

namespace App\Services\Economy;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Voluntary help records. No payment, contract, office or entitlement is created. */
class AssistanceService
{
    public function __construct(private readonly AccountService $accounts) {}

    public function ownedAccounts(User $actor): Builder
    {
        return DB::table('economic_account_bindings')->select('account_id')
            ->where('owner_type', 'users')->where('owner_id', $actor->getKey());
    }

    public function owns(User $actor, ?string $account): bool
    {
        return $account !== null && $this->ownedAccounts($actor)->where('account_id', $account)->exists();
    }

    /** The existing restricted account service resolves only the authenticated owner. */
    public function participationAccount(User $actor): ?string
    {
        $root = DB::table('jurisdictions')->whereNull('parent_id')->whereNull('deleted_at')->value('id');
        $currency = $root === null ? null : DB::table('currencies')->where('jurisdiction_id', $root)->whereNull('deleted_at')->value('id');
        if ($currency === null) return null;
        $account = $this->accounts->accountIdFor('users', (string) $actor->getKey(), (string) $currency);
        return $account !== null && DB::table('economic_accounts')->where('id', $account)->where('status', 'open')->whereNull('deleted_at')->exists()
            ? $account : null;
    }

    public function visible(User $actor, string $id, bool $lock = false): object
    {
        $query = DB::table('assistance_requests')->where('id', $id)->whereNull('deleted_at');
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_if($row === null, 404);
        // No jurisdiction-sharing policy exists. Do not infer one from its label.
        abort_unless($row->privacy === 'public' || $this->owns($actor, $row->requester_account_id)
            || $this->owns($actor, $row->responder_account_id), 404);
        return $row;
    }

    public function create(User $actor, string $title, string $need, string $privacy): string
    {
        $title = trim($title);
        $need = trim($need);
        if ($title === '' || $need === '' || ! in_array($privacy, ['private', 'public'], true)) {
            throw new InvalidArgumentException('Add a title, describe the help needed, and choose who can see the request.');
        }
        $account = $this->participationAccount($actor);
        if ($account === null) throw new InvalidArgumentException('An open personal wallet is required to keep your identity separate from this request.');
        $id = (string) Str::uuid();
        DB::table('assistance_requests')->insert([
            'id' => $id, 'requester_account_id' => $account, 'title' => $title, 'need' => $need,
            'privacy' => $privacy, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    public function publish(User $actor, string $id): void
    {
        $this->changeOwned($actor, $id, function ($row) {
            if ($row->privacy !== 'private' || $row->status !== 'open') throw new InvalidArgumentException('Only an open private draft can be published.');
            DB::table('assistance_requests')->where('id', $row->id)->update(['privacy' => 'public', 'updated_at' => now()]);
        });
    }

    public function withdraw(User $actor, string $id): void
    {
        $this->changeOwned($actor, $id, function ($row) {
            $this->assertUnfinished($row);
            DB::table('assistance_requests')->where('id', $row->id)->update(['status' => 'withdrawn', 'updated_at' => now()]);
        });
    }

    public function resolve(User $actor, string $id): void
    {
        $this->changeOwned($actor, $id, function ($row) {
            $this->assertUnfinished($row);
            DB::table('assistance_requests')->where('id', $row->id)->update(['status' => 'resolved', 'updated_at' => now()]);
        });
    }

    public function respond(User $actor, string $id, string $message): string
    {
        $message = trim($message);
        if ($message === '') throw new InvalidArgumentException('Describe the help you can offer.');
        return DB::transaction(function () use ($actor, $id, $message) {
            $row = $this->visible($actor, $id, true);
            if ($row->privacy !== 'public' || $row->status !== 'open') throw new InvalidArgumentException('This request is not accepting offers of help.');
            if ($this->owns($actor, $row->requester_account_id)) throw new InvalidArgumentException('You cannot offer help on your own request.');
            $account = $this->participationAccount($actor);
            if ($account === null) throw new InvalidArgumentException('An open personal wallet is required to offer help.');
            if (DB::table('assistance_responses')->where('request_id', $id)->whereIn('responder_account_id', $this->ownedAccounts($actor))->exists()) {
                throw new InvalidArgumentException('Your offer is already recorded, including after withdrawal.');
            }
            $response = (string) Str::uuid();
            DB::table('assistance_responses')->insert([
                'id' => $response, 'request_id' => $id, 'responder_account_id' => $account,
                'message' => $message, 'status' => 'offered', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $response;
        });
    }

    public function match(User $actor, string $id, string $response): void
    {
        $this->changeOwned($actor, $id, function ($row) use ($response) {
            if ($row->status !== 'open') throw new InvalidArgumentException('This request is no longer open for matching.');
            $offer = $this->response($row->id, $response);
            if ($offer->status !== 'offered') throw new InvalidArgumentException('That offer is no longer available.');
            DB::table('assistance_requests')->where('id', $row->id)->update([
                'status' => 'matched', 'responder_account_id' => $offer->responder_account_id, 'updated_at' => now(),
            ]);
            DB::table('assistance_responses')->where('id', $offer->id)->update(['status' => 'accepted', 'updated_at' => now()]);
        });
    }

    public function withdrawResponse(User $actor, string $id, string $response): void
    {
        DB::transaction(function () use ($actor, $id, $response) {
            $row = $this->visible($actor, $id, true);
            $offer = $this->response($id, $response);
            abort_unless($this->owns($actor, $offer->responder_account_id), 403);
            $this->assertUnfinished($row);
            if (! in_array($offer->status, ['offered', 'accepted'], true)) throw new InvalidArgumentException('That offer is already withdrawn.');
            if ($offer->status === 'accepted') {
                if ($row->status !== 'matched' || $row->responder_account_id !== $offer->responder_account_id) {
                    throw new InvalidArgumentException('That offer is not the current match.');
                }
                DB::table('assistance_requests')->where('id', $id)->update(['status' => 'open', 'responder_account_id' => null, 'updated_at' => now()]);
            }
            DB::table('assistance_responses')->where('id', $response)->update(['status' => 'withdrawn', 'updated_at' => now()]);
        });
    }

    private function changeOwned(User $actor, string $id, callable $action): void
    {
        DB::transaction(function () use ($actor, $id, $action) {
            $row = $this->visible($actor, $id, true);
            abort_unless($this->owns($actor, $row->requester_account_id), 403);
            $action($row);
        });
    }

    private function response(string $id, string $response): object
    {
        $row = DB::table('assistance_responses')->where('id', $response)->where('request_id', $id)->lockForUpdate()->first();
        abort_if($row === null, 404);
        return $row;
    }

    private function assertUnfinished(object $row): void
    {
        if (! in_array($row->status, ['open', 'matched'], true)) throw new InvalidArgumentException('This request is already finished.');
    }
}

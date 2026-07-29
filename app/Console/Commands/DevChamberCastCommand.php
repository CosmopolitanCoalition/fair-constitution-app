<?php

namespace App\Console\Commands;

use App\Http\Controllers\Dev\ChamberCastController;
use App\Http\Middleware\DevTimeControlsEnabled;
use App\Models\ChamberVote;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * P4's CLI twin (UI↔CLI parity, standing order — ruling 2026-07-28 §10
 * item 10). POST /dev/chamber/cast existed with no terminal door; the
 * clock controls had artisan twins and the chamber cast did not.
 *
 *   php artisan dev:chamber-cast                       # list open votes
 *   php artisan dev:chamber-cast <vote> --yes=40 --no=5
 *   php artisan dev:chamber-cast <vote> --no=30 --lane=type_b
 *
 * IT SUPPLIES BALLOTS. IT DOES NOT SUPPLY OUTCOMES. This command is a
 * thin console skin over the ONE ChamberCastController — the same
 * validation, the same engine path, the same audit marker, the same
 * ChamberCastIsBallotsOnlyTest pins. Parity of capability, never of
 * exposure: the same DevTimeControlsEnabled gate refuses here first,
 * exactly as it does on the route.
 */
class DevChamberCastCommand extends Command
{
    protected $signature = 'dev:chamber-cast
        {vote? : The open chamber vote to ballot; omit to list what is open}
        {--yes=0 : How many yes ballots to file}
        {--no=0 : How many no ballots to file}
        {--abstain=0 : How many abstain ballots to file}
        {--lane= : Restrict the bloc to one chamber (type_a or type_b) — lets dual agreement fail on purpose}';

    protected $description = 'Playtest control: bloc-cast a chamber vote through the real engine (ballots only, never outcomes)';

    public function handle(): int
    {
        if ($reason = DevTimeControlsEnabled::refusalReason()) {
            $this->error($reason);

            return self::FAILURE;
        }

        $voteId = $this->argument('vote');

        if ($voteId === null) {
            return $this->listOpen();
        }

        // ONE implementation: synthesize the request and invoke the real
        // controller, so the CLI and the HTTP door can never drift apart on
        // what a bloc cast is allowed to do.
        $params = [
            'vote_id' => $voteId,
            'yes' => (int) $this->option('yes'),
            'no' => (int) $this->option('no'),
            'abstain' => (int) $this->option('abstain'),
        ];

        if ($this->option('lane') !== null) {
            $params['lane'] = $this->option('lane');
        }

        $request = Request::create('/dev/chamber/cast', 'POST', $params);

        try {
            $response = app(ChamberCastController::class)($request);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->error("  {$field}: ".implode(' ', $messages));
            }

            return self::FAILURE;
        }

        $data = $response->getData(true);

        if (isset($data['error'])) {
            $this->error('  '.$data['error']);

            return self::FAILURE;
        }

        $this->line(sprintf(
            '  requested y/n/a %d/%d/%d — cast %d/%d/%d across %d eligible member(s)',
            $data['requested']['yes'], $data['requested']['no'], $data['requested']['abstain'],
            $data['cast']['yes'], $data['cast']['no'], $data['cast']['abstain'],
            $data['eligible'],
        ));

        foreach ($data['tallies'] as $lane => $values) {
            $this->line(sprintf(
                '  %-8s yes %d · no %d · abstain %d',
                $lane,
                $values['yes'] ?? 0, $values['no'] ?? 0, $values['abstain'] ?? 0,
            ));
        }

        foreach ($data['refusals'] as $refusal) {
            $this->warn('  refused: '.$refusal);
        }

        // Whatever the ENGINE decided — this command reports it, never made it.
        $this->info(sprintf(
            '  vote is %s%s — %s',
            $data['status'],
            $data['outcome'] ? " ({$data['outcome']})" : '',
            $data['ballots_only'],
        ));

        return self::SUCCESS;
    }

    /** What is open, newest first — so the operator can pick one. */
    private function listOpen(): int
    {
        // chamber_votes carries NO deleted_at (soft deletes are a convention,
        // not a guarantee — CLAUDE.md).
        $rows = DB::table('chamber_votes as cv')
            ->leftJoin('jurisdictions as j', 'j.id', '=', 'cv.jurisdiction_id')
            ->where('cv.status', ChamberVote::STATUS_OPEN)
            ->orderByDesc('cv.opened_at')
            ->limit(50)
            ->get(['cv.id', 'cv.vote_type', 'cv.stage', 'cv.bicameral', 'cv.opened_at', 'j.name as jurisdiction']);

        if ($rows->isEmpty()) {
            $this->line('  <fg=gray>No vote is open. Nothing is waiting to be balloted.</>');

            return self::SUCCESS;
        }

        $this->table(
            ['vote', 'type', 'stage', 'chambers', 'place', 'opened'],
            $rows->map(static fn ($r): array => [
                $r->id,
                $r->vote_type,
                $r->stage ?? '—',
                $r->bicameral ? 'bicameral' : 'single',
                $r->jurisdiction ?? '—',
                $r->opened_at,
            ])->all(),
        );

        $this->line('  Ballot one with <options=bold>php artisan dev:chamber-cast {vote} --yes=N --no=M</>');

        return self::SUCCESS;
    }
}

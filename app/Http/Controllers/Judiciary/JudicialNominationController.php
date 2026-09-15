<?php

namespace App\Http\Controllers\Judiciary;

use App\Domain\Engine\ConstitutionalEngine;
use App\Http\Controllers\Controller;
use App\Models\Judiciary;
use App\Models\Legislature;
use App\Services\Judiciary\JudicialNominationService;
use Illuminate\Http\Request;

final class JudicialNominationController extends Controller
{
    public function __construct(private ConstitutionalEngine $engine) {}

    public function nominate(Request $request, Judiciary $judiciary)
    {
        $data = $request->validate(['legislature_id' => 'required|uuid', 'seat_id' => 'required|uuid', 'nominee_user_id' => 'required|uuid', 'statement' => 'required|string|max:10000']);
        $leg = Legislature::query()->findOrFail($data['legislature_id']);
        $this->engine->file(JudicialNominationService::NOMINATE_FORM, $request->user(), $data + ['judiciary_id' => $judiciary->id, 'jurisdiction_id' => $leg->jurisdiction_id]);

        return redirect('/judiciaries/'.$judiciary->id.'#judicial-proposals')->with('status', __('Nomination proposal filed. The nominating body’s vote is now open.'));
    }

    public function designate(Request $request, Judiciary $judiciary)
    {
        $data = $request->validate(['committee_id' => 'required|uuid', 'statement' => 'required|string|max:10000']);
        $this->engine->file(JudicialNominationService::DESIGNATE_FORM, $request->user(), $data + ['judiciary_id' => $judiciary->id,
            'legislature_id' => $judiciary->source_legislature_id, 'jurisdiction_id' => $judiciary->jurisdiction_id]);

        return redirect('/judiciaries/'.$judiciary->id.'#judicial-proposals')->with('status', __('Committee designation proposed. The creating legislature’s supermajority vote is now open.'));
    }
}

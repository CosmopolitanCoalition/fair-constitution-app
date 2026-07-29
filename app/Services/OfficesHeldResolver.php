<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every public office a user holds — legislative seat, executive membership,
 * judicial seat — as one ordered list, most-senior-jurisdiction first.
 *
 * This is a lift of PersonProfileController::officesFor(), pulled out unchanged
 * so the PUBLIC person profile (/people) and the SELF record (/civic/record)
 * read office from ONE place and cannot drift apart — they were about to compute
 * the same three joins in two controllers. Sits beside RepresentativesResolver,
 * the same shape of shared read.
 *
 * Every row: { kind, title, jurisdiction, status, since, until, is_speaker, href }.
 * A DERIVED read only — it owns no table and writes nothing; the seat tables
 * (legislature_members / executive_members / judicial_seats) are each owned by
 * their institution's own service.
 */
class OfficesHeldResolver
{
    /** @return list<array{kind:string,title:string,jurisdiction:string,status:string,since:?string,until:?string,is_speaker:bool,href:?string}> */
    public function forUser(User $subject): array
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
}

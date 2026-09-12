<?php

namespace App\Http\Controllers\Civic;

use App\Http\Controllers\Controller;
use App\Models\Jurisdiction;
use App\Support\JurisdictionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class RoleExplorerController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->validate(['jurisdiction' => ['nullable', 'string', 'max:255']]);
        $key = $request->query('jurisdiction');
        $place = $key ? Jurisdiction::query()
            ->where(Str::isUuid($key) ? 'id' : 'slug', $key)
            ->firstOrFail(['id', 'name', 'slug', 'parent_id', 'adm_level']) : null;
        $destinations = [];

        if ($place) {
            // Each lookup is restricted to one indexed jurisdiction. Never
            // inventory the whole world to populate a navigation workspace.
            $id = (string) $place->id;
            $institution = static fn (string $table) => DB::table($table)
                ->where('jurisdiction_id', $id)->whereNull('deleted_at')
                ->orderByDesc('created_at')->value('id');
            $legislature = $institution('legislatures');
            $judiciary = $institution('judiciaries');
            $board = DB::table('election_boards')->where('jurisdiction_id', $id)
                ->whereNull('deleted_at')->where('status', 'active')->value('id');
            $election = DB::table('elections')->where('jurisdiction_id', $id)
                ->whereNull('deleted_at')->where('status', '!=', 'cancelled')
                ->orderByRaw("CASE WHEN status IN ('certified', 'final') THEN 1 ELSE 0 END")
                ->orderByDesc('created_at')->orderBy('id')->value('id');
            $destinations = [
                'place' => '/jurisdictions/'.$place->slug,
                'rooms' => '/civic/commons/square?jurisdiction='.$id,
                'halls' => '/civic/commons/halls?jurisdiction='.$id,
                'election' => $election ? '/elections/'.$election : null,
                'board' => $board ? '/board?board='.$board : null,
                'court' => $judiciary ? '/judiciaries/'.$judiciary : null,
                'docket' => $judiciary ? '/judiciaries/'.$judiciary.'/docket' : null,
            ];
            foreach (['chamber', 'session', 'bills', 'committees', 'speaker', 'districts'] as $surface) {
                $destinations[$surface] = $legislature ? '/legislatures/'.$legislature.'/'.$surface : null;
            }
        }

        return Inertia::render('Civic/RoleExplorer', array_merge([
            'place' => $place ? JurisdictionContext::chip($place) : null,
            'destinations' => $destinations,
        ], $place ? ['jurisdictionContext' => JurisdictionContext::for($place)] : []));
    }
}

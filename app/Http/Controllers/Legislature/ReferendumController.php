<?php

namespace App\Http\Controllers\Legislature;

use App\Http\Controllers\Controller;

/**
 * FE-C9 ROUTE ANCHOR — Referendums (PHASE_C_DESIGN_frontend.md §B.9).
 *
 * The Phase C route table registers every surface up front (the Phase B
 * precedent); this skeleton exists so the routes resolve and
 * `route:list` stays green. REPLACE with the real controller in the
 * batch-3 WI (FE-C9) — every method answers 501 until then.
 */
class ReferendumController extends Controller
{
    public function index()
    {
        abort(501, 'Referendums surface lands with FE-C9 (Phase C batch 3).');
    }

    public function store()
    {
        abort(501, 'F-LEG-023 referendum delegation lands with FE-C9 (Phase C batch 3).');
    }

    public function modify()
    {
        abort(501, 'F-LEG-034 referendum-act modification (CLK-19 gate) lands with FE-C9 (Phase C batch 3).');
    }
}

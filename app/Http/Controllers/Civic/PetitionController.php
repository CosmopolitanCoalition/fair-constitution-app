<?php

namespace App\Http\Controllers\Civic;

use App\Http\Controllers\Controller;

/**
 * FE-C10 ROUTE ANCHOR — Petitions (PHASE_C_DESIGN_frontend.md
 * §B.12/§B.13). Skeleton so the up-front Phase C route table resolves;
 * REPLACE with the real controller in the batch-3 WI (FE-C10).
 */
class PetitionController extends Controller
{
    public function index()
    {
        abort(501, 'Petitions surface lands with FE-C10 (Phase C batch 3).');
    }

    public function store()
    {
        abort(501, 'F-IND-009 petition creation lands with FE-C10 (Phase C batch 3).');
    }

    public function show()
    {
        abort(501, 'Petition detail lands with FE-C10 (Phase C batch 3).');
    }

    public function sign()
    {
        abort(501, 'F-IND-010 petition signature lands with FE-C10 (Phase C batch 3).');
    }

    public function revoke()
    {
        abort(501, 'F-IND-010 signature revocation lands with FE-C10 (Phase C batch 3).');
    }
}

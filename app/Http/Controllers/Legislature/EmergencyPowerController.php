<?php

namespace App\Http\Controllers\Legislature;

use App\Http\Controllers\Controller;

/**
 * FE-C9 ROUTE ANCHOR — Emergency powers (PHASE_C_DESIGN_frontend.md
 * §B.10). Skeleton so the up-front Phase C route table resolves;
 * REPLACE with the real controller in the batch-3 WI (FE-C9).
 */
class EmergencyPowerController extends Controller
{
    public function index()
    {
        abort(501, 'Emergency-powers surface lands with FE-C9 (Phase C batch 3).');
    }

    public function store()
    {
        abort(501, 'F-LEG-024 emergency declaration lands with FE-C9 (Phase C batch 3).');
    }

    public function renew()
    {
        abort(501, 'F-LEG-025 emergency renewal lands with FE-C9 (Phase C batch 3).');
    }
}

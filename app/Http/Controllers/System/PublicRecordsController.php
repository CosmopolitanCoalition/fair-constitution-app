<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;

/**
 * FE-C11 ROUTE ANCHOR — Public records (PHASE_C_DESIGN_frontend.md
 * §B.15/§D). Skeleton so the up-front Phase C route table resolves;
 * REPLACE with the real controller in the batch-3 WI (FE-C11). The
 * public_records TABLE is live since batch 1 — only the reader surface
 * is pending.
 */
class PublicRecordsController extends Controller
{
    public function index()
    {
        abort(501, 'Public-records surface lands with FE-C11 (Phase C batch 3).');
    }

    public function statement()
    {
        abort(501, 'F-LEG-006 statement composer lands with FE-C11 (Phase C batch 3).');
    }
}

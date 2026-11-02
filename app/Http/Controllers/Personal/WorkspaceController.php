<?php

namespace App\Http\Controllers\Personal;

use App\Http\Controllers\Controller;
use Inertia\Inertia;

/** The four workspace screens. Each page loads its own data from the workspace API. */
class WorkspaceController extends Controller
{
    public function calendar()
    {
        return Inertia::render('Personal/Calendar');
    }

    public function tasks()
    {
        return Inertia::render('Personal/Tasks');
    }

    public function contacts()
    {
        return Inertia::render('Personal/Contacts');
    }

    public function inbox()
    {
        return Inertia::render('Personal/Inbox');
    }
}

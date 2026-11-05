<?php

namespace App\Http\Controllers\Node;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** W-0523 - the drawer posts what the person said. The reply comes from AssistantService. */
class AssistantChatController extends Controller
{
    public function chat(Request $request, AssistantService $assistant): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['nullable', 'string', 'max:12'],
            'history.*.text' => ['nullable', 'string', 'max:4000'],
        ]);

        return response()->json($assistant->reply((string) $request->user()->id, $data['message'], $data['history'] ?? []));
    }
}

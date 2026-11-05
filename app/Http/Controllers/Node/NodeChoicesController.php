<?php

namespace App\Http\Controllers\Node;

use App\Http\Controllers\Controller;
use App\Services\Node\NodeChoices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** W-0521 - the My node screens read and save their choices here. Each person sees only their own. */
class NodeChoicesController extends Controller
{
    public function index(Request $request, NodeChoices $choices): JsonResponse
    {
        return response()->json((object) $choices->all((string) $request->user()->id));
    }

    public function update(Request $request, string $key, NodeChoices $choices): JsonResponse
    {
        abort_unless(in_array($key, NodeChoices::KEYS, true), 404);
        $value = $request->json()->all();
        try {
            $choices->put((string) $request->user()->id, $key, $value);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['saved' => true]);
    }
}

<?php

namespace App\Http\Controllers\Personal\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Native tasks and projects. Replaces Planner and To Do as the place tasks live. */
class TasksApiController extends Controller
{
    public function projects(Request $r)
    {
        return ['data' => DB::table('task_projects')->where('user_id', $r->user()->id)->orderBy('name')->get()];
    }

    public function createProject(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120']);
        $id = (string) Str::uuid();
        DB::table('task_projects')->insert($d + ['id' => $id, 'user_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => DB::table('task_projects')->find($id)], 201);
    }

    public function index(Request $r)
    {
        $d = $r->validate(['status' => 'nullable|in:open,done,cancelled', 'project_id' => 'nullable|uuid', 'due_before' => 'nullable|date', 'limit' => 'nullable|integer|min:1|max:500']);
        $q = DB::table('personal_tasks')->where('user_id', $r->user()->id)->whereNull('deleted_at')
            ->where('status', $d['status'] ?? 'open')
            ->when($d['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', $v))
            ->when($d['due_before'] ?? null, fn ($q, $v) => $q->where('due_at', '<=', $v));

        return ['data' => $q->orderByRaw('due_at asc nulls last')->orderBy('priority')->limit($d['limit'] ?? 200)->get()];
    }

    public function store(Request $r)
    {
        $d = $this->rules($r, true);
        $this->checkProject($r, $d);
        $id = (string) Str::uuid();
        DB::table('personal_tasks')->insert($d + ['id' => $id, 'user_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => DB::table('personal_tasks')->find($id)], 201);
    }

    public function update(Request $r, string $id)
    {
        $t = $this->own($r, $id);
        $d = $this->rules($r, false);
        $this->checkProject($r, $d);
        if (($d['status'] ?? null) === 'done' && $t->status !== 'done') {
            $d['completed_at'] = now();
        } elseif (($d['status'] ?? null) === 'open') {
            $d['completed_at'] = null;
        }
        DB::table('personal_tasks')->where('id', $t->id)->update($d + ['updated_at' => now()]);

        return ['data' => DB::table('personal_tasks')->find($t->id)];
    }

    public function destroy(Request $r, string $id)
    {
        $t = $this->own($r, $id);
        DB::table('personal_tasks')->where('id', $t->id)->update(['deleted_at' => now()]);

        return response()->noContent();
    }

    /** Insert or update tasks pulled from Planner or To Do, keyed by (source, external id). */
    public function sync(Request $r)
    {
        $d = $r->validate([
            'source' => 'required|in:microsoft,google', 'tasks' => 'required|array|max:500',
            'tasks.*.external_id' => 'required|string|max:200', 'tasks.*.title' => 'required|string|max:240',
            'tasks.*.status' => 'nullable|in:open,done,cancelled', 'tasks.*.due_at' => 'nullable|date',
        ]);
        foreach ($d['tasks'] as $t) {
            DB::table('personal_tasks')->updateOrInsert(
                ['user_id' => $r->user()->id, 'external_source' => $d['source'], 'external_id' => $t['external_id']],
                array_intersect_key($t, array_flip(['title', 'status', 'due_at'])) + ['deleted_at' => null, 'updated_at' => now()]
                + ['id' => (string) Str::uuid(), 'created_at' => now()],
            );
        }

        return ['synced' => count($d['tasks'])];
    }

    private function rules(Request $r, bool $create): array
    {
        return $r->validate([
            'title' => ($create ? 'required' : 'sometimes').'|string|max:240', 'notes' => 'nullable|string|max:5000',
            'status' => 'nullable|in:open,done,cancelled', 'priority' => 'nullable|integer|between:1,5',
            'due_at' => 'nullable|date', 'remind_at' => 'nullable|date', 'project_id' => 'nullable|uuid', 'repeat_rule' => 'nullable|string|max:60',
        ]);
    }

    private function checkProject(Request $r, array $d): void
    {
        if (! empty($d['project_id'])) {
            abort_unless(DB::table('task_projects')->where('id', $d['project_id'])->where('user_id', $r->user()->id)->exists(), 422, 'Unknown project.');
        }
    }

    private function own(Request $r, string $id): object
    {
        $t = DB::table('personal_tasks')->where('id', $id)->where('user_id', $r->user()->id)->whereNull('deleted_at')->first();
        abort_unless($t, 404);

        return $t;
    }
}

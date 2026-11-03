<?php

namespace App\Http\Controllers\Node;

use App\Http\Controllers\Controller;
use App\Support\NodeCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * The node surfaces: what this box hosts, which datastores a person owns and who may use them,
 * what is shared with peers, which bundled tools to add, and the assistant with its tools and
 * surfaces. Pages read the catalog plus real counts. Choices on these pages are held in the
 * browser until the wiring items in the rubric land.
 */
class NodeController extends Controller
{
    public function home(Request $request)
    {
        return Inertia::render('Node/Home', [
            'node' => $this->nodeFacts(),
            'kits' => $this->kits(),
            'counts' => $this->counts($request->user()->id),
        ]);
    }

    public function data(Request $request)
    {
        $counts = $this->counts($request->user()->id);

        return Inertia::render('Node/Data', [
            'stores' => collect(NodeCatalog::datastores())->map(fn ($s) => $s + ['count' => $s['table'] ? ($counts[$s['table']] ?? null) : null])->all(),
            'audiences' => NodeCatalog::AUDIENCES,
            'rights' => NodeCatalog::ASSISTANT_RIGHTS,
            'after' => NodeCatalog::AFTER_RELATIONSHIP,
            'replication' => NodeCatalog::replication(),
        ]);
    }

    public function sharing(Request $request)
    {
        return Inertia::render('Node/Sharing', [
            'sharing' => NodeCatalog::sharing(),
            'peers' => $this->peers(),
            'audiences' => NodeCatalog::AUDIENCES,
            'stores' => collect(NodeCatalog::datastores())->map(fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'owner' => $s['owner']])->all(),
        ]);
    }

    public function bundles(Request $request)
    {
        return Inertia::render('Node/Bundles', [
            'bundles' => NodeCatalog::bundles(),
            'external' => NodeCatalog::externalConnections(),
            'host' => $this->hostSize(),
        ]);
    }

    public function assistant(Request $request)
    {
        $id = $request->user()->id;

        return Inertia::render('Node/Assistant', [
            'brains' => NodeCatalog::brains(),
            'tiers' => NodeCatalog::tiers(),
            'stores' => collect(NodeCatalog::datastores())->map(fn ($s) => ['id' => $s['id'], 'name' => $s['name'], 'assistant' => $s['assistant'], 'after' => $s['after']])->all(),
            'rights' => NodeCatalog::ASSISTANT_RIGHTS,
            'vault' => ['keys' => $this->countWhere('api_keys', $id, fn ($q) => $q->whereNull('revoked_at')), 'secrets' => $this->countWhere('user_secrets', $id)],
        ]);
    }

    public function tools(Request $request)
    {
        return Inertia::render('Node/AssistantTools', [
            'tools' => NodeCatalog::assistantTools(),
            'classes' => NodeCatalog::safetyClasses(),
            'levels' => NodeCatalog::surfaceLevels(),
            'inventory' => NodeCatalog::jarvisInventory(),
        ]);
    }

    public function surfaces(Request $request)
    {
        return Inertia::render('Node/AssistantSurfaces', [
            'surfaces' => NodeCatalog::assistantSurfaces(),
            'levels' => NodeCatalog::surfaceLevels(),
            'external' => collect(NodeCatalog::externalConnections())->whereIn('id', ['alexa', 'twilio', 'telegram', 'whatsapp'])->values()->all(),
        ]);
    }

    /** Facts about the box that exist today. */
    private function nodeFacts(): array
    {
        $row = Schema::hasTable('instance_settings') ? DB::table('instance_settings')->whereNull('deleted_at')->first() : null;

        return [
            'name' => $row->instance_name ?? null,
            'federation' => (bool) ($row->federation_enabled ?? false),
            'setup_mode' => $row->setup_mode ?? null,
            'map_mode' => $row->map_mode ?? null,
            'setup_done' => (bool) ($row->setup_completed_at ?? false),
            'host' => request()->getHost(),
        ];
    }

    /** The four kits. Individual is always on. Community is on where setup finished. */
    private function kits(): array
    {
        $done = (bool) (Schema::hasTable('instance_settings') ? DB::table('instance_settings')->whereNull('deleted_at')->value('setup_completed_at') : false);

        return [
            ['id' => 'individual', 'label' => 'Individual', 'on' => true, 'locked' => true, 'note' => 'Always on. Every person starts in a household of one.'],
            ['id' => 'household', 'label' => 'Household', 'on' => false, 'locked' => false, 'note' => 'An organization type with presets and succession triggers.'],
            ['id' => 'organization', 'label' => 'Organization', 'on' => true, 'locked' => false, 'note' => 'Members, boards, departments, ledgers.'],
            ['id' => 'community', 'label' => 'Community', 'on' => $done, 'locked' => false, 'note' => 'The simulation or a real community, with the civic engine.'],
        ];
    }

    private function peers(): array
    {
        if (! Schema::hasTable('federation_peers')) {
            return [];
        }

        return DB::table('federation_peers')->orderBy('created_at')->limit(50)->get()->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name ?? $p->url, 'status' => $p->status ?? null,
        ])->all();
    }

    private function counts(string $userId): array
    {
        $out = [];
        foreach (['personal_events', 'personal_tasks', 'crm_contacts', 'mail_messages', 'user_secrets', 'api_keys'] as $t) {
            $out[$t] = $this->countWhere($t, $userId);
        }

        return $out;
    }

    private function countWhere(string $table, string $userId, ?callable $extra = null): ?int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
            return null;
        }
        $q = DB::table($table)->where('user_id', $userId);
        if ($extra) {
            $extra($q);
        }

        return $q->count();
    }

    /** Memory and free disk of the host. */
    private function hostSize(): array
    {
        // The box is the host, not this container: read the host total from meminfo.
        $mem = null;
        $info = is_readable('/proc/meminfo') ? (string) @file_get_contents('/proc/meminfo') : '';
        if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $info, $m)) {
            $mem = (int) round(((int) $m[1]) / 1024);
        }
        $free = @disk_free_space(base_path());

        return ['memory_mb' => $mem, 'disk_free_gb' => $free ? (int) round($free / 1073741824) : null];
    }
}

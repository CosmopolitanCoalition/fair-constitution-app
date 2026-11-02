<?php

namespace App\Http\Controllers\Personal\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Native CRM: contacts, notes, follow-ups and caller identity. */
class CrmApiController extends Controller
{
    public function contacts(Request $r)
    {
        $d = $r->validate(['q' => 'nullable|string|max:120', 'limit' => 'nullable|integer|min:1|max:200']);
        $q = DB::table('crm_contacts')->where('user_id', $r->user()->id)->whereNull('deleted_at')
            ->when($d['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $v).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [strtolower($like)])->orWhereRaw('lower(organization) like ?', [strtolower($like)])->orWhereRaw('lower(email) like ?', [strtolower($like)]));
            });

        return ['data' => $q->orderBy('name')->limit($d['limit'] ?? 50)->get()];
    }

    /** Caller identity: match a phone number or an email to a contact. Returns null data when unknown. */
    public function identify(Request $r)
    {
        $d = $r->validate(['phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:255']);
        abort_if(empty($d['phone']) && empty($d['email']), 422, 'Give a phone or an email.');
        $q = DB::table('crm_contacts')->where('user_id', $r->user()->id)->whereNull('deleted_at');
        if (! empty($d['phone'])) {
            $q->where('phone', self::phone($d['phone']));
        } else {
            $q->whereRaw('lower(email) = ?', [strtolower($d['email'])]);
        }
        $c = $q->first();

        return ['data' => $c, 'known' => $c !== null, 'class' => $c->contact_class ?? 'unknown'];
    }

    public function show(Request $r, string $id)
    {
        $c = $this->own($r, $id);

        return ['data' => $c, 'notes' => DB::table('crm_notes')->where('contact_id', $c->id)->orderByDesc('created_at')->limit(50)->get(),
            'followups' => DB::table('crm_followups')->where('contact_id', $c->id)->whereNull('done_at')->orderBy('due_at')->get()];
    }

    public function store(Request $r)
    {
        $d = $this->rules($r, true);
        $id = (string) Str::uuid();
        DB::table('crm_contacts')->insert($this->clean($d) + ['id' => $id, 'user_id' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => DB::table('crm_contacts')->find($id)], 201);
    }

    public function update(Request $r, string $id)
    {
        $c = $this->own($r, $id);
        DB::table('crm_contacts')->where('id', $c->id)->update($this->clean($this->rules($r, false)) + ['updated_at' => now()]);

        return ['data' => DB::table('crm_contacts')->find($c->id)];
    }

    public function addNote(Request $r, string $id)
    {
        $c = $this->own($r, $id);
        $d = $r->validate(['body' => 'required|string|max:10000', 'channel' => 'nullable|in:note,call,text,email,meeting']);
        $nid = (string) Str::uuid();
        DB::table('crm_notes')->insert($d + ['id' => $nid, 'user_id' => $r->user()->id, 'contact_id' => $c->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => DB::table('crm_notes')->find($nid)], 201);
    }

    public function followups(Request $r)
    {
        return ['data' => DB::table('crm_followups as f')->join('crm_contacts as c', 'c.id', '=', 'f.contact_id')
            ->where('f.user_id', $r->user()->id)->whereNull('f.done_at')->orderByRaw('f.due_at asc nulls last')->limit(200)
            ->get(['f.id', 'f.what', 'f.due_at', 'f.contact_id', 'c.name as contact_name'])];
    }

    public function addFollowup(Request $r, string $id)
    {
        $c = $this->own($r, $id);
        $d = $r->validate(['what' => 'required|string|max:240', 'due_at' => 'nullable|date']);
        $fid = (string) Str::uuid();
        DB::table('crm_followups')->insert($d + ['id' => $fid, 'user_id' => $r->user()->id, 'contact_id' => $c->id, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => DB::table('crm_followups')->find($fid)], 201);
    }

    public function completeFollowup(Request $r, string $id)
    {
        $n = DB::table('crm_followups')->where('id', $id)->where('user_id', $r->user()->id)->update(['done_at' => now(), 'updated_at' => now()]);
        abort_unless($n, 404);

        return ['done' => true];
    }

    /** Insert or update contacts mirrored from EspoCRM or another source, keyed by (source, external id). */
    public function sync(Request $r)
    {
        $d = $r->validate([
            'source' => 'required|string|max:24', 'contacts' => 'required|array|max:500',
            'contacts.*.external_id' => 'required|string|max:200', 'contacts.*.name' => 'required|string|max:160',
            'contacts.*.email' => 'nullable|email|max:255', 'contacts.*.phone' => 'nullable|string|max:40',
            'contacts.*.organization' => 'nullable|string|max:160', 'contacts.*.contact_class' => 'nullable|in:standard,vip,blocked',
        ]);
        foreach ($d['contacts'] as $c) {
            DB::table('crm_contacts')->updateOrInsert(
                ['user_id' => $r->user()->id, 'external_source' => $d['source'], 'external_id' => $c['external_id']],
                $this->clean(array_diff_key($c, ['external_id' => 1])) + ['deleted_at' => null, 'updated_at' => now()]
                + ['id' => (string) Str::uuid(), 'created_at' => now()],
            );
        }

        return ['synced' => count($d['contacts'])];
    }

    /** Store phones as + and digits so a Twilio caller id matches however the number was typed. */
    public static function phone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return '+'.$digits;
    }

    private function clean(array $d): array
    {
        if (! empty($d['phone'])) {
            $d['phone'] = self::phone($d['phone']);
        }
        if (isset($d['tags'])) {
            $d['tags'] = json_encode($d['tags']);
        }

        return $d;
    }

    private function rules(Request $r, bool $create): array
    {
        return $r->validate([
            'name' => ($create ? 'required' : 'sometimes').'|string|max:160', 'organization' => 'nullable|string|max:160',
            'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:40',
            'contact_class' => 'nullable|in:standard,vip,blocked', 'tags' => 'nullable|array|max:20', 'tags.*' => 'string|max:40',
        ]);
    }

    private function own(Request $r, string $id): object
    {
        $c = DB::table('crm_contacts')->where('id', $id)->where('user_id', $r->user()->id)->whereNull('deleted_at')->first();
        abort_unless($c, 404);

        return $c;
    }
}

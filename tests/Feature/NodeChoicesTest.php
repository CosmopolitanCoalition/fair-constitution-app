<?php

namespace Tests\Feature;

use App\Services\Node\NodeChoices;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** W-0521 - My node choices are saved per person on the node. DB-free: sqlite :memory:. */
class NodeChoicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $schema = DB::connection()->getSchemaBuilder();
        if (! $schema->hasTable('node_choices')) {
            $schema->create('node_choices', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('user_id');
                $t->string('key', 40);
                $t->text('value');
                $t->timestamps();
                $t->unique(['user_id', 'key']);
            });
        }
        DB::table('node_choices')->delete();
    }

    public function test_a_value_round_trips_and_a_second_save_replaces_it(): void
    {
        $svc = new NodeChoices();
        $svc->put('u1', 'kits', ['on' => ['household' => true]]);
        $svc->put('u1', 'kits', ['on' => ['household' => false]]);

        $this->assertSame(['on' => ['household' => false]], $svc->get('u1', 'kits'));
        $this->assertSame(1, DB::table('node_choices')->count());
    }

    public function test_each_person_sees_only_their_own_choices(): void
    {
        $svc = new NodeChoices();
        $svc->put('u1', 'reach', ['value' => 'own']);
        $svc->put('u2', 'reach', ['value' => 'relay']);

        $this->assertSame(['reach' => ['value' => 'own']], $svc->all('u1'));
        $this->assertSame(['reach' => ['value' => 'relay']], $svc->all('u2'));
    }

    public function test_unknown_keys_and_oversized_values_are_refused(): void
    {
        $svc = new NodeChoices();

        $this->expectException(\InvalidArgumentException::class);
        $svc->put('u1', 'secrets', ['x' => 1]);
    }

    public function test_oversized_value_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new NodeChoices())->put('u1', 'stores', ['blob' => str_repeat('a', NodeChoices::MAX_BYTES)]);
    }
}

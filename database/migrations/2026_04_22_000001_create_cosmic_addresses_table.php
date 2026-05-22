<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cosmic_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('parent_id')->nullable();

            $table->string('label');
            $table->string('slug')->unique();

            $table->string('type')
                ->comment('multiverse|observable_universe|supercluster|galaxy_group|galaxy|galactic_region|star_system|world');
            $table->string('subtype')->nullable()
                ->comment('For world: planet|moon|planetoid|asteroid|space_station|artificial_habitat');

            $table->boolean('enabled')->default(false);
            $table->string('source')->default('seed');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'sort_order']);
            $table->index(['type', 'enabled']);
        });

        DB::statement('ALTER TABLE cosmic_addresses ALTER COLUMN id SET DEFAULT gen_random_uuid()');

        Schema::table('cosmic_addresses', function (Blueprint $table) {
            $table->foreign('parent_id')
                ->references('id')
                ->on('cosmic_addresses')
                ->cascadeOnDelete();
        });

        // Seed the canonical Multiverse → Earth path.
        $now = now();
        $path = [
            ['slug' => 'multiverse',           'label' => 'Multiverse',               'type' => 'multiverse',          'subtype' => null],
            ['slug' => 'observable-universe',  'label' => 'Observable Universe',      'type' => 'observable_universe', 'subtype' => null],
            ['slug' => 'laniakea-supercluster','label' => 'Laniakea Supercluster',    'type' => 'supercluster',        'subtype' => null],
            ['slug' => 'local-group',          'label' => 'Local Group',              'type' => 'galaxy_group',        'subtype' => null],
            ['slug' => 'milky-way',            'label' => 'Milky Way',                'type' => 'galaxy',              'subtype' => null],
            ['slug' => 'orion-arm',            'label' => 'Orion Arm (Orion Spur)',   'type' => 'galactic_region',     'subtype' => null],
            ['slug' => 'solar-system',         'label' => 'Solar System',             'type' => 'star_system',         'subtype' => null],
            ['slug' => 'earth',                'label' => 'Earth',                    'type' => 'world',               'subtype' => 'planet'],
        ];

        $parentId = null;
        foreach ($path as $i => $node) {
            $id = (string) Str::uuid();
            DB::table('cosmic_addresses')->insert([
                'id'         => $id,
                'parent_id'  => $parentId,
                'label'      => $node['label'],
                'slug'       => $node['slug'],
                'type'       => $node['type'],
                'subtype'    => $node['subtype'],
                'enabled'    => true,
                'source'     => 'seed',
                'sort_order' => 0,
                'metadata'   => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $parentId = $id;
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cosmic_addresses');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('elections', 'general_cycle_election_id')) {
            Schema::table('elections', fn (Blueprint $table) => $table->uuid('general_cycle_election_id')->nullable());
        }
        if (DB::getDriverName() === 'pgsql') {
            // Existing rows are null. NOT VALID skips their scan while still
            // enforcing the FK for every new insert/update with an anchor.
            if (! DB::selectOne("SELECT 1 FROM pg_constraint WHERE conrelid = 'elections'::regclass AND conname = 'elections_general_cycle_foreign'")) {
                DB::statement('ALTER TABLE elections ADD CONSTRAINT elections_general_cycle_foreign FOREIGN KEY (general_cycle_election_id) REFERENCES elections(id) ON DELETE RESTRICT NOT VALID');
            }
            foreach (['executive', 'judiciary'] as $owner) {
                $name = 'elections_general_cycle_'.$owner.'_unique';
                $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
                if ($index !== null && ! $index->indisvalid) {
                    DB::statement('DROP INDEX CONCURRENTLY '.$name);
                }
                DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON elections (general_cycle_election_id, '.$owner.'_id) WHERE general_cycle_election_id IS NOT NULL AND '.$owner.'_id IS NOT NULL');
            }
        } else {
            foreach (['executive', 'judiciary'] as $owner) {
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS elections_general_cycle_'.$owner.'_unique ON elections (general_cycle_election_id, '.$owner.'_id)');
            }
        }
    }

    public function down(): void
    {
        foreach (['executive', 'judiciary'] as $owner) {
            DB::statement('DROP INDEX '.(DB::getDriverName() === 'pgsql' ? 'CONCURRENTLY ' : '').'IF EXISTS elections_general_cycle_'.$owner.'_unique');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE elections DROP CONSTRAINT IF EXISTS elections_general_cycle_foreign');
        }
        if (Schema::hasColumn('elections', 'general_cycle_election_id')) {
            Schema::table('elections', fn (Blueprint $table) => $table->dropColumn('general_cycle_election_id'));
        }
    }
};

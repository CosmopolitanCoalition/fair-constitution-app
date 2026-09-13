<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEXES = [
        'judicial_proposal_court_seek_idx' => "chamber_vote_proposals ((payload->>'judiciary_id'), id DESC) WHERE proposal_kind IN ('judicial_nomination', 'judicial_committee_designation')",
        'judicial_vacancy_court_seek_idx' => "judicial_seats (judiciary_id, id DESC) WHERE status = 'vacant' AND deleted_at IS NULL",
        'judicial_nomination_seat_seek_idx' => 'judicial_nominations (seat_id, id DESC)',
        'judicial_committee_selection_idx' => "committees (legislature_id, id DESC) WHERE status != 'dissolved' AND deleted_at IS NULL",
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout = '5s'");
        }
        try {
            foreach (['judicial_committee_id', 'judicial_committee_vote_id'] as $column) {
                if (! Schema::hasColumn('judiciaries', $column)) {
                    Schema::table('judiciaries', fn (Blueprint $t) => $t->uuid($column)->nullable());
                }
            }
            if (DB::getDriverName() !== 'pgsql') {
                return;
            }
            // Preserve the installed constraint's full expression, including any prior extensions.
            // Existing rows already passed it; NOT VALID avoids a table-wide verification scan.
            DB::transaction(function () {
                $old = DB::selectOne("SELECT pg_get_expr(conbin, conrelid) AS expression FROM pg_constraint WHERE conrelid = 'chamber_vote_proposals'::regclass AND conname = 'chamber_vote_proposals_kind_check'");
                if ($old && ! str_contains($old->expression, 'judicial_committee_designation')) {
                    DB::statement('ALTER TABLE chamber_vote_proposals DROP CONSTRAINT chamber_vote_proposals_kind_check');
                    DB::statement("ALTER TABLE chamber_vote_proposals ADD CONSTRAINT chamber_vote_proposals_kind_check CHECK (({$old->expression}) OR proposal_kind IN ('judicial_nomination', 'judicial_committee_designation')) NOT VALID");
                }
            });
            foreach (self::INDEXES as $name => $definition) {
                $index = DB::selectOne('SELECT indisvalid FROM pg_index WHERE indexrelid = to_regclass(?)', [$name]);
                if ($index !== null && ! $index->indisvalid) {
                    DB::statement('DROP INDEX CONCURRENTLY '.$name);
                }
                DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$definition);
            }
        } finally {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('RESET lock_timeout');
            }
        }
    }

    public function down(): void
    {
        // Recorded judicial acts and assignments survive a code rollback.
    }
};

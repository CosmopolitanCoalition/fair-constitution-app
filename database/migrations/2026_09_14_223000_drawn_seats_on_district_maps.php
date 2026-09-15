<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W-0443 — the drawn-seat total lives on the map row, kept by the database.
 *
 * The sim console's seat-gap rail compared every active map's drawn seats
 * with its chamber's Type A total by summing legislature_districts per
 * chamber on every request: 512,895 candidate chambers, 1.8 million
 * districts, 56 s on box E (2026-09-14). The total is now a column on
 * legislature_district_maps (`drawn_seats`, the identity of apportionment
 * step 8: seats minus bonus_seats) with the chamber's gap beside it
 * (`seat_gap` = type_a_seats minus drawn_seats), maintained by two
 * statement-level triggers: one on legislature_districts (insert, update,
 * delete, through transition tables, so a planet-wide draw pays one update
 * per statement, never one per row) and one on legislatures when
 * type_a_seats changes. Two partial indexes make the two rails index reads:
 * drifted active maps, and chambers whose Type B half exceeds Type A.
 *
 * NULL means "not yet computed": `maps:drawn-seats-backfill` fills existing
 * rows in bounded, resumable chunks. Additive; rerunnable; PostgreSQL. The
 * SQLite fixtures take the two columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legislature_district_maps')) {
            return;
        }

        Schema::table('legislature_district_maps', function ($table) {
            if (! Schema::hasColumn('legislature_district_maps', 'drawn_seats')) {
                $table->integer('drawn_seats')->nullable();
            }
            if (! Schema::hasColumn('legislature_district_maps', 'seat_gap')) {
                $table->integer('seat_gap')->nullable();
            }
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.cga_map_drawn_seats_recompute(map_ids uuid[]) RETURNS void
    LANGUAGE sql
    AS $$
        UPDATE legislature_district_maps m
           SET drawn_seats = s.total,
               seat_gap    = l.type_a_seats - s.total
          FROM legislatures l,
               (
                   SELECT x.id AS map_id,
                          COALESCE((
                              SELECT SUM(d.seats - COALESCE(d.bonus_seats, 0))
                                FROM legislature_districts d
                               WHERE d.map_id = x.id AND d.deleted_at IS NULL
                          ), 0)::int AS total
                     FROM unnest(map_ids) AS x(id)
               ) s
         WHERE m.id = s.map_id
           AND l.id = m.legislature_id;
    $$;

CREATE OR REPLACE FUNCTION public.cga_map_drawn_seats_from_districts() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
        DECLARE ids uuid[];
        BEGIN
            IF TG_OP = 'INSERT' THEN
                SELECT array_agg(DISTINCT map_id) INTO ids FROM new_rows WHERE map_id IS NOT NULL;
            ELSIF TG_OP = 'DELETE' THEN
                SELECT array_agg(DISTINCT map_id) INTO ids FROM old_rows WHERE map_id IS NOT NULL;
            ELSE
                SELECT array_agg(DISTINCT map_id) INTO ids FROM (
                    SELECT map_id FROM new_rows UNION SELECT map_id FROM old_rows
                ) u WHERE map_id IS NOT NULL;
            END IF;
            IF ids IS NOT NULL THEN
                PERFORM public.cga_map_drawn_seats_recompute(ids);
            END IF;
            RETURN NULL;
        END;
    $$;

DROP TRIGGER IF EXISTS cga_map_drawn_seats_ins ON legislature_districts;
DROP TRIGGER IF EXISTS cga_map_drawn_seats_upd ON legislature_districts;
DROP TRIGGER IF EXISTS cga_map_drawn_seats_del ON legislature_districts;
CREATE TRIGGER cga_map_drawn_seats_ins AFTER INSERT ON legislature_districts
    REFERENCING NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION public.cga_map_drawn_seats_from_districts();
CREATE TRIGGER cga_map_drawn_seats_upd AFTER UPDATE ON legislature_districts
    REFERENCING OLD TABLE AS old_rows NEW TABLE AS new_rows FOR EACH STATEMENT EXECUTE FUNCTION public.cga_map_drawn_seats_from_districts();
CREATE TRIGGER cga_map_drawn_seats_del AFTER DELETE ON legislature_districts
    REFERENCING OLD TABLE AS old_rows FOR EACH STATEMENT EXECUTE FUNCTION public.cga_map_drawn_seats_from_districts();

-- PostgreSQL refuses transition tables on a trigger with a column list, so this
-- one is row-level and fires only when type_a_seats actually changed (WHEN).
CREATE OR REPLACE FUNCTION public.cga_map_seat_gap_from_legislature() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
        BEGIN
            UPDATE legislature_district_maps m
               SET seat_gap = NEW.type_a_seats - m.drawn_seats
             WHERE m.legislature_id = NEW.id AND m.drawn_seats IS NOT NULL;
            RETURN NULL;
        END;
    $$;

DROP TRIGGER IF EXISTS cga_map_seat_gap_sync ON legislatures;
CREATE TRIGGER cga_map_seat_gap_sync AFTER UPDATE OF type_a_seats ON legislatures
    FOR EACH ROW WHEN (OLD.type_a_seats IS DISTINCT FROM NEW.type_a_seats)
    EXECUTE FUNCTION public.cga_map_seat_gap_from_legislature();

CREATE INDEX IF NOT EXISTS legislature_district_maps_drift_idx
    ON legislature_district_maps (seat_gap)
    WHERE status = 'active' AND deleted_at IS NULL AND seat_gap IS NOT NULL AND seat_gap <> 0;

CREATE INDEX IF NOT EXISTS legislatures_over_bound_idx
    ON legislatures (type_b_seats DESC)
    WHERE deleted_at IS NULL AND type_b_seats > type_a_seats;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS cga_map_drawn_seats_ins ON legislature_districts;
DROP TRIGGER IF EXISTS cga_map_drawn_seats_upd ON legislature_districts;
DROP TRIGGER IF EXISTS cga_map_drawn_seats_del ON legislature_districts;
DROP TRIGGER IF EXISTS cga_map_seat_gap_sync ON legislatures;
DROP FUNCTION IF EXISTS public.cga_map_drawn_seats_from_districts();
DROP FUNCTION IF EXISTS public.cga_map_seat_gap_from_legislature();
DROP FUNCTION IF EXISTS public.cga_map_drawn_seats_recompute(uuid[]);
DROP INDEX IF EXISTS legislature_district_maps_drift_idx;
DROP INDEX IF EXISTS legislatures_over_bound_idx;
SQL);
        }

        if (Schema::hasTable('legislature_district_maps')) {
            Schema::table('legislature_district_maps', function ($table) {
                foreach (['drawn_seats', 'seat_gap'] as $col) {
                    if (Schema::hasColumn('legislature_district_maps', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};

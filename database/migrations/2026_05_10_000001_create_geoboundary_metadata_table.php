<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create geoboundary_metadata table.
 *
 * Mirrors the geoBoundaries meta CSV
 * (D:\fair-constitution-map-files\geoBoundaries_repo\releaseData\geoBoundariesOpen-meta.csv)
 * one row per (boundaryISO, boundaryType=ADM_n). Loaded once during the
 * Phase 1 ETL run from `import_geoboundaries.py::load_meta_index()` and
 * queried thereafter by:
 *
 *   - `synthesize_missing_country_rows` — looks up the human-readable
 *     boundary name when synthesising a missing ADM0 row (e.g. "Puerto
 *     Rico" for iso=PRI). Replaces the previous TERRITORY_DISPLAY_NAMES
 *     hard-coded dict in scripts/etl/sovereign_territories.py.
 *
 *   - `process_geojson_file` — pulls boundary_id (fallback for
 *     geoboundaries_id) and unsdg_region (used by the language-lookup
 *     code path) from this table instead of an in-memory dict.
 *
 *   - Future Setup wizard / DataReviewService — can JOIN against this
 *     for region/income-group filtering and display.
 *
 * Purged on `--fresh` runs alongside jurisdictions / synthetic rows so
 * a stale CSV row from a previous archive version doesn't survive.
 *
 * Columns mirror the CSV's 28 columns. Hyphenated CSV column names
 * (`UNSDG-region`, `UNSDG-subregion`, `worldBankIncomeGroup`) become
 * snake_case here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geoboundary_metadata', function (Blueprint $t) {
            $t->char('iso_code', 3);
            $t->smallInteger('adm_level');             // 0..5 (geoBoundaries native, NOT app's adm_level)
            $t->string('boundary_id', 64)->nullable(); // e.g. "PRI-ADM0-13396579"
            $t->string('name')->nullable();            // boundaryName — "Puerto Rico"
            $t->smallInteger('year_represented')->nullable();
            $t->string('boundary_type', 16)->nullable();   // "ADM0", "ADM1", ...
            $t->text('boundary_canonical')->nullable();    // "افغانستان‎ , Afġānistān" — local name(s)
            $t->text('boundary_source')->nullable();       // "Sentinel-2 10m LandCover", etc.
            $t->text('boundary_license')->nullable();      // "Creative Commons Attribution 4.0 (CC BY 4.0)"
            $t->text('license_detail')->nullable();
            $t->text('license_source')->nullable();
            $t->text('boundary_source_url')->nullable();
            $t->string('source_data_update_date', 64)->nullable();   // free-form date string from CSV
            $t->string('build_date', 64)->nullable();                // free-form date string from CSV
            $t->string('continent', 64)->nullable();
            $t->string('unsdg_region', 128)->nullable();
            $t->string('unsdg_subregion', 128)->nullable();
            $t->string('world_bank_income_group', 64)->nullable();
            $t->integer('adm_unit_count')->nullable();
            $t->double('mean_vertices')->nullable();
            $t->integer('min_vertices')->nullable();
            $t->integer('max_vertices')->nullable();
            $t->double('mean_perimeter_length_km')->nullable();
            $t->double('min_perimeter_length_km')->nullable();
            $t->double('max_perimeter_length_km')->nullable();
            $t->double('mean_area_sq_km')->nullable();
            $t->double('min_area_sq_km')->nullable();
            $t->double('max_area_sq_km')->nullable();
            $t->text('static_download_link')->nullable();
            $t->timestampsTz();

            $t->primary(['iso_code', 'adm_level']);
            $t->index('iso_code');
            $t->index('continent');
            $t->index('unsdg_region');
            $t->index('world_bank_income_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geoboundary_metadata');
    }
};

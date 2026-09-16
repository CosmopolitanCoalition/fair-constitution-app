<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * W-0448 / W-0449 — the video library substrate. Five additive tables:
 *
 *   - media_pulls / media_pull_items  the resumable ingestion engine (website
 *     download or local-folder copy), the same run/item shape the sim and
 *     autoscale pull engines use, so the Step-2 dashboard renders it with no
 *     new idiom.
 *   - media_videos / media_video_tracks  uploaded films and their audio /
 *     caption tracks. The generated registry (config/cga/media.php) stays the
 *     baseline catalog; a DB row with the same id wins, so an operator upload
 *     augments or overrides a registry entry without regenerating the file.
 *   - media_surface_videos  a per-surface film assignment for the Learning
 *     Drawer (one film per surface).
 *
 * Real-dated after the flattened baseline, additive-only. Every create is
 * hasTable-guarded so a re-run is a no-op. Postgres and SQLite safe: uuid pks
 * are set by the models (HasUuids), never a Postgres-only gen_random_uuid()
 * default; jsonb falls back to text on sqlite; the partial unique index is
 * pgsql-only with a plain unique on other drivers. The desk applies this on
 * the live box; tests build the tables by calling this migration's up().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_pulls')) {
            Schema::create('media_pulls', function (Blueprint $t) {
                $t->uuid('id')->primary();
                // web | folder
                $t->string('source', 16);
                // the website base url, or the local folder path
                $t->string('source_ref', 512)->nullable();
                // running | halted | done | failed
                $t->string('status', 16)->default('running');
                $t->jsonb('options')->default('{}');
                $t->integer('items_total')->default(0);
                $t->integer('items_done')->default(0);
                $t->integer('items_failed')->default(0);
                $t->bigInteger('bytes_total')->default(0);
                $t->bigInteger('bytes_done')->default(0);
                $t->text('error')->nullable();
                $t->uuid('initiator_user_id')->nullable();
                $t->timestampTz('started_at')->nullable();
                $t->timestampTz('finished_at')->nullable();
                $t->timestamps();

                $t->index('status', 'media_pulls_status_idx');
            });
        }

        if (! Schema::hasTable('media_pull_items')) {
            Schema::create('media_pull_items', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->foreignUuid('pull_id')->constrained('media_pulls')->cascadeOnDelete();
                $t->string('subject', 160);
                // master | audio | captions
                $t->string('kind', 16);
                // the track language English name (null for a master)
                $t->string('track_name', 64)->nullable();
                $t->string('source', 1024);
                // relative to the library root
                $t->string('dest', 1024);
                $t->bigInteger('bytes_expected')->nullable();
                $t->bigInteger('bytes_done')->default(0);
                // pending | running | done | failed | skipped
                $t->string('status', 16)->default('pending');
                $t->smallInteger('attempts')->default(0);
                $t->text('error')->nullable();
                $t->timestamps();

                $t->index(['pull_id', 'status'], 'media_pull_items_pull_status_idx');
            });
        }

        if (! Schema::hasTable('media_videos')) {
            Schema::create('media_videos', function (Blueprint $t) {
                // the catalog id, e.g. v-<slug>
                $t->string('id', 96)->primary();
                $t->string('subject', 160);
                $t->string('slug', 96);
                $t->string('master', 255);
                $t->string('title', 160);
                $t->text('summary')->nullable();
                $t->string('poster', 32)->default('learn');
                $t->decimal('seconds', 10, 1)->nullable();
                // upload (the operator uploaded it) — the registry source is 'registry'
                $t->string('source', 16)->default('upload');
                $t->uuid('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });

            // One live slug. A partial unique index on pgsql lets a soft-deleted
            // row and a later re-upload of the same slug coexist in history; other
            // drivers get a plain unique (acceptable for the sqlite test fixture,
            // which never soft-deletes a duplicate slug).
            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'CREATE UNIQUE INDEX media_videos_slug_unique
                     ON media_videos (slug) WHERE deleted_at IS NULL'
                );
            } else {
                Schema::table('media_videos', function (Blueprint $t) {
                    $t->unique('slug', 'media_videos_slug_unique');
                });
            }
        }

        if (! Schema::hasTable('media_video_tracks')) {
            Schema::create('media_video_tracks', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('video_id', 96);
                // audio | captions
                $t->string('kind', 16);
                // BCP-47 track code from the languages table
                $t->string('code', 16);
                // the language English name (the media filename token)
                $t->string('name', 64);
                $t->timestamps();

                $t->foreign('video_id', 'media_video_tracks_video_fk')
                    ->references('id')->on('media_videos')->cascadeOnDelete();
                $t->unique(['video_id', 'kind', 'code'], 'media_video_tracks_unique');
            });
        }

        if (! Schema::hasTable('media_surface_videos')) {
            Schema::create('media_surface_videos', function (Blueprint $t) {
                // one film per surface — the surface id is the key
                $t->string('surface_id', 96)->primary();
                $t->string('video_id', 96);
                $t->uuid('assigned_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_surface_videos');
        Schema::dropIfExists('media_video_tracks');
        Schema::dropIfExists('media_videos');
        Schema::dropIfExists('media_pull_items');
        Schema::dropIfExists('media_pulls');
    }
};

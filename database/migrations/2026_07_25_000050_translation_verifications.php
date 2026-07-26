<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase N (lane 5) — the human half of translation.
 *
 * Machine translation writes the catalogs; those are files, regenerable, and
 * carry no opinion. THIS table is the part that cannot be regenerated: a record
 * of a person reading a string in their own language and saying whether it is
 * right. It is the only durable artifact in the translation plane that a re-run
 * must never overwrite.
 *
 * DESIGN, taken from mockups/v3/translation (fixtures-translation.js):
 *   - the lifecycle is none -> ai_draft -> in_review -> verified -> published
 *   - verification is by QUORUM, not by authority: `needed: 3` in the fixture.
 *     No single person's opinion settles a string, which is the same posture
 *     the rest of the constitution takes toward single actors.
 *   - verification is gated to READERS of the language. The mockup is explicit:
 *     "verified BY THE PEOPLE WHO READ IT in that language". A person who
 *     cannot read the language cannot usefully confirm it, so the right to
 *     verify follows the reader, not a title.
 *
 * One row per (locale, key, person). A person may change their own verdict —
 * that is an update, not a second vote — and the unique index enforces it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // What was judged.
            $table->string('locale', 12);
            $table->string('namespace', 64);
            $table->string('message_key', 191);

            /*
             * The English text this verdict was made against, by hash. A source
             * string that later changes INVALIDATES the verdict rather than
             * silently keeping it: nobody verified the new wording, and quietly
             * carrying the old approval forward would be a lie about who
             * checked what.
             */
            $table->char('source_hash', 64);

            // The verdict.
            $table->string('verdict', 16);              // approved | edited | rejected
            $table->text('machine_text')->nullable();   // what the machine proposed
            $table->text('verified_text')->nullable();  // what the person settled on
            $table->text('note')->nullable();           // why, when they chose to say

            // Who and when — the whole point of the table.
            $table->uuid('verified_by');
            $table->timestamp('verified_at');

            $table->timestamps();
            $table->softDeletes();

            // One verdict per person per string; changing your mind updates it.
            $table->unique(['locale', 'namespace', 'message_key', 'verified_by'], 'translation_verifications_one_per_person');

            // The queue reads "what still needs eyes in this locale".
            $table->index(['locale', 'verdict']);
            // Counting toward quorum reads all verdicts for one string.
            $table->index(['locale', 'namespace', 'message_key'], 'translation_verifications_string_idx');
            $table->index('verified_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_verifications');
    }
};

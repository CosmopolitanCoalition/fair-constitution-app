<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase K-3 (K3-K hardening) — an explicit encryption signal on matrix_rooms so the translation privacy
 * rail can never fail OPEN. Today every public room is created unencrypted (m.room.encryption uncreatable),
 * so the rail's "public ⇒ cloud-translatable" assumption holds — but the moment an encrypted-but-public
 * room exists (a future room type, or a federated/external room mirrored as public), conflating public
 * with cloud-safe would leak ciphertext-origin content to a cloud translator. is_encrypted (default false)
 * is OR-ed into TranslationGate::isPrivate so ENCRYPTION ALONE forces the local-only provider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matrix_rooms', function (Blueprint $table) {
            $table->boolean('is_encrypted')->default(false)->after('is_public');
        });
    }

    public function down(): void
    {
        Schema::table('matrix_rooms', function (Blueprint $table) {
            $table->dropColumn('is_encrypted');
        });
    }
};

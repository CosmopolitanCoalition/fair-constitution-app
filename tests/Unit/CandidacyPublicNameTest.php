<?php

namespace Tests\Unit;

use App\Http\Presenters\CandidacyPanel;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Public naming only, using a private SQLite database and no live profile requests. */
final class CandidacyPublicNameTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config(['database.connections.candidacy_name_fixture' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::setDefaultConnection('candidacy_name_fixture');
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('users', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
            $t->string('display_name')->nullable();
            $t->softDeletes();
        });
        $schema->create('social_profiles', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('user_id')->unique();
            $t->string('display_name')->nullable();
            $t->string('handle')->nullable();
            $t->string('visibility');
            $t->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('candidacy_name_fixture');
        DB::setDefaultConnection($this->originalConnection);
        parent::tearDown();
    }

    public function test_private_and_jurisdiction_social_names_do_not_leak_through_public_display_fallback(): void
    {
        foreach (['private', 'jurisdiction'] as $visibility) {
            $id = 'user-'.$visibility;
            DB::table('users')->insert(['id' => $id, 'name' => 'Private legal identity']);
            DB::table('social_profiles')->insert(['id' => 'profile-'.$id, 'user_id' => $id,
                'display_name' => 'Private social identity', 'handle' => 'private-handle', 'visibility' => $visibility]);
            $expected = 'Resident-'.substr(hash('sha256', $id), 0, 8);
            self::assertSame($expected, CandidacyPanel::displayName(User::findOrFail($id)));
            self::assertSame([$id => $expected], CandidacyPanel::displayNames([$id]));
            DB::table('social_profiles')->where('user_id', $id)->update(['display_name' => null]);
            self::assertSame($expected, CandidacyPanel::displayName(User::findOrFail($id)));
            self::assertSame([$id => $expected], CandidacyPanel::displayNames([$id]));
        }
    }

    public function test_chosen_civic_name_and_public_social_pseudonym_keep_their_precedence_in_single_and_bulk_names(): void
    {
        DB::table('users')->insert([
            ['id' => 'civic', 'name' => 'Legal one', 'display_name' => 'Chosen civic name'],
            ['id' => 'social', 'name' => 'Legal two', 'display_name' => null],
            ['id' => 'handle', 'name' => 'Legal three', 'display_name' => null],
            ['id' => 'deleted-social', 'name' => 'Legal four', 'display_name' => null],
        ]);
        DB::table('social_profiles')->insert([
            ['id' => 'profile-civic', 'user_id' => 'civic', 'display_name' => 'Private face', 'handle' => 'private', 'visibility' => 'private', 'deleted_at' => null],
            ['id' => 'profile-social', 'user_id' => 'social', 'display_name' => 'Public social face', 'handle' => 'social', 'visibility' => 'public', 'deleted_at' => null],
            ['id' => 'profile-handle', 'user_id' => 'handle', 'display_name' => null, 'handle' => 'public-handle', 'visibility' => 'public', 'deleted_at' => null],
            ['id' => 'profile-deleted', 'user_id' => 'deleted-social', 'display_name' => 'Removed face', 'handle' => 'removed', 'visibility' => 'public', 'deleted_at' => '2026-09-13'],
        ]);
        $expected = ['civic' => 'Chosen civic name', 'social' => 'Public social face', 'handle' => '@public-handle',
            'deleted-social' => 'Resident-'.substr(hash('sha256', 'deleted-social'), 0, 8)];
        self::assertSame($expected, CandidacyPanel::displayNames(array_keys($expected)));
        foreach ($expected as $id => $display) {
            self::assertSame($display, CandidacyPanel::displayName(User::findOrFail($id)));
        }
        self::assertSame('Candidate', CandidacyPanel::displayName(null));
        self::assertSame([], CandidacyPanel::displayNames([]));
    }
}

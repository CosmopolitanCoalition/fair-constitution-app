<?php

namespace Tests\Feature;

use App\Models\MediaPull;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * W-0448 — the Step-2 video library ingestion endpoints.
 *
 * Runs on the phpunit sqlite :memory: connection. NEVER RefreshDatabase: the
 * media migration's own up() builds the run tables. The operator gate refuses
 * before any read (an unpersisted User is enough), so those pins need no world.
 * A temp empty library root pins present() false and base_url null.
 */
class MediaLibrarySetupControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require base_path('database/migrations/2026_09_16_180000_media_library_tables.php'))->up();

        config([
            'cga.media_local.local_root' => rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/')
                .'/media-setup-empty-'.getmypid().'-'.uniqid(),
            'cga.media.base_url' => null,
        ]);
        Cache::flush();
    }

    public function test_a_guest_is_refused_at_the_write_doors(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->postJson('/api/setup/wizard/step2/media/pull', [])->assertStatus(401);
        $this->postJson('/api/setup/wizard/step2/media/control', [])->assertStatus(401);
        $this->postJson('/api/setup/wizard/step2/media/path', [])->assertStatus(401);
    }

    public function test_a_non_operator_is_refused_before_any_read(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $citizen = new User(['name' => 'Resident', 'email' => 'resident@example.test']);
        $citizen->is_operator = false;
        $this->actingAs($citizen);

        $this->postJson('/api/setup/wizard/step2/media/pull', ['source' => 'web'])->assertStatus(403);
        $this->postJson('/api/setup/wizard/step2/media/control', ['action' => 'halt'])->assertStatus(403);
        $this->postJson('/api/setup/wizard/step2/media/path', ['media_dir' => '/x'])->assertStatus(403);
    }

    public function test_status_is_public_and_reports_the_library_shape(): void
    {
        $json = $this->getJson('/api/setup/wizard/step2/media')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('root', $json);
        $this->assertSame('/var/www/html/public/media/Subjects', $json['mount']);
        $this->assertFalse($json['present'], 'an empty root is not present');
        $this->assertNull($json['base_url'], 'no base url with an empty local library');
        $this->assertArrayHasKey('subjects_with_master', $json['inventory']);
        $this->assertSame(58_834_000_000, $json['library_size_estimate_bytes']);
        $this->assertNull($json['pull'], 'no run yet');
        $this->assertIsArray($json['films']);
    }

    public function test_start_refuses_409_while_a_pull_is_running(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        MediaPull::create(['source' => 'web', 'status' => 'running', 'options' => []]);

        $this->actingAs($this->operator());

        $this->postJson('/api/setup/wizard/step2/media/pull', ['source' => 'web'])
            ->assertStatus(409);
    }

    public function test_control_halt_flips_the_running_run_to_halted(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $pull = MediaPull::create(['source' => 'web', 'status' => 'running', 'options' => []]);

        $this->actingAs($this->operator());

        $this->postJson('/api/setup/wizard/step2/media/control', ['action' => 'halt'])
            ->assertOk()
            ->assertJsonPath('pull.status', 'halted');

        $this->assertSame('halted', $pull->fresh()->status);
    }

    private function operator(): User
    {
        $operator = new User(['name' => 'Operator', 'email' => 'operator@example.test']);
        $operator->is_operator = true;

        return $operator;
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\System\TranslationPackageController;
use App\Models\User;
use App\Services\I18n\LanguagePackageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * W-0446 — the language-package actions are operator-only (operator ruling
 * 2026-09-15). Runs on the phpunit sqlite fixture connection (phpunit.xml
 * DB_CONNECTION=sqlite :memory:); the request store is a JSON file under a
 * temp dir, so no schema and no live database are touched. The controller
 * methods are called directly with an operator- or non-operator-bearing
 * Request (the TypeBMapControllerTest posture) — the gate is enforced in the
 * controller, exactly like halt/resume.
 */
class LanguagePackageRoutesTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/i18n-packages-routes-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            foreach (glob($this->tmp . '/*') ?: [] as $f) {
                is_file($f) ? @unlink($f) : $this->rrmdir($f);
            }
            @rmdir($this->tmp);
        }
        parent::tearDown();
    }

    private function controller(): TranslationPackageController
    {
        return new TranslationPackageController(
            new LanguagePackageService($this->tmp, ['es' => ['target' => true]]),
        );
    }

    private function req(bool $operator, array $body = []): Request
    {
        $user = (new User)->forceFill(['id' => 'u-' . bin2hex(random_bytes(3)), 'is_operator' => $operator]);
        $r = Request::create('/', 'POST', $body);
        $r->setUserResolver(fn () => $user);

        return $r;
    }

    private function assert403(callable $call): void
    {
        try {
            $call();
            $this->fail('expected a 403 from a non-operator');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_a_non_operator_is_403_on_every_action(): void
    {
        $c = $this->controller();

        $this->assert403(fn () => $c->export($this->req(false, ['locale' => 'es'])));
        $this->assert403(fn () => $c->import($this->req(false, ['locale' => 'es'])));
        $this->assert403(fn () => $c->confirm($this->req(false), 'run-1'));
        $this->assert403(fn () => $c->download($this->req(false), 'run-1', 'es'));
        $this->assert403(fn () => $c->requestLanguage($this->req(false, ['locale' => 'es'])));
        $this->assert403(fn () => $c->status($this->req(false)));
    }

    public function test_an_operator_can_request_a_language_and_it_is_listed(): void
    {
        $c = $this->controller();

        $resp = $c->requestLanguage($this->req(true, ['locale' => 'Klingon (tlh)', 'note' => 'conference ask']));
        $this->assertSame(200, $resp->getStatusCode());

        $listed = $c->status($this->req(true))->getData(true);
        $this->assertCount(1, $listed['requests']);
        $this->assertSame('Klingon (tlh)', $listed['requests'][0]['locale']);
        $this->assertSame('conference ask', $listed['requests'][0]['note']);
        $this->assertContains('es', $listed['targets']);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_file($f) ? @unlink($f) : $this->rrmdir($f);
        }
        @rmdir($dir);
    }
}

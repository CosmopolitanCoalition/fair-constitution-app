<?php

namespace Tests\Unit;

use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\TestCase;

/**
 * Lane pin (gap-wiring-locale, gap 2 — PHP side).
 *
 * DB-free. The registration + settings language list is no longer a
 * hand-copied five: RegisteredUserController::languages() reads THE locale
 * registry (config/locales.php). This pins that the accepted set is exactly
 * every registered code, and that the validation rule accepts each of them.
 */
final class Wiring_gap_wiring_localeTest extends TestCase
{
    public function test_languages_equal_the_locale_registry_keys(): void
    {
        $registry = array_keys(config('locales.locales', []));

        self::assertNotEmpty($registry, 'the locale registry is readable');
        self::assertSame(
            $registry,
            RegisteredUserController::languages(),
            'the accepted language set is exactly config(locales.locales) keys',
        );
    }

    public function test_registry_carries_the_enabled_seven_including_fr_and_pt(): void
    {
        $codes = RegisteredUserController::languages();

        foreach (['en', 'es', 'ar', 'zh-Hans', 'hi', 'fr', 'pt'] as $code) {
            self::assertContains($code, $codes, "registry includes $code");
        }
    }

    public function test_the_in_rule_accepts_every_registered_code_and_rejects_an_unregistered_one(): void
    {
        $rule = Rule::in(RegisteredUserController::languages());

        foreach (RegisteredUserController::languages() as $code) {
            $ok = Validator::make(
                ['languages' => [$code]],
                ['languages.*' => ['string', $rule]],
            )->passes();
            self::assertTrue($ok, "the rule accepts registered code $code");
        }

        $bad = Validator::make(
            ['languages' => ['zz-not-a-locale']],
            ['languages.*' => ['string', $rule]],
        )->passes();
        self::assertFalse($bad, 'the rule rejects an unregistered code');
    }

    public function test_my_record_controller_shares_the_same_registry_source(): void
    {
        // The settings panel's language options and validation delegate to the
        // same method (no second hand-copied list).
        $src = file_get_contents(app_path('Http/Controllers/Civic/MyRecordController.php'));

        self::assertStringContainsString(
            'RegisteredUserController::languages()',
            $src,
            'MyRecordController reads the registry-sourced list, not a constant copy',
        );
        self::assertStringNotContainsString(
            'RegisteredUserController::LANGUAGES',
            $src,
            'the retired LANGUAGES constant reference is gone',
        );
    }
}

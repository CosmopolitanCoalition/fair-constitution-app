<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pin (W-0445, 2026-09-15): SetLocale must run AFTER the session starts and
 * BEFORE HandleInertiaRequests. Prepended to the web group it ran before the
 * session existed, so a guest's POST /locale choice and the session guard's
 * user were never read and only Accept-Language applied. Source pin, DB-free.
 */
class MiddlewareOrderTest extends TestCase
{
    #[Test]
    public function set_locale_is_appended_before_inertia_and_never_prepended(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/bootstrap/app.php');
        $web = substr($src, strpos($src, '$middleware->web('));
        $web = substr($web, 0, strpos($web, ');'));

        $this->assertStringNotContainsString('prepend:', $web, 'nothing runs before the session in the web group');
        $append = strpos($web, 'append:');
        $locale = strpos($web, 'SetLocale::class');
        $inertia = strpos($web, 'HandleInertiaRequests::class');
        $this->assertNotFalse($append);
        $this->assertNotFalse($locale);
        $this->assertNotFalse($inertia);
        $this->assertGreaterThan($append, $locale, 'SetLocale sits in append');
        $this->assertLessThan($inertia, $locale, 'SetLocale runs before HandleInertiaRequests');
    }
}

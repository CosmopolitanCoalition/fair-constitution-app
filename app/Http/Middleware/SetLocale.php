<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase F — per-request locale resolution. Sets the app locale so BOTH the
 * Inertia shared `locale` prop AND the blade <html lang/dir> (which read
 * app()->getLocale()) agree on first paint. Resolution order:
 *   1. the authenticated user's stored locale,
 *   2. a guest's session choice (POST /locale),
 *   3. Accept-Language negotiated against the supported product locales,
 *   4. English.
 * Registered BEFORE HandleInertiaRequests. `en-XA` (the pseudo-locale QA tool)
 * is never resolved server-side — it is a client-only dev toggle.
 */
class SetLocale
{
    /** Supported product locales (mirrors resources/js/i18n LOCALES). */
    public const SUPPORTED = ['en', 'es', 'ar', 'zh-Hans', 'hi'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        app()->setLocale($locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $user = $request->user();
        if ($user !== null && $this->supported($user->locale)) {
            return (string) $user->locale;
        }

        $session = $request->hasSession() ? $request->session()->get('locale') : null;
        if ($this->supported($session)) {
            return (string) $session;
        }

        $negotiated = $this->negotiate((string) $request->header('Accept-Language', ''));
        if ($negotiated !== null) {
            return $negotiated;
        }

        return 'en';
    }

    private function supported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::SUPPORTED, true);
    }

    /** Map the highest-q Accept-Language tag onto a supported locale. */
    private function negotiate(string $header): ?string
    {
        foreach (explode(',', $header) as $part) {
            $tag = strtolower(trim(explode(';', $part)[0]));
            if ($tag === '') {
                continue;
            }
            if (str_starts_with($tag, 'zh')) {
                return 'zh-Hans';
            }
            $primary = explode('-', $tag)[0];
            foreach (self::SUPPORTED as $supported) {
                if ($primary === strtolower(explode('-', $supported)[0])) {
                    return $supported;
                }
            }
        }

        return null;
    }
}

<!DOCTYPE html>
@php
    $locale = str_replace('_', '-', app()->getLocale());
    // RTL scripts among the supported chrome locales (i18n/index.js LOCALES).
    $dir = in_array($locale, ['ar', 'fa', 'he', 'ur'], true) ? 'rtl' : 'ltr';

    // Preload the two latin Instrument Sans faces (regular + semibold) that
    // paint the first screen — fonts.css carries font-display: swap, so this
    // removes the FOUT window for body text and headings. Guarded: the woff2
    // files are manifest-tracked via cga/fonts.css url() references, but a
    // stale/partial build must never take the page down over a hint.
    try {
        $preloadFonts = [
            \Illuminate\Support\Facades\Vite::asset('resources/fonts/instrument-sans-400-latin.woff2'),
            \Illuminate\Support\Facades\Vite::asset('resources/fonts/instrument-sans-600-latin.woff2'),
        ];
    } catch (\Throwable $e) {
        $preloadFonts = [];
    }
@endphp
<html lang="{{ $locale }}" dir="{{ $dir }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        @foreach ($preloadFonts as $font)
        <link rel="preload" href="{{ $font }}" as="font" type="font/woff2" crossorigin />
        @endforeach
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>

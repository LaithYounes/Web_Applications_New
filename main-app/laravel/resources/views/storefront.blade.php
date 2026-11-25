<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
    <title>{{ config('storefront.brand') }}</title>
    @if (Vite::isRunningHot() || is_file(public_path('build/manifest.json')))
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/storefront/main.tsx'])
    @endif
</head>
<body>
    <div id="storefront" data-config="{{ json_encode(config('storefront'), JSON_THROW_ON_ERROR) }}">
        @unless (Vite::isRunningHot() || is_file(public_path('build/manifest.json')))
            <main><h1>{{ config('storefront.brand') }}</h1><p>The storefront is unavailable. Build the web assets to start it.</p></main>
        @endunless
    </div>
    <noscript>Enable JavaScript to use the storefront.</noscript>
</body>
</html>

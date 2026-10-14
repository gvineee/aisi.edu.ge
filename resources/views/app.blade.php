<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @php
            // Deliberate Blade-based public rendering path (docs/02 section 7,
            // CLAUDE.md tech decisions): without a running Inertia SSR
            // server, this is what makes a public page's real title and
            // description visible in the initial HTML for crawlers/JS-less
            // clients, instead of the generic app-name fallback.
            $seoTitle = data_get($page, 'props.page.seoTitle');
            $seoDescription = data_get($page, 'props.page.seoDescription');
            $siteName = data_get($page, 'props.name', config('app.name', 'Laravel'));

            // Canonical/hreflang only for indexable public pages; the query
            // string is dropped except news pagination, which is real content.
            $seoTenant = app(\App\Domain\Tenancy\CurrentTenant::class)->get();
            $canonicalUrl = null;
            if ($seoTenant && str_starts_with($page['component'], 'public/') && $page['component'] !== 'public/report-card-verify') {
                $listPage = (int) request()->query('page', 1);
                $canonicalUrl = $seoTenant->canonicalUrl(request()->getPathInfo().($listPage > 1 ? '?page='.$listPage : ''));
            }
        @endphp

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ $seoTitle ?: $siteName }}</title>
            @if ($seoDescription)
                <meta name="description" content="{{ $seoDescription }}">
            @endif
            @if ($canonicalUrl)
                <link rel="canonical" href="{{ $canonicalUrl }}">
                <link rel="alternate" hreflang="{{ $seoTenant->locale }}" href="{{ $canonicalUrl }}">
                <link rel="alternate" hreflang="x-default" href="{{ $canonicalUrl }}">
            @endif
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>

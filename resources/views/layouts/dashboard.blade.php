{{--
    The shell every dashboard page extends.

    The stylesheet comes from a package route rather than a published asset, so the file that ships
    is the file that renders and a host that never ran a publish still sees a styled page. Every URL
    here is built by `route()`, which is what the #70 guard requires: escaping does nothing to a
    `javascript:` URL, so no value a requester or an agent supplied may reach one.

    Nothing in this file renders anything unescaped. The #67 guard refuses `{!! !!}`, a `@php` block
    and a raw PHP tag anywhere under `resources/views/`, and it refuses this package from building an
    `Htmlable`, because `{{ }}` does not escape one of those either.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme ?? 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ?? 'Robot Council' }}</title>

    <link rel="stylesheet" href="{{ route('robot-council.dashboard.stylesheet') }}">

    @livewireStyles
</head>
<body class="min-h-screen bg-base-200 font-sans antialiased">
    <div class="mx-auto max-w-7xl p-4 sm:p-6">
        <header class="mb-6">
            <h1 class="text-xl font-semibold">Robot Council</h1>
            <p class="text-sm opacity-70">Fleet coordination</p>
        </header>

        <main>
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>

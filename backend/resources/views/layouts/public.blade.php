<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">

    <title>@yield('title', config('app.name')) — {{ config('app.name', 'ScamSense') }}</title>

    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="bg-body-secondary d-flex flex-column min-vh-100">

    <a href="#main" class="visually-hidden-focusable p-2 bg-body">{{ __('Skip to content') }}</a>

    <nav class="navbar bg-body border-bottom">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="{{ route('verify.show') }}">ScamSense</a>

            {{-- Language switch. The audience is bilingual and many will be
                 far more comfortable in Malay, so this must be reachable
                 without scrolling and readable at arm's length. --}}
            <div class="btn-group" role="group" aria-label="{{ __('Language') }}">
                @foreach (['en' => 'English', 'ms' => 'Bahasa Malaysia'] as $code => $name)
                    <a href="{{ route('locale.switch', $code) }}"
                       class="btn btn-sm {{ app()->getLocale() === $code ? 'btn-secondary' : 'btn-outline-secondary' }}"
                       @if (app()->getLocale() === $code) aria-current="true" @endif>
                        {{ $code === 'en' ? 'EN' : 'BM' }}
                        <span class="visually-hidden">{{ $name }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </nav>

    <main id="main" class="container py-4 flex-grow-1">
        @yield('content')
    </main>

    <footer class="border-top bg-body py-3 mt-auto">
        <div class="container">
            <p class="small text-body-secondary mb-0">
                {{ __('ScamSense gives guidance, not a guarantee. If you have lost money, contact your bank immediately and report it to the police.') }}
            </p>
        </div>
    </footer>
</body>
</html>

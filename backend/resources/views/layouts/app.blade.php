<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'ScamSense') }}</title>

    @vite(['resources/css/app.scss', 'resources/js/app.js'])
</head>
<body class="bg-body-secondary d-flex flex-column min-vh-100">
    <nav class="navbar navbar-expand-md bg-body border-bottom">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="{{ route('verify.show') }}">ScamSense</a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#primaryNav" aria-controls="primaryNav"
                    aria-expanded="false" aria-label="{{ __('Toggle navigation') }}">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="primaryNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                           @if (request()->routeIs('dashboard')) aria-current="page" @endif
                           href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a>
                    </li>
                </ul>

                @auth
                    <div class="d-flex align-items-center gap-3">
                        <span class="text-body-secondary small">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-sm">
                                {{ __('Log out') }}
                            </button>
                        </form>
                    </div>
                @endauth
            </div>
        </div>
    </nav>

    @isset($header)
        <header class="bg-body border-bottom">
            <div class="container py-3">
                {{ $header }}
            </div>
        </header>
    @endisset

    <main class="container py-4 flex-grow-1">
        {{ $slot }}
    </main>
</body>
</html>

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
    {{-- The admin bar wears the brand surface so the product reads as one
         thing, but the admin area stops there: it is a tool, and the masthead
         hero belongs to the pages a member of the public sees. --}}
    <nav class="navbar navbar-expand-md sc-mast sc-mast--bar">
        <div class="container">
            <a class="sc-brand navbar-brand" href="{{ route('verify.show') }}">
                <svg class="sc-brand__mark" viewBox="0 0 24 24" fill="none"
                     width="24" height="24" aria-hidden="true" focusable="false">
                    <path d="M12 2.2 4.2 5.4v6.2c0 4.8 3.2 8.9 7.8 10.2 4.6-1.3 7.8-5.4 7.8-10.2V5.4Z"
                          fill="currentColor"/>
                    <path d="m8.4 12.2 2.6 2.6 4.7-5" stroke="#1f4a3e" stroke-width="2.2"
                          stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>ScamSense</span>
            </a>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#primaryNav" aria-controls="primaryNav"
                    aria-expanded="false" aria-label="{{ __('Toggle navigation') }}">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="primaryNav">
                {{-- Three pages, one job each. Moderation was reachable only
                     by typing the URL or following a link from a dashboard
                     counter, which is a poor way to find the one page in the
                     admin area that has work waiting on it. --}}
                <ul class="navbar-nav me-auto">
                    @foreach ([
                        ['admin.dashboard', 'Dashboard'],
                        ['admin.moderation.index', 'Moderation'],
                        ['admin.reports', 'Reports'],
                    ] as [$route, $label])
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs($route) ? 'active' : '' }}"
                               @if (request()->routeIs($route)) aria-current="page" @endif
                               href="{{ route($route) }}">{{ __($label) }}</a>
                        </li>
                    @endforeach
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

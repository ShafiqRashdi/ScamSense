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
{{-- `sc-public` scopes the security-print ribbon to the public pages; the
     admin area is a tool and gets none of it (ADR 0012). --}}
<body class="sc-public d-flex flex-column min-vh-100">

    <a href="#main" class="visually-hidden-focusable p-2 bg-body">{{ __('Skip to content') }}</a>

    {{-- ── The masthead ────────────────────────────────────────────────────
         The brand surface the page hangs from, and the only dark region on
         the site apart from the footer that bookends it. Keeping the dark to
         these two bands is what lets the risk colours stay the loudest thing
         on any page (ADR 0012 rule 2).

         Each page supplies its own hero inside it, so the verdict page can be
         shallower than the landing page without a second layout. --}}
    <header class="sc-mast @yield('mast_class')">
        {{-- The brand mark again, oversized and cropped into the corner as the
             band's texture. Outline only, at 9%: it can never be read as
             content or compete with a risk colour. --}}
        <svg class="sc-mast__shield" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M12 2.2 4.2 5.4v6.2c0 4.8 3.2 8.9 7.8 10.2 4.6-1.3 7.8-5.4 7.8-10.2V5.4Z"
                  fill="none" stroke="currentColor" stroke-width="0.6"/>
            <path d="M12 4.6 6.3 7v4.6c0 3.6 2.4 6.6 5.7 7.6 3.3-1 5.7-4 5.7-7.6V7Z"
                  fill="none" stroke="currentColor" stroke-width="0.4"/>
        </svg>
        <div class="sc-mast__inner">
            <div class="sc-bar">
                <a class="sc-brand" href="{{ route('verify.show') }}">
                    {{-- Inline rather than an <img>: it must be the exact brand
                         green, it must not cost a request, and it must still
                         be there if an image fails to load. --}}
                    <svg class="sc-brand__mark" viewBox="0 0 24 24" fill="none"
                         width="26" height="26" aria-hidden="true" focusable="false">
                        <path d="M12 2.2 4.2 5.4v6.2c0 4.8 3.2 8.9 7.8 10.2 4.6-1.3 7.8-5.4 7.8-10.2V5.4Z"
                              fill="currentColor"/>
                        <path d="m8.4 12.2 2.6 2.6 4.7-5" stroke="#1f4a3e" stroke-width="2.2"
                              stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>ScamSense</span>
                </a>

                {{-- Plain links rather than tab-shaped buttons: a tab look would
                     read as another switch like the Message / Link choice on the
                     form. On a phone they drop to their own row inside the band,
                     because the brand, two links and EN/BM do not fit on one. --}}
                <nav class="sc-nav" aria-label="{{ __('pages.nav.label') }}">
                    @foreach (['about', 'howto'] as $page)
                        <a href="{{ route($page) }}" class="sc-nav__link"
                           @if (request()->routeIs($page)) aria-current="page" @endif>
                            {{ __('pages.nav.'.$page) }}
                        </a>
                    @endforeach
                </nav>

                {{-- Language switch. The audience is bilingual and many will be
                     far more comfortable in Malay, so this must be reachable
                     without scrolling and readable at arm's length.

                     Links, not buttons, and the pill is positioned from the
                     current locale in CSS — so it is correct before any
                     JavaScript runs and correct if none ever does. --}}
                <div class="sc-lang" role="group" aria-label="{{ __('Language') }}"
                     data-active="{{ app()->getLocale() === 'ms' ? 'ms' : 'en' }}">
                    <span class="sc-lang__pill" aria-hidden="true"></span>
                    @foreach (['en' => 'English', 'ms' => 'Bahasa Malaysia'] as $code => $name)
                        <a href="{{ route('locale.switch', ['locale' => $code, 'from' => request()->route()?->getName()]) }}"
                           class="sc-lang__opt"
                           @if (app()->getLocale() === $code) aria-current="true" @endif>
                            {{ $code === 'en' ? 'EN' : 'BM' }}
                            <span class="visually-hidden">{{ $name }}</span>
                        </a>
                    @endforeach
                </div>
            </div>

            @yield('hero')
        </div>
    </header>

    <main id="main" class="flex-grow-1">
        @yield('content')
    </main>

    <footer class="sc-foot mt-auto">
        <div class="sc-foot__inner">
            <p class="mb-0">
                <strong>{{ __('ScamSense gives guidance, not a guarantee.') }}</strong>
            </p>
            {{-- The most consequential sentence on the site, so it sits in its
                 own box rather than trailing after the one above. --}}
            <p class="sc-foot__help">
                <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                    <circle cx="10" cy="10" r="7.5" fill="none" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M10 6v5" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <circle cx="10" cy="14" r="1.1" fill="currentColor"/>
                </svg>
                <span>{{ __('If you have lost money, contact your bank immediately and report it to the police.') }}</span>
            </p>
            <p class="sc-foot__meta">
                @foreach (['about', 'howto'] as $page)
                    <a href="{{ route($page) }}">{{ __('pages.nav.'.$page) }}</a>
                @endforeach
                <span>{{ __('Final Year Project · Universiti Poli-Tech Malaysia') }}</span>
            </p>
        </div>
    </footer>
</body>
</html>

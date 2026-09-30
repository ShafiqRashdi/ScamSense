@extends('layouts.public')

@section('title', __('pages.about.title'))

@section('hero')
    <div class="sc-hero sc-hero--page">
        <h1 class="sc-enter" style="--sc-d: 0">{{ __('pages.about.title') }}</h1>
        <p class="sc-enter" style="--sc-d: 1">{{ __('pages.about.lead') }}</p>
    </div>
@endsection

@section('content')
    {{-- No accuracy percentages anywhere on this page, deliberately. See the
         header of lang/en/pages.php; a test holds the line. --}}
    <div class="sc-lift pb-5">
        <div class="sc-shell">
            <article class="sc-card sc-page sc-enter" style="--sc-d: 2">
                <div class="sc-card__body">

                    <section>
                        <h2>{{ __('pages.about.ai_heading') }}</h2>
                        <div class="sc-facts">
                            @foreach (['messages', 'links', 'reported', 'official'] as $key)
                                <div class="sc-fact">
                                    <h3>{{ __('pages.about.ai.'.$key.'.title') }}</h3>
                                    <p>{{ __('pages.about.ai.'.$key.'.body') }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>

                    <section>
                        <h2>{{ __('pages.about.safe_heading') }}</h2>
                        <ul>
                            <li>{{ __('pages.about.safe.never_open') }}</li>
                            {{-- The same sentence the form shows, so the two pages
                                 cannot drift into describing different policies. --}}
                            <li>{{ __('We remove phone numbers, IC numbers, emails and account numbers before saving anything.') }}</li>
                            <li>{{ __('pages.about.safe.reviewed') }}</li>
                        </ul>
                    </section>

                    <section>
                        <h2>{{ __('pages.about.limits_heading') }}</h2>
                        <ul>
                            <li>{{ __('pages.about.limits.wrong') }}</li>
                            <li>{{ __('pages.about.limits.page') }}</li>
                        </ul>
                    </section>

                </div>
            </article>
        </div>
    </div>
@endsection

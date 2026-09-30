@extends('layouts.public')

@section('title', __('pages.howto.title'))

@section('hero')
    <div class="sc-hero sc-hero--page">
        <h1 class="sc-enter" style="--sc-d: 0">{{ __('pages.howto.title') }}</h1>
        <p class="sc-enter" style="--sc-d: 1">{{ __('pages.howto.lead') }}</p>
    </div>
@endsection

@section('content')
    @php
        // Every button and answer name comes from the string the site actually
        // shows, never a copy of it, so this page cannot teach a label that
        // has since been renamed. The drawings below reuse the same strings.
        $steps = [
            __('pages.howto.steps.choose', ['message' => __('A message'), 'link' => __('A link')]),
            __('pages.howto.steps.paste'),
            __('pages.howto.steps.press', ['button' => __('Check it')]),
            __('pages.howto.steps.read'),
        ];

        // Same order as the risk they carry, with the verified answer beside
        // "no warning signs" and "could not check" last.
        $answers = [
            \App\Enums\RiskLevel::High,
            \App\Enums\RiskLevel::Medium,
            \App\Enums\RiskLevel::Low,
            'verified',
            \App\Enums\RiskLevel::Unknown,
        ];
    @endphp

    <div class="sc-lift pb-5">
        <div class="sc-shell">
            <article class="sc-card sc-page sc-enter" style="--sc-d: 2">
                <div class="sc-card__body">

                    {{-- ── Checking something ──────────────────────────────
                         Each step is paired with a small drawing of the real
                         control, built from the site's own parts rather than a
                         screenshot: it stays sharp, follows the language
                         switch, and cannot fall out of date with the design.
                         The drawings are aria-hidden; the sentence above each
                         one carries the instruction. --}}
                    <section>
                        <h2>{{ __('pages.howto.steps_heading') }}</h2>
                        <ol class="sc-howsteps">
                            @foreach ($steps as $i => $step)
                                <li class="sc-howstep">
                                    <div class="sc-howstep__text">
                                        <span class="sc-howstep__n" aria-hidden="true">{{ sprintf('%02d', $i + 1) }}</span>
                                        <p>{{ $step }}</p>
                                    </div>
                                    <div class="sc-stage" aria-hidden="true">
                                        @switch($i)
                                            @case(0)
                                                <div class="sc-mini">
                                                    <div class="sc-mini-seg">
                                                        <span>{{ __('A message') }}</span>
                                                        <span class="is-on">{{ __('A link') }}</span>
                                                    </div>
                                                </div>
                                                <span class="sc-tap"></span>
                                                @break
                                            @case(1)
                                                {{-- The body stays Malay in both
                                                     locales, as the scams do. --}}
                                                <div class="sc-mini sc-mini--low">
                                                    <div class="sc-mini-box">
                                                        <span class="sc-popup"><span>{{ __('Copy') }}</span><span class="is-on">{{ __('Paste') }}</span></span>
                                                        Tahniah! Anda memenangi RM5,000. Sila tuntut dalam 24 jam...<span class="sc-caret"></span>
                                                    </div>
                                                </div>
                                                @break
                                            @case(2)
                                                <div class="sc-mini"><div class="sc-mini-btn">{{ __('Check it') }}</div></div>
                                                <span class="sc-tap"></span>
                                                @break
                                            @case(3)
                                                <div class="sc-mini">
                                                    <div class="sc-mini-verdict sc-tone sc-tone--danger">
                                                        <span class="sc-mini-verdict__rule"></span>
                                                        <div class="sc-mini-verdict__body">
                                                            <span class="sc-mini-badge">{{ __('verdict.high.label') }}</span>
                                                            <strong>{{ __('verdict.high.heading') }}</strong>
                                                            <span class="sc-mini-reason"><i>1</i>{{ __('indicators.prize_claim') }}</span>
                                                            <span class="sc-mini-reason"><i>2</i>{{ __('indicators.urgency_language') }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                @break
                                        @endswitch
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                        <p class="sc-page__note">{{ __('pages.howto.language') }}</p>
                    </section>

                    <section>
                        <h2>{{ __('pages.howto.answers_heading') }}</h2>
                        {{-- Each row is ruled in its own band colour, beside the
                             badge exactly as the result page draws it. --}}
                        <ul class="sc-answers">
                            @foreach ($answers as $answer)
                                @if ($answer === 'verified')
                                    <li class="sc-tone sc-tone--success">
                                        <span class="sc-badge">
                                            <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                                                <path d="M10 1.8 3.5 4.5v5.1c0 4 2.7 7.3 6.5 8.5 3.8-1.2 6.5-4.5 6.5-8.5V4.5Z" fill="none"
                                                      stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/>
                                                <path d="m7 10 2.1 2.1 4-4.4" fill="none" stroke="currentColor" stroke-width="2"
                                                      stroke-linecap="round" stroke-linejoin="round"/>
                                            </svg>
                                            {{ __('verdict.verified.label') }}
                                        </span>
                                        <p>{{ __('pages.howto.answers.verified', ['scope' => __('verdict.verified.scope_heading')]) }}</p>
                                    </li>
                                @else
                                    <li class="sc-tone sc-tone--{{ $answer->colour() }}">
                                        <span class="sc-badge">
                                            {{-- iconSvg() returns a hardcoded SVG string from
                                                 the enum, never user input -- the same reason
                                                 the result page may print it unescaped. --}}
                                            <span aria-hidden="true">{!! $answer->iconSvg() !!}</span>
                                            {{ __('verdict.'.$answer->value.'.label') }}
                                        </span>
                                        <p>{{ __('pages.howto.answers.'.$answer->value) }}</p>
                                    </li>
                                @endif
                            @endforeach
                        </ul>
                    </section>

                    {{-- ── Reading an address, drawn ────────────────────────
                         The same marks as the result page's address breakdown:
                         the owner boxed, a copied name wavy-underlined. Plain
                         text, never links: this page teaches reading an address
                         instead of tapping it (asserted by InfoPagesTest). --}}
                    <section>
                        <h2>{{ __('pages.howto.address_heading') }}</h2>
                        <p>{{ __('pages.howto.address') }}</p>
                        <div class="sc-urls">
                            <div class="sc-url sc-tone sc-tone--success">
                                <p class="sc-url__tag">
                                    <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="m4.5 10.5 3.5 3.5 7.5-8" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    {{ __('pages.howto.address_real') }}
                                </p>
                                <p class="sc-url__addr"><span class="sc-url__dim">https://www.</span><span class="sc-url__owner">maybank2u.com.my</span><b>/</b><span class="sc-url__dim">home/login</span></p>
                                <ul class="sc-legend">
                                    <li><span class="sc-key sc-key--owner" aria-hidden="true"></span><span>{{ __('verdict.anatomy.owner') }}</span></li>
                                </ul>
                            </div>
                            <div class="sc-url sc-tone sc-tone--danger">
                                <p class="sc-url__tag">
                                    <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M5.5 5.5l9 9m0-9-9 9" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
                                    {{ __('pages.howto.address_fake') }}
                                </p>
                                <p class="sc-url__addr"><span class="sc-url__dim">https://</span><span class="sc-url__copy">maybank2u.com.my.</span><span class="sc-url__owner">secure-verify.xyz</span><b>/</b><span class="sc-url__dim">login</span></p>
                                <ul class="sc-legend">
                                    <li><span class="sc-key sc-key--copy" aria-hidden="true"></span><span>{{ __('verdict.anatomy.copied') }}</span></li>
                                    <li><span class="sc-key sc-key--owner" aria-hidden="true"></span><span>{{ __('verdict.anatomy.owner') }}</span></li>
                                </ul>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h2>{{ __('pages.howto.feedback_heading') }}</h2>
                        <p>{{ __('pages.howto.feedback', ['heading' => __('feedback.heading')]) }}</p>
                        {{-- The two real buttons, drawn small so they can be
                             recognised under an answer. Not buttons here. --}}
                        <p class="sc-mini-fb" aria-hidden="true"><span>{{ __('feedback.agree') }}</span><span>{{ __('feedback.dispute') }}</span></p>
                    </section>

                    <section>
                        <h2>{{ __('pages.howto.lost_heading') }}</h2>
                        <ol class="sc-lost">
                            <li>
                                <div class="sc-lost__top">
                                    <span class="sc-lost__ico" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M3 9.5 12 4l9 5.5M5 10v8m4.7-8v8m4.6-8v8M19 10v8M3.5 20h17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                                    <span class="sc-howstep__n" aria-hidden="true">01</span>
                                </div>
                                <p>{{ __('pages.howto.lost.bank') }}</p>
                            </li>
                            <li>
                                <div class="sc-lost__top">
                                    <span class="sc-lost__ico" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M6.6 3.5h2.6l1.4 4-2 1.4a11 11 0 0 0 6.5 6.5l1.4-2 4 1.4v2.6a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.6 5.7a2 2 0 0 1 2-2.2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></span>
                                    <span class="sc-howstep__n" aria-hidden="true">02</span>
                                </div>
                                {{-- Large so it can be read off the screen while
                                     dialling. The sentence below still carries it. --}}
                                <span class="sc-lost__big" aria-hidden="true">997</span>
                                <p>{{ __('pages.howto.lost.nsrc', ['number' => '997']) }}</p>
                            </li>
                            <li>
                                <div class="sc-lost__top">
                                    <span class="sc-lost__ico" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="10.5" cy="10.5" r="6" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m15 15 5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                                    <span class="sc-howstep__n" aria-hidden="true">03</span>
                                </div>
                                <p>{{ __('pages.howto.lost.mule', ['site' => 'semakmule.rmp.gov.my']) }}</p>
                            </li>
                        </ol>
                        <p class="sc-hurry">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="13" r="7.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 9v4.5l3 1.8M9.5 3h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                            <span>{{ __('pages.howto.lost_hurry') }}</span>
                        </p>
                    </section>

                </div>
            </article>
        </div>
    </div>
@endsection

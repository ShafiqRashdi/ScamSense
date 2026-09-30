@extends('layouts.public')

@section('title', __('Check a message or link'))

{{-- The header carries only the bar here; the band continues in .sc-home-top. --}}
@section('mast_class', 'sc-mast--flush')

@section('content')

    {{-- The home band. Heading and form share ONE grid here, inside <main>,
         so each column sizes itself: a longer Malay heading wraps inside its
         column and a taller left side simply makes the band taller. The first
         version pulled the form up into the header by a fixed distance, which
         only worked while the left side stayed one exact height -- in Malay
         the heading ran under the card and the specimen was cropped.

         The header above it is flush (no hero, no light, no shield) so the
         two read as one band; the shield lives here instead. The skip link
         lands on this section: the heading, then the form. --}}
    <div class="sc-home-top">
        <svg class="sc-mast__shield" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M12 2.2 4.2 5.4v6.2c0 4.8 3.2 8.9 7.8 10.2 4.6-1.3 7.8-5.4 7.8-10.2V5.4Z"
                  fill="none" stroke="currentColor" stroke-width="0.6"/>
            <path d="M12 4.6 6.3 7v4.6c0 3.6 2.4 6.6 5.7 7.6 3.3-1 5.7-4 5.7-7.6V7Z"
                  fill="none" stroke="currentColor" stroke-width="0.4"/>
        </svg>
        <div class="sc-shell sc-home-top__grid">
        {{-- `--sc-d` is each element's place in the entrance stagger (50ms). --}}
            <div class="sc-hero sc-hero--home">
                <p class="sc-eyebrow sc-enter" style="--sc-d: 0">{{ __('Free · No sign-up · Malaysia') }}</p>
                <h1 class="sc-enter" style="--sc-d: 1">{{ __('Is this a scam?') }}</h1>
                <p class="sc-enter" style="--sc-d: 2">{{ __('Paste a message or a link and we will check it for you.') }}</p>

                {{-- Desktop only. The same promises are under the button and in the
                     privacy panel, so a phone loses nothing by skipping them. --}}
                <ul class="sc-trust sc-enter" style="--sc-d: 3">
                    @foreach ([
                        'We never open the link you paste.',
                        'We never ask who you are.',
                        'Phone and account numbers are removed before we save anything.',
                    ] as $promise)
                        <li>
                            <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                                <path d="m4.5 10.5 3.5 3.5 7.5-8" fill="none" stroke="currentColor" stroke-width="2.2"
                                      stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            {{ __($promise) }}
                        </li>
                    @endforeach
                </ul>

                {{-- A specimen of what the tool looks for, not content: aria-hidden,
                     desktop only, and static. Bodies stay Malay in both locales, as
                     the scams themselves are. The link is written already defanged
                     so the band never carries a live-looking address. --}}
                <div class="sc-specimen sc-enter" style="--sc-d: 8" aria-hidden="true">
                    <div class="sc-ghost sc-ghost--a">
                        <small>+60 11-xxxx xxxx</small>
                        <b>Tahniah!</b> Anda memenangi RM5,000. Sila tuntut <b>dalam 24 jam</b>...
                    </div>
                    <div class="sc-ghost sc-ghost--b">
                        <small>POS-MY</small>
                        Bungkusan anda tertahan. Kemaskini alamat di <b>hxxps://pos-semak[.]xyz</b>
                    </div>
                </div>
            </div>

            {{-- `data-busy-form` opts this form into the submit feedback in
                 app.js. The verdict can take up to three seconds, and a button
                 that does nothing for three seconds gets pressed again. --}}
            <form method="POST" action="{{ route('verify.store') }}" class="sc-card sc-enter" style="--sc-d: 3" data-busy-form>
                @csrf
                <div class="sc-card__body">

                    {{-- Inside the card, not above it: on a wide screen the card
                         sits beside the heading, and an alert above it would
                         land on top of the heading. --}}
                    @if ($errors->any())
                        <div class="alert alert-danger" role="alert">
                            <ul class="mb-0 ps-3">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <fieldset class="mb-4" data-busy-hide>
                        <legend class="form-label fs-6 fw-semibold">
                            {{ __('What are you checking?') }}
                        </legend>

                        {{-- Radio inputs, not buttons: this is a form control
                             and it must submit without JavaScript. The chosen
                             side is shown by `.sc-seg__on`, a copy of both
                             labels clipped from `:checked` in CSS — right on
                             first paint, before app.js runs and even if it
                             never does. The icons are literal SVG, not {!! !!}: the
                             result page's enum icon stays the only unescaped
                             output in the application. --}}
                        <div class="sc-seg" data-seg>
                            <input type="radio" class="sc-seg__input" name="type" id="type-message"
                                   value="message" autocomplete="off"
                                   {{ old('type', 'message') === 'message' ? 'checked' : '' }}>
                            <label class="sc-seg__opt" for="type-message">
                                <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M3.5 5.5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-3.5 3v-3a2 2 0 0 1-2-2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                                {{ __('A message') }}
                            </label>

                            <input type="radio" class="sc-seg__input" name="type" id="type-url"
                                   value="url" autocomplete="off"
                                   {{ old('type') === 'url' ? 'checked' : '' }}>
                            <label class="sc-seg__opt" for="type-url">
                                <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M8.5 11.5a3.5 3.5 0 0 0 5 0l2.5-2.5a3.5 3.5 0 0 0-5-5l-1 1M11.5 8.5a3.5 3.5 0 0 0-5 0L4 11a3.5 3.5 0 0 0 5 5l1-1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                {{ __('A link') }}
                            </label>

                            {{-- LAST, not first. `~` only matches siblings that
                                 come after the subject, so written before the
                                 inputs the rule that moves the clip could never
                                 match -- the "message" side would stay lit
                                 whichever was chosen. Pinned by VerifyPageTest. --}}
                            <span class="sc-seg__on" aria-hidden="true">
                                <span><svg viewBox="0 0 20 20" focusable="false"><path d="M3.5 5.5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H9l-3.5 3v-3a2 2 0 0 1-2-2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>{{ __('A message') }}</span>
                                <span><svg viewBox="0 0 20 20" focusable="false"><path d="M8.5 11.5a3.5 3.5 0 0 0 5 0l2.5-2.5a3.5 3.5 0 0 0-5-5l-1 1M11.5 8.5a3.5 3.5 0 0 0-5 0L4 11a3.5 3.5 0 0 0 5 5l1-1" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>{{ __('A link') }}</span>
                            </span>
                        </div>
                    </fieldset>

                    <div class="mb-3" data-busy-hide>
                        <label for="content" class="form-label fw-semibold">
                            {{ __('Paste it here') }}
                        </label>
                        {{-- rows="3" rather than the tall fixed box this was.
                             app.js grows it to fit as the user types, so it
                             starts as an invitation rather than a void. Three
                             rows plus the min-height in the stylesheet is still
                             a comfortable target with no JavaScript at all.

                             The two caps are the ones VerifySubmissionRequest
                             actually enforces, read from the same config rather
                             than repeated as literals — a counter that
                             disagrees with the validator is worse than none,
                             because it tells the user they have room and then
                             the server refuses the message. --}}
                        <textarea id="content"
                                  name="content"
                                  rows="3"
                                  data-autogrow
                                  data-max-message="{{ config('scamsense.limits.message_max_chars') }}"
                                  data-max-url="{{ config('scamsense.limits.url_max_chars') }}"
                                  class="form-control form-control-lg @error('content') is-invalid @enderror"
                                  required
                                  autofocus
                                  placeholder="{{ __('For example: Tahniah! Anda memenangi RM5,000...') }}"
                        >{{ old('content') }}</textarea>
                        {{-- How to get a message into the box, for someone who
                             has not pasted on a phone before. The quoted words
                             are the phone's own menu labels (Malay Android says
                             "Salin" and "Tampal"), so they are translated as the
                             menu reads rather than in the site's own wording.
                             Quotes rather than bold, so the line stays escaped. --}}
                        <p class="sc-tip">{{ __('Tip: press and hold the message in WhatsApp or SMS, choose "Copy", then press and hold in this box and choose "Paste".') }}</p>
                        {{-- Length, shown only when it starts to matter.
                             A counter sitting there from the first keystroke is
                             a warning nobody needs; this stays hidden until 90%
                             of the cap and then says how much room is left.

                             `aria-hidden` because a live region updating on
                             every keystroke is unusable with a screen reader.
                             The visually-hidden status beside it announces only
                             the crossing into "too long" and back out again,
                             which is the part that changes what the user has to
                             do. --}}
                        <p class="sc-counter small text-body-secondary mb-0 mt-1 text-end"
                           data-counter
                           data-counter-left="{{ __(':count characters left') }}"
                           data-counter-over="{{ __('Too long. Please remove :count characters.') }}"
                           aria-hidden="true" hidden></p>

                        <p class="visually-hidden" data-counter-announce role="status" aria-live="polite"></p>

                        @error('content')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    {{-- The busy label travels in a data attribute because the
                         CSP is `script-src 'self'` and it must be translated.
                         Without JS the button is an ordinary submit button. --}}
                    {{-- The wait lives INSIDE the button: a rail along its
                         bottom edge and a label that crossfades. One element
                         doing one job, rather than a spinner and a separate
                         progress panel competing for the same moment. --}}
                    {{-- The wait, shown. Hidden until submit, when app.js hides
                         the box above and fills this with what was pasted —
                         links made safe in front of the reader, the same rule
                         DefangService applies. Nothing is marked here: no
                         finding exists yet. --}}
                    <div class="mt-1" data-reading-panel hidden>
                        <p class="fw-semibold mb-2" data-reading-label
                           data-label-message="{{ __('Your message') }}"
                           data-label-url="{{ __('Your link') }}"></p>
                        <div class="sc-reading" data-reading aria-hidden="true"></div>
                    </div>

                    <button type="submit" class="sc-cta" data-busy-label="{{ __('Checking...') }}">
                        <span class="sc-cta__label">{{ __('Check it') }}</span>
                        <span class="sc-cta__rail" aria-hidden="true"><i></i></span>
                    </button>

                    <p class="sc-reassure">
                        <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                            <rect x="4" y="8.5" width="12" height="9" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M6.8 8.5V6.2a3.2 3.2 0 0 1 6.4 0v2.3" fill="none" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                        <span>{{ __('We never open the link you paste, and we never ask who you are.') }}</span>
                    </p>

                    {{-- The wait.
                         =====
                         A verdict takes up to three seconds and the user
                         spends all of them wondering whether anything is
                         happening. A bare spinner fills the time without
                         telling them anything; these lines fill it and also
                         teach what the platform actually does, which is the
                         same job the indicator list does on the result page.

                         The copy names ordinary actions in ordinary words. It
                         deliberately avoids naming a "model", an "engine" or a
                         "database" -- this audience includes people for whom
                         those words carry no meaning, and `enjin` in Malay
                         reads as the thing in a car.

                         The bar is INDETERMINATE by design. A bar that fills
                         to a percentage would be inventing a progress figure
                         nothing measures, and on a page whose whole argument
                         is that it does not overclaim, a fake progress bar is
                         a bad place to start.

                         Rendered hidden and revealed by app.js, so with no
                         JavaScript the form is exactly as it was. --}}
                    {{-- The rail now lives in the button, so this carries
                         only the words. They fill the wait AND teach what the
                         platform does -- the same job the indicator list does
                         on the result page. --}}
                    <p class="visually-hidden" data-busy-status role="status" aria-live="polite"></p>

                    {{-- Read by app.js and rotated through the status line
                         above. Kept in the markup rather than in JS so the
                         strings stay translatable and stay with the rest of
                         the page copy. --}}
                    {{-- Shown as a checklist while waiting: the current line in
                         full ink, earlier ones dimmed. The visually-hidden status
                         above speaks each line once for screen readers. --}}
                    <ul class="sc-steps-live" data-busy-steps data-busy-panel hidden>
                        <li>{{ __('Reading what you pasted...') }}</li>
                        <li>{{ __('Comparing it with scams we have seen before...') }}</li>
                        <li>{{ __('Getting your answer ready...') }}</li>
                    </ul>
                </div>
            </form>

        </div>
    </div>

    {{-- ── How it works ────────────────────────────────────────────────────
         Three steps, numbered rather than illustrated. This fills the desktop
         width with something that teaches instead of decorating, which matters
         for a reader who has never used a tool like this and does not know
         whether pasting a scam somewhere is safe. --}}
    {{-- A real <ol>, so the order is announced by the list itself. The large
         numerals are visual only; the old aria-label on a <span> is ignored by
         many screen readers. `sc-reveal` rises the steps in once, and only if
         they start below the fold. --}}
    <section class="sc-band" aria-labelledby="how-heading">
        <div class="sc-shell">
            <h2 class="sc-section-head" id="how-heading">{{ __('How it works') }}</h2>
            <ol class="sc-how sc-reveal">
                @foreach ([
                    ['Paste it', 'A message someone sent you, or a link you were asked to open. You never have to say who you are.'],
                    ['We check it', 'We compare it against scams we have seen before. We never open the link you paste.'],
                    ['You decide', 'You get an answer in plain words, with the reasons - so the next one you can spot yourself.'],
                ] as $i => [$title, $body])
                    <li class="sc-step" style="--sc-d: {{ $i }}">
                        <span class="sc-step__n" aria-hidden="true">{{ sprintf('%02d', $i + 1) }}</span>
                        <h3>{{ __($title) }}</h3>
                        <p>{{ __($body) }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Privacy notice, required on the form itself (Phase 6 brief). --}}
    <section class="sc-band sc-band--last">
        <div class="sc-shell">
            <div class="sc-priv sc-reveal">
                <h2>{{ __('What happens to what you paste') }}</h2>
                <ul>
                    <li>
                        <span class="sc-priv__ico" aria-hidden="true">
                            <svg viewBox="0 0 20 20" focusable="false"><path d="M4 6h12M4 10h7M4 14h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="m13 12 4 4m0-4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        </span>
                        <p class="mb-0">{{ __('We remove phone numbers, IC numbers, emails and account numbers before saving anything.') }}</p>
                    </li>
                    <li>
                        <span class="sc-priv__ico" aria-hidden="true">
                            <svg viewBox="0 0 20 20" focusable="false"><circle cx="10" cy="10" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M10 6.5V10l2.5 1.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        </span>
                        <p class="mb-0">{{ __('We keep the message for :days days so we can improve our scam detection, then delete it automatically.', ['days' => config('scamsense.retention.submission_days')]) }}</p>
                    </li>
                    <li>
                        <span class="sc-priv__ico" aria-hidden="true">
                            <svg viewBox="0 0 20 20" focusable="false"><rect x="4" y="8.5" width="12" height="9" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M6.8 8.5V6.2a3.2 3.2 0 0 1 6.4 0v2.3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
                        </span>
                        <p class="mb-0">{{ __('We never open the link you paste, and we never ask who you are.') }}</p>
                    </li>
                </ul>
            </div>
        </div>
    </section>
@endsection

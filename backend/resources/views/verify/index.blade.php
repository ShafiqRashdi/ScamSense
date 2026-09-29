@extends('layouts.public')

@section('title', __('Check a message or link'))

@section('content')
    @php
        /*
         | Tap-to-fill examples.
         |
         | The emptiest, deadest part of this page was a large blank textarea,
         | and a first-time visitor has nothing to paste — they arrived to look
         | rather than because something happened to them. These give them
         | something to check, and they turn a demonstration into one tap
         | instead of a paste from a notes file.
         |
         | Content follows the SampleSubmissionSeeder convention: the shapes are
         | real Malaysian scams, the phone and account numbers are invented, and
         | the domains use TLDs that do not resolve. The redaction is visible in
         | the result, which is a useful thing for a reader to see happen.
         |
         | The bodies stay in Malay in both locales, deliberately. A Malaysian
         | who prefers an English interface still receives Malay scam SMS, so
         | translating them would show the user something they will never get.
         | Only the labels are translated.
         |
         | The LABELS ARE NEUTRAL on purpose — "Parcel notice", not "Parcel
         | scam". Naming the answer on the button spoils the check, and if the
         | model gets one of them wrong it would announce the failure on screen
         | before the verdict arrives. The interface should not pre-commit to a
         | verdict it has not produced.
         */
        $examples = [
            [
                'label' => __('Parcel notice'),
                'type' => 'message',
                // Checked end to end against the running model rather than
                // written and trusted. A near-identical first draft -- same
                // scam, same length, "Pakej anda gagal dihantar kerana alamat
                // tidak lengkap" -- landed in the medium band with no
                // indicators at all, which is a poor thing to hand a first-time
                // visitor as the opening example. The difference is phrasing,
                // not content, which is the same limit AGENTS.md records for
                // the two remaining probe misses.
                'text' => 'Parcel anda tidak dapat dihantar. Sila kemaskini '
                    .'maklumat anda di sini pos-semakan.xyz/update. Jika tidak '
                    .'bungkusan akan dipulangkan kepada pengirim.',
            ],
            [
                'label' => __('Prize message'),
                'type' => 'message',
                'text' => 'TAHNIAH! Nombor anda terpilih memenangi RM5,000 dalam '
                    .'cabutan bertuah. Hubungi 011-2345 6789 dan berikan nombor '
                    .'akaun bank anda untuk menuntut hadiah.',
            ],
            [
                'label' => __('Electricity bill'),
                'type' => 'message',
                'text' => 'TNB: Bil elektrik anda bagi bulan Ogos berjumlah '
                    .'RM128.40. Tarikh akhir bayaran 25 Ogos.',
            ],
            [
                'label' => __('Bank link'),
                'type' => 'url',
                'text' => 'http://maybank2u.secure-verify.xyz/login/update-account',
            ],
        ];
    @endphp

@section('hero')
    <div class="sc-hero">
        <h1>{{ __('Is this a scam?') }}</h1>
        <p>{{ __('Paste a message or a link and we will check it for you.') }}</p>
    </div>
@endsection

    {{-- The card breaks out of the masthead. That overlap is the move that
         makes the page read as designed rather than stacked, and it is why the
         hero lives in the layout's band rather than here. --}}
    <div class="sc-lift">
        <div class="sc-shell">

            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- `data-busy-form` opts this form into the submit feedback in
                 app.js. The verdict can take up to three seconds, and a button
                 that does nothing for three seconds gets pressed again. --}}
            <form method="POST" action="{{ route('verify.store') }}" class="sc-card" data-busy-form>
                @csrf
                <div class="sc-card__body">

                    <fieldset class="mb-4" data-busy-hide>
                        <legend class="form-label fs-6 fw-semibold">
                            {{ __('What are you checking?') }}
                        </legend>

                        {{-- Radio inputs, not buttons: this is a form control
                             and it must submit without JavaScript. The pill is
                             a decoration layered over them, positioned from
                             `:checked` in CSS — so it is already in the right
                             place on first paint, before app.js runs and even
                             if it never does. --}}
                        <div class="sc-seg" data-seg>
                            <input type="radio" class="sc-seg__input" name="type" id="type-message"
                                   value="message" autocomplete="off"
                                   {{ old('type', 'message') === 'message' ? 'checked' : '' }}>
                            <label class="sc-seg__opt" for="type-message">
                                {{ __('A message') }}
                            </label>

                            <input type="radio" class="sc-seg__input" name="type" id="type-url"
                                   value="url" autocomplete="off"
                                   {{ old('type') === 'url' ? 'checked' : '' }}>
                            <label class="sc-seg__opt" for="type-url">
                                {{ __('A link') }}
                            </label>

                            {{-- LAST, not first. `~` only matches siblings that
                                 come after the subject, so with the pill above
                                 the inputs the rule that moves it could never
                                 match -- the pill stayed left while the labels
                                 swapped colour, leaving white text on white and
                                 green text on the green fill. Both captions
                                 disappeared.

                                 Absolutely positioned, so DOM order costs
                                 nothing visually, and it sits below the labels
                                 on z-index regardless. --}}
                            <span class="sc-seg__pill" aria-hidden="true"></span>
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

                        <div class="sc-examples mt-3" role="group"
                             aria-label="{{ __('Example messages to try') }}">
                            <span class="small text-body-secondary me-1">
                                {{ __('Or try one of these:') }}
                            </span>

                            @foreach ($examples as $example)
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        data-example-type="{{ $example['type'] }}"
                                        data-example-text="{{ $example['text'] }}">
                                    {{ $example['label'] }}
                                </button>
                            @endforeach
                        </div>
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
    <div class="sc-band">
        <div class="sc-shell sc-how">
            @foreach ([
                ['01', 'Paste it', 'A message someone sent you, or a link you were asked to open. You never have to say who you are.'],
                ['02', 'We check it', 'We compare it against scams we have seen before. We never open the link you paste.'],
                ['03', 'You decide', 'You get an answer in plain words, with the reasons - so the next one you can spot yourself.'],
            ] as [$n, $title, $body])
                <div class="sc-step">
                    <span class="sc-step__n" aria-label="{{ __('STEP') }} {{ (int) $n }}">{{ (int) $n }}</span>
                    <h2>{{ __($title) }}</h2>
                    <p>{{ __($body) }}</p>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Privacy notice, required on the form itself (Phase 6 brief). --}}
    <div class="sc-band sc-band--last">
        <div class="sc-shell sc-priv">
            <h2>{{ __('What happens to what you paste') }}</h2>
            <ul>
                <li>{{ __('We remove phone numbers, IC numbers, emails and account numbers before saving anything.') }}</li>
                <li>{{ __('We keep the message for :days days so we can improve our scam detection, then delete it automatically.', ['days' => config('scamsense.retention.submission_days')]) }}</li>
                <li>{{ __('We never open the link you paste, and we never ask who you are.') }}</li>
            </ul>
        </div>
    </div>
@endsection

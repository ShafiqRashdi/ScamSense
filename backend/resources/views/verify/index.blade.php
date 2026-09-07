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

    <div class="row justify-content-center">
        <div class="col-12 col-md-9 col-lg-7">

            <h1 class="h3 mb-2">{{ __('Is this a scam?') }}</h1>
            <p class="lead text-body-secondary mb-4">
                {{ __('Paste a message or a link and we will check it for you.') }}
            </p>

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
            <form method="POST" action="{{ route('verify.store') }}" class="card shadow-sm" data-busy-form>
                @csrf
                <div class="card-body p-4">

                    <fieldset class="mb-4">
                        <legend class="form-label fs-6 fw-semibold">
                            {{ __('What are you checking?') }}
                        </legend>

                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="type" id="type-message"
                                   value="message" autocomplete="off"
                                   {{ old('type', 'message') === 'message' ? 'checked' : '' }}>
                            <label class="btn btn-outline-primary py-3" for="type-message">
                                {{ __('A message') }}
                            </label>

                            <input type="radio" class="btn-check" name="type" id="type-url"
                                   value="url" autocomplete="off"
                                   {{ old('type') === 'url' ? 'checked' : '' }}>
                            <label class="btn btn-outline-primary py-3" for="type-url">
                                {{ __('A link') }}
                            </label>
                        </div>
                    </fieldset>

                    <div class="mb-3">
                        <label for="content" class="form-label fw-semibold">
                            {{ __('Paste it here') }}
                        </label>
                        {{-- rows="3" rather than the tall fixed box this was.
                             app.js grows it to fit as the user types, so it
                             starts as an invitation rather than a void. Three
                             rows plus the min-height in the stylesheet is still
                             a comfortable target with no JavaScript at all. --}}
                        <textarea id="content"
                                  name="content"
                                  rows="3"
                                  data-autogrow
                                  class="form-control form-control-lg @error('content') is-invalid @enderror"
                                  required
                                  autofocus
                                  placeholder="{{ __('For example: Tahniah! Anda memenangi RM5,000...') }}"
                        >{{ old('content') }}</textarea>
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
                    <button type="submit" class="btn btn-primary btn-lg w-100 py-3"
                            data-busy-label="{{ __('Checking...') }}">
                        {{ __('Check it') }}
                    </button>

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
                    <div class="sc-busy mt-3" data-busy-panel hidden>
                        <div class="sc-busy__track" aria-hidden="true">
                            <span class="sc-busy__bar"></span>
                        </div>

                        <p class="sc-busy__status text-body-secondary small mb-0"
                           data-busy-status role="status" aria-live="polite"></p>
                    </div>

                    {{-- Read by app.js and rotated through the status line
                         above. Kept in the markup rather than in JS so the
                         strings stay translatable and stay with the rest of
                         the page copy. --}}
                    <ul data-busy-steps hidden>
                        <li>{{ __('Reading what you pasted...') }}</li>
                        <li>{{ __('Comparing it with scams we have seen before...') }}</li>
                        <li>{{ __('Getting your answer ready...') }}</li>
                    </ul>
                </div>
            </form>

            {{-- Privacy notice, required on the form itself (Phase 6 brief). --}}
            <div class="card border-0 bg-body-tertiary mt-4">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('What happens to what you paste') }}</h2>
                    <ul class="small text-body-secondary mb-0 ps-3">
                        <li>{{ __('We remove phone numbers, IC numbers, emails and account numbers before saving anything.') }}</li>
                        <li>{{ __('We keep the message for :days days so we can improve our scam detection, then delete it automatically.', ['days' => config('scamsense.retention.submission_days')]) }}</li>
                        <li>{{ __('We never open the link you paste, and we never ask who you are.') }}</li>
                    </ul>
                </div>
            </div>

        </div>
    </div>
@endsection

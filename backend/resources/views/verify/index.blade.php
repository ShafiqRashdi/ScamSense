@extends('layouts.public')

@section('title', __('Check a message or link'))

@section('content')
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
                        <textarea id="content"
                                  name="content"
                                  rows="6"
                                  class="form-control form-control-lg @error('content') is-invalid @enderror"
                                  required
                                  autofocus
                                  placeholder="{{ __('For example: Tahniah! Anda memenangi RM5,000...') }}"
                        >{{ old('content') }}</textarea>
                        @error('content')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
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

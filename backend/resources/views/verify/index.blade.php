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

            <form method="POST" action="{{ route('verify.store') }}" class="card shadow-sm">
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

                    <button type="submit" class="btn btn-primary btn-lg w-100 py-3">
                        {{ __('Check it') }}
                    </button>
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

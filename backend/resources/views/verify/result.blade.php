@extends('layouts.public')

@section('title', __('verdict.'.$verdict->riskLevel->value.'.label'))

@section('content')
    @php
        $risk = $verdict->riskLevel;
        $band = $risk->value;
        $defang = app(\App\Services\DefangService::class);
        $institution = $verdict->institution;
    @endphp

    <div class="row justify-content-center">
        <div class="col-12 col-md-9 col-lg-7">

            {{-- ── Verdict ────────────────────────────────────────────────
                 Risk is never conveyed by colour alone: the icon and the text
                 label carry the same meaning, because colour-only signalling
                 fails WCAG 1.4.1 and fails the older users this exists for. --}}
            <div class="card shadow-sm border-{{ $risk->colour() }} border-2 mb-4">
                <div class="card-body p-4">

                    <p class="d-flex align-items-center gap-2 mb-3">
                        <span class="badge text-bg-{{ $risk->colour() }} fs-6 px-3 py-2 d-inline-flex align-items-center gap-2">
                            {{-- The only unescaped output in the application.
                                 $risk is a RiskLevel enum with four cases and
                                 iconSvg() returns a hardcoded SVG string, so no
                                 user input can reach it. Pinned by a test that
                                 asserts the rendered SVG is unaffected by
                                 submitted content, so it cannot silently start
                                 taking user data later. --}}
                            <span aria-hidden="true">{!! $risk->iconSvg() !!}</span>
                            {{ __('verdict.'.$band.'.label') }}
                        </span>
                    </p>

                    <h1 class="h4 mb-3">{{ __('verdict.'.$band.'.heading') }}</h1>
                    <p class="fs-5 mb-4">{{ __('verdict.'.$band.'.body') }}</p>

                    <div class="p-3 rounded bg-body-tertiary">
                        <h2 class="h6 fw-semibold">{{ __('verdict.'.$band.'.action_heading') }}</h2>
                        <p class="mb-0">{{ __('verdict.'.$band.'.action') }}</p>
                    </div>

                    @if (__('verdict.'.$band.'.closing') !== 'verdict.'.$band.'.closing')
                        <p class="mt-3 mb-0 fw-semibold">{{ __('verdict.'.$band.'.closing') }}</p>
                    @endif

                    @if ($verdict->isPartial)
                        <div class="alert alert-secondary d-flex gap-2 mt-4 mb-0" role="status">
                            <span aria-hidden="true">&#9432;</span>
                            <span>{{ __('verdict.partial.notice') }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ── Verified address ───────────────────────────────────────
                 The most reassuring output the platform produces, and the one
                 most at risk of being read as "this page is safe".

                 The scoping paragraph is NOT collapsible and must stay visible
                 in every layout. Only the teaching section is disclosed
                 progressively, because four paragraphs is heavy at 375px. --}}
            @if ($verdict->gatedByVerifiedDomain && $institution)
                @php
                    $name = $institution->displayName();
                    $authority = __('verdict.authority.'.$institution->authority);
                @endphp

                <div class="card shadow-sm border-success border-2 mb-4">
                    <div class="card-body p-4">

                        <p class="d-flex align-items-center gap-2 mb-3">
                            <span class="badge text-bg-success fs-6 px-3 py-2 d-inline-flex align-items-center gap-2">
                                <span aria-hidden="true">&#10004;</span>
                                {{ __('verdict.verified.label') }}
                            </span>
                        </p>

                        <h2 class="h5 mb-3">
                            {{ __('verdict.verified.heading', ['institution' => $name]) }}
                        </h2>

                        <p class="fs-6">
                            @if ($institution->bySuffix)
                                {{ __('verdict.verified.body_suffix', [
                                    'authority' => $authority,
                                    'domain_suffix' => '.'.\Illuminate\Support\Str::after($institution->domain, '.'),
                                ]) }}
                            @else
                                {{ __('verdict.verified.body_institution', [
                                    'domain' => $institution->domain,
                                    'institution' => $name,
                                    'authority' => $authority,
                                ]) }}
                            @endif
                        </p>

                        {{-- Progressive disclosure: teaching only. --}}
                        <details class="mb-3">
                            <summary class="fw-semibold py-2" style="cursor: pointer;">
                                {{ __('verdict.verified.teach_heading') }}
                            </summary>
                            <p class="mt-2 mb-0 text-body-secondary">
                                {{ __('verdict.verified.teach', [
                                    'example' => $institution->domain.'.secure-verify.xyz',
                                    'institution' => $name,
                                ]) }}
                            </p>
                        </details>

                        {{-- Always visible. This is what stops the panel above
                             being read as a safety guarantee. --}}
                        <div class="p-3 rounded bg-body-tertiary">
                            <h3 class="h6 fw-semibold">{{ __('verdict.verified.scope_heading') }}</h3>
                            <p class="mb-0">{{ __('verdict.verified.scope') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ── Indicators ─────────────────────────────────────────────
                 Codes are mapped to prose here; the ML service never sends
                 user-facing text. --}}
            @if (count($verdict->indicators) > 0)
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-semibold mb-3">{{ __('verdict.indicators_heading') }}</h2>
                        <ul class="list-unstyled mb-0">
                            @foreach ($verdict->indicators as $indicator)
                                <li class="d-flex gap-2 mb-3">
                                    <span aria-hidden="true" class="text-body-secondary">&bull;</span>
                                    <div>
                                        <p class="mb-1">{{ __('indicators.'.$indicator->code) }}</p>
                                        @if ($indicator->evidence)
                                            <p class="small text-body-secondary font-monospace mb-0">
                                                {{ $defang->defangUrlsIn($indicator->evidence) }}
                                            </p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            {{-- ── What was submitted ─────────────────────────────────────
                 Defanged and inside a <pre>. Never an href, never {!! !!}. --}}
            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h6 fw-semibold">{{ __('verdict.submitted.heading') }}</h2>
                    <pre class="bg-body-tertiary p-3 rounded small text-wrap text-break mb-2"><code>{{ $defang->defangUrlsIn($submission->content_redacted) }}</code></pre>
                    <p class="small text-body-secondary mb-0">{{ __('verdict.submitted.note') }}</p>
                </div>
            </div>

            {{-- ── Community feedback (objective O3) ──────────────────────
                 Goes to a moderation queue. Nothing here reaches a model
                 without an administrator reviewing it — see ADR 0008. --}}
            @if (session('feedback_received'))
                <div class="alert alert-success" role="status">
                    {{ __('feedback.thanks') }}
                </div>
            @else
                <div class="card shadow-sm mb-4">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-semibold">{{ __('feedback.heading') }}</h2>
                        <p class="small text-body-secondary">{{ __('feedback.explain') }}</p>

                        <div class="d-flex gap-2">
                            @foreach ([1 => 'feedback.agree', 0 => 'feedback.dispute'] as $agrees => $key)
                                <form method="POST" action="{{ route('feedback.store') }}" class="flex-fill">
                                    @csrf
                                    <input type="hidden" name="verdict_id" value="{{ $verdictRecord->id }}">
                                    <input type="hidden" name="agrees" value="{{ $agrees }}">
                                    <button type="submit"
                                            class="btn btn-outline-{{ $agrees ? 'success' : 'secondary' }} w-100 py-3">
                                        {{ __($key) }}
                                    </button>
                                </form>
                            @endforeach
                        </div>

                        <p class="small text-body-secondary mt-3 mb-0">{{ __('feedback.moderated') }}</p>
                    </div>
                </div>
            @endif

            <a href="{{ route('verify.show') }}" class="btn btn-outline-primary btn-lg w-100 py-3">
                {{ __('verdict.check_another') }}
            </a>

        </div>
    </div>
@endsection

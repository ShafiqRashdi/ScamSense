@extends('layouts.public')

@section('title', __('verdict.'.$verdict->riskLevel->value.'.label'))

@section('content')
    @php
        $risk = $verdict->riskLevel;
        $band = $risk->value;
        $tone = 'sc-tone sc-tone--'.$risk->colour();
        $defang = app(\App\Services\DefangService::class);
        $institution = $verdict->institution;
        $verifiedFirst = $verdict->gatedByVerifiedDomain && $institution;

        /* Copy differs by what the user actually submitted. Telling someone who
           pasted an SMS that "this link has features we see in scam links"
           announces that the system did not read what they sent. */
        $type = $submission->type instanceof \App\Enums\SubmissionType
            ? $submission->type->value
            : (string) $submission->type;
    @endphp

@section('hero')
    {{-- Shallower than the landing page's hero: the answer is the point, and
         the card below has to clear the fold at 375px with enlarged text.

         A <p>, NOT an h1. ADR 0012 keeps exactly one h1 per document and it is
         always the strongest claim on the page -- the verified panel's, or
         failing that the verdict's. "We checked it" is a page label and a
         weaker claim than "This looks like a scam", so making it a heading
         would demote the answer to second place in the outline. --}}
    <div class="sc-hero sc-hero--slim">
        <p>{{ __('verdict.checked_heading') }}</p>
        <a class="sc-back" href="{{ route('verify.show') }}">&larr; {{ __('verdict.check_another') }}</a>
    </div>
@endsection

    <div class="sc-lift pb-5">
        <div class="sc-shell sc-stack">

            {{-- ── Verified address ───────────────────────────────────────
                 FIRST, deliberately. This is the answer the user came for, and
                 the strongest reassurance the platform can produce. Rendered
                 after the verdict card it sat behind "we did not find warning
                 signs / this does not guarantee the page is safe" — a generic
                 hedge read first is a hedge read instead, and at 375px with
                 enlarged text the heading fell below the fold.

                 The scoping paragraph is NOT collapsible and must stay visible
                 in every layout. Only the teaching section is disclosed
                 progressively, because four paragraphs is heavy at 375px. --}}
            @if ($verifiedFirst)
                @php
                    $name = $institution->displayName();
                    $authority = __('verdict.authority.'.$institution->authority);
                @endphp

                <section class="sc-card sc-card--verified sc-tone sc-tone--success">
                    <span class="sc-card__rule" aria-hidden="true"></span>
                    <div class="sc-card__body">
                        <span class="sc-badge">
                            <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                                <path d="M10 1.8 3.5 4.5v5.1c0 4 2.7 7.3 6.5 8.5 3.8-1.2 6.5-4.5 6.5-8.5V4.5Z" fill="none"
                                      stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/>
                                <path d="m7 10 2.1 2.1 4-4.4" fill="none" stroke="currentColor" stroke-width="2"
                                      stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            {{ __('verdict.verified.label') }}
                        </span>

                        <h1 class="sc-card__verdict">
                            {{ __('verdict.verified.heading', ['institution' => $name]) }}
                        </h1>

                        <p class="sc-lead">
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

                        {{-- Always visible. This is what stops the panel above
                             being read as a safety guarantee. --}}
                        <div class="p-3 rounded bg-body-tertiary mt-3">
                            <h2 class="h6 fw-semibold">{{ __('verdict.verified.scope_heading') }}</h2>
                            <p class="mb-0">{{ __('verdict.verified.scope') }}</p>
                        </div>

                        {{-- Progressive disclosure: teaching only. --}}
                        <details class="sc-disclose">
                            <summary>{{ __('verdict.verified.teach_heading') }}</summary>
                            <p>
                                {{ __('verdict.verified.teach', [
                                    'example' => $institution->domain.'.secure-verify.xyz',
                                    'institution' => $name,
                                ]) }}
                            </p>
                        </details>
                    </div>
                </section>
            @endif

            {{-- ── Verdict ────────────────────────────────────────────────
                 Risk is never conveyed by colour alone: the icon and the text
                 label carry the same meaning, because colour-only signalling
                 fails WCAG 1.4.1 and fails the older users this exists for.

                 The rule is its own element so it can DRAW across as the card
                 lands. The risk colour arriving is the decision being made,
                 rather than a bar that was always there. --}}
            <section class="sc-card sc-card--verdict {{ $tone }}">
                <span class="sc-card__rule" aria-hidden="true"></span>
                <div class="sc-card__body">
                    <span class="sc-badge">
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

                    {{-- When the verified panel is shown it owns the h1, so this
                         steps down to h2. The document keeps exactly one h1 and
                         it is always the strongest claim on the page. The action
                         heading sits one level below whichever the verdict took,
                         so the outline never skips h1 -> h3. --}}
                    @php
                        $verdictTag = $verifiedFirst ? 'h2' : 'h1';
                        $actionTag = $verdictTag === 'h1' ? 'h2' : 'h3';
                    @endphp
                    <{{ $verdictTag }} class="sc-card__verdict">{{ __('verdict.'.$band.'.heading') }}</{{ $verdictTag }}>
                    <p class="sc-lead">{{ __('verdict.'.$band.'.body.'.$type) }}</p>

                    <div class="sc-todo">
                        <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                            <path d="M4 10h11M11 5.5 15.5 10 11 14.5" fill="none" stroke="currentColor" stroke-width="2"
                                  stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <{{ $actionTag }}>{{ __('verdict.'.$band.'.action_heading') }}</{{ $actionTag }}>
                        <p>{{ __('verdict.'.$band.'.action.'.$type) }}</p>
                    </div>

                    @if (__('verdict.'.$band.'.closing') !== 'verdict.'.$band.'.closing')
                        <p class="mt-3 mb-0 fw-semibold">{{ __('verdict.'.$band.'.closing') }}</p>
                    @endif

                    @if ($verdict->isPartial)
                        <div class="alert alert-secondary d-flex gap-2 mt-4 mb-0" role="status">
                            <svg class="sc-glyph mt-1" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                                <circle cx="8" cy="8" r="6.6" fill="none" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M8 7.2v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                                <circle cx="8" cy="4.9" r="0.95" fill="currentColor"/>
                            </svg>
                            <span>{{ __('verdict.partial.notice') }}</span>
                        </div>
                    @endif
                </div>
            </section>

            {{-- ── The reasons, and the text they came from ─────────────────
                 Two columns on a wide screen: each reason beside the words it
                 points into. On a phone they stack, reasons first -- the reader
                 wants the reasons before the echo of their own input.

                 A reason carries a number only when SubmissionExplainer found
                 its evidence in the text, and the same number is marked there.
                 A reason it could not locate keeps the plain diamond and its
                 evidence line, rather than a number pointing at nothing.

                 The card renders even with nothing to list. An empty list is a
                 real finding — most often in the medium band, where the model
                 reached "not certain" on continuous features that are
                 deliberately never surfaced (see app/indicators.py). Hiding the
                 section entirely left the reader with "parts of this look odd"
                 and nothing named, which reads as a list that failed to load. --}}
            <div class="sc-cols {{ $verifiedFirst ? 'sc-tone sc-tone--success' : $tone }}" data-evidence-links>
                <section class="sc-panel">
                    <h2 class="sc-panel__title">{{ __('verdict.indicators_heading') }}</h2>

                    @if (count($verdict->indicators) > 0)
                        @if ($explanation->hasMarks())
                            <p class="sc-panel__hint">{{ __('verdict.indicators_hint') }}</p>
                        @endif

                        <ol class="sc-reasons">
                            @foreach ($verdict->indicators as $i => $indicator)
                                @php $number = $explanation->numbers[$i] ?? null; @endphp
                                <li class="sc-reason" @if ($number) data-n="{{ $number }}" tabindex="0" @endif>
                                    @if ($number)
                                        <span class="sc-num" aria-hidden="true">{{ $number }}</span>
                                    @else
                                        {{-- A drawn mark, not a bullet character
                                             and never an emoji: it has to be the
                                             band's colour at any text size and it
                                             must not depend on a font. --}}
                                        <svg width="17" height="17" viewBox="0 0 16 16"
                                             aria-hidden="true" focusable="false">
                                            <path d="M8 1.6 14.4 8 8 14.4 1.6 8Z" fill="currentColor"/>
                                        </svg>
                                    @endif
                                    <div>
                                        <p>{{ __('indicators.'.$indicator->code) }}</p>
                                        @if (! $number && $indicator->evidence)
                                            <p class="small text-body-secondary font-monospace mb-0">
                                                {{ $defang->defangUrlsIn($indicator->evidence) }}
                                            </p>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="mb-0">{{ __('verdict.indicators_none.'.$type) }}</p>
                    @endif
                </section>

                {{-- ── What was submitted ─────────────────────────────────
                     Defanged and inside a <pre>. Never an href, never {!! !!}:
                     each segment is escaped by {{ }} and only its CLASS varies.
                     Written on one line because whitespace inside a <pre> is
                     content. The empty echo between @endif and @endforeach
                     is load-bearing: Blade only recognises a directive whose
                     @ does not follow a letter, and a space there would be
                     printed after every segment. --}}
                <section class="sc-panel">
                    <h2 class="sc-panel__title">{{ __('verdict.submitted.heading') }}</h2>
                    <pre class="sc-echo"><code>@foreach ($explanation->segments as $segment)@if ($segment->number)<mark class="sc-hl" data-n="{{ $segment->number }}"><span class="sc-num" aria-hidden="true">{{ $segment->number }}</span>{{ $segment->text }}</mark>@elseif ($segment->isMask)<span class="sc-mask">{{ $segment->text }}</span>@else{{ $segment->text }}@endif{{ '' }}@endforeach</code></pre>

                    @if ($explanation->anatomy)
                        @php $anatomy = $explanation->anatomy; @endphp
                        {{-- ── How to read this address ─────────────────────
                             The owner is the part just before the first single
                             slash; a company name anywhere else proves nothing.
                             Shown on every link, genuine or not, because the
                             habit is the same either way. --}}
                        <div class="sc-anat">
                            <h3>{{ __('verdict.anatomy.heading') }}</h3>
                            <div class="sc-anat__url"><span class="sc-anat__lead">{{ $anatomy->lead }}</span>@if ($anatomy->prefix !== '')<span @class(['sc-anat__copy' => $anatomy->prefixIsCopiedName])>{{ $anatomy->prefix }}</span>@endif<span class="sc-anat__owner">{{ $anatomy->owner }}</span><span class="sc-anat__tail">{{ $anatomy->tail }}</span></div>
                            <ul class="sc-legend">
                                <li><span class="sc-key sc-key--owner" aria-hidden="true"></span><span>{{ __('verdict.anatomy.owner') }}</span></li>
                                @if ($anatomy->prefixIsCopiedName)
                                    <li><span class="sc-key sc-key--copy" aria-hidden="true"></span><span>{{ __('verdict.anatomy.copied') }}</span></li>
                                @endif
                                @if ($anatomy->tail !== '')
                                    <li><span class="sc-key sc-key--tail" aria-hidden="true"></span><span>{{ __('verdict.anatomy.tail') }}</span></li>
                                @endif
                            </ul>
                        </div>
                    @endif

                    <p class="sc-panel__note">
                        {{ $type === 'message' ? __('verdict.submitted.note_message') : __('verdict.submitted.note') }}
                    </p>
                </section>
            </div>

            {{-- ── Warn your family ────────────────────────────────────────
                 High band only. Scam messages travel through family groups,
                 so the most useful thing a reader can do next is often to
                 warn someone else. The copied text keeps "likely" and keeps
                 the link defanged — a forwarded message is where an overclaim
                 or a live link would travel furthest. --}}
            @if ($band === 'high')
                @php
                    $safe = $defang->defangUrlsIn($submission->content_redacted);
                    $excerpt = \Illuminate\Support\Str::limit($safe, 110, '...');
                    $shareText = $type === 'url'
                        ? __('verdict.share.message.url')."\n".$excerpt
                        : __('verdict.share.message.message')."\n\n\"".$excerpt.'"';
                @endphp
                <section class="sc-panel" aria-labelledby="share-heading">
                    <h2 class="sc-panel__title" id="share-heading">{{ __('verdict.share.heading') }}</h2>
                    <p class="sc-panel__hint mb-0">{{ __('verdict.share.explain') }}</p>
                    <div class="sc-bubble" id="share-text">{{ $shareText }}</div>
                    {{-- Hidden until app.js confirms the clipboard exists; with
                         no JavaScript the bubble is still plain, selectable text. --}}
                    <button type="button" class="sc-copy" data-copy="share-text"
                            data-copied-label="{{ __('verdict.share.copied') }}" hidden>
                        <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                            <rect x="7" y="7" width="10" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M13 4.5V4a1.5 1.5 0 0 0-1.5-1.5h-7A1.5 1.5 0 0 0 3 4v7a1.5 1.5 0 0 0 1.5 1.5H5"
                                  fill="none" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                        <span data-copy-label>{{ __('verdict.share.button') }}</span>
                    </button>
                    <p class="sc-note">
                        <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                            <rect x="4" y="8.5" width="12" height="9" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/>
                            <path d="M6.8 8.5V6.2a3.2 3.2 0 0 1 6.4 0v2.3" fill="none" stroke="currentColor" stroke-width="1.8"/>
                        </svg>
                        <span>{{ __('verdict.share.note') }}</span>
                    </p>
                </section>
            @endif

            {{-- ── Community feedback (objective O3) ──────────────────────
                 Goes to a moderation queue. Nothing here reaches a model
                 without an administrator reviewing it — see ADR 0008. --}}
            @if (session('feedback_received'))
                <div class="alert alert-success mb-0" role="status">
                    {{ __('feedback.thanks') }}
                </div>
            @else
                <section class="sc-panel">
                    <h2 class="sc-panel__title">{{ __('feedback.heading') }}</h2>
                    <p class="sc-panel__hint mb-0">{{ __('feedback.explain') }}</p>

                    <div class="d-flex gap-2 mt-3">
                        @foreach ([1 => 'feedback.agree', 0 => 'feedback.dispute'] as $agrees => $key)
                            <form method="POST" action="{{ route('feedback.store') }}" class="flex-fill">
                                @csrf
                                <input type="hidden" name="verdict_id" value="{{ $verdictRecord->id }}">
                                <input type="hidden" name="agrees" value="{{ $agrees }}">
                                <button type="submit" class="btn btn-outline-secondary w-100 py-3">
                                    {{ __($key) }}
                                </button>
                            </form>
                        @endforeach
                    </div>

                    <p class="small text-body-secondary mt-3 mb-0">{{ __('feedback.moderated') }}</p>
                </section>
            @endif

            {{-- Solid, because it is the page's one next step. --}}
            <a href="{{ route('verify.show') }}" class="btn btn-primary btn-lg w-100 py-3">
                {{ __('verdict.check_another') }}
            </a>

        </div>
    </div>
@endsection

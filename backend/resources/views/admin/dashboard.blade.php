<x-app-layout>
    <x-slot name="header">
        <h1 class="h4 mb-0">{{ __('Dashboard') }}</h1>
    </x-slot>

    {{-- ── Headline counters ──────────────────────────────────────────────
         Three, not four, and none of them restates a panel below.

         "In retraining set" and "Partial verdicts" used to sit here as well,
         but both were the same figures the Retraining set and Degraded verdicts
         panels already break down — and "Pending review" appears a third time
         on the moderation page itself. A number repeated across a screen is not
         emphasis; it just costs the reader a moment working out whether the two
         are the same thing.

         The degraded rate is promoted here because it is the one figure worth
         checking daily: a rising rate means a dependency is failing quietly
         while users still get answers, so nothing looks broken from outside.
         The panel below now carries only the breakdown by reason. --}}
    <div class="row g-3 mb-4">
        @php
            $counters = [
                ['Submissions', number_format($data['totals']['submissions']), 'body-emphasis', null, null],
                [
                    'Pending review',
                    number_format($data['moderation']['pending']),
                    $data['moderation']['pending'] > 0 ? 'warning' : 'body-emphasis',
                    route('admin.moderation.index'),
                    __('Review'),
                ],
                [
                    'Degraded verdicts',
                    number_format($data['partial']['rate'] * 100, 1).'%',
                    $data['partial']['rate'] > 0 ? 'danger' : 'success',
                    '#degraded',
                    __('Why'),
                ],
            ];
        @endphp

        @foreach ($counters as [$label, $value, $colour, $link, $linkText])
            <div class="col-12 col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <p class="text-body-secondary small mb-1">{{ __($label) }}</p>
                        <p class="h3 mb-0 text-{{ $colour }}">{{ $value }}</p>
                        @if ($link)
                            <a href="{{ $link }}" class="small">{{ $linkText }}</a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-4">

        {{-- ── Submission volume ──────────────────────────────────────── --}}
        <div class="col-12 col-xl-7">
            <div class="card h-100">
                <div class="card-body">
                    {{-- The range control lives here rather than in the page
                         header because this is the only thing it changes.
                         `DashboardService::summary()` passes `$days` to
                         `submissionVolume()` and to nothing else, so every other
                         figure on this page is all-time. In the header the
                         control read as a page-wide filter and quietly implied
                         that the counters beside it followed the same window. --}}
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h2 class="h6 fw-semibold mb-0">{{ __('Submissions over time') }}</h2>
                        <div class="btn-group btn-group-sm" role="group"
                             aria-label="{{ __('Change the range of this chart') }}">
                            @foreach ([7, 30, 90] as $option)
                                <a href="{{ route('admin.dashboard', ['days' => $option]) }}"
                                   class="btn {{ $days === $option ? 'btn-secondary' : 'btn-outline-secondary' }}"
                                   @if ($days === $option) aria-current="true" @endif>
                                    {{ $option }}d
                                </a>
                            @endforeach
                        </div>
                    </div>
                    <p class="small text-body-secondary mt-2">
                        {{ __(':total submissions across :days days. Days with no traffic are shown as zero.', [
                            'total' => number_format($data['volume']['total']),
                            'days' => $data['volume']['days'],
                        ]) }}
                        {{ __('This range applies to this chart only; every other figure on this page is all time.') }}
                    </p>

                    @if ($data['volume']['peak'] > 0)
                        {{-- A CSS bar chart rather than a charting library: the CSP
                             forbids remote scripts, and this needs no JavaScript
                             at all. Each bar carries its numbers as text so the
                             chart is readable by a screen reader. --}}
                        <div class="d-flex align-items-end gap-1" style="height: 160px;" role="img"
                             aria-label="{{ __('Daily submission volume') }}">
                            @foreach ($data['volume']['series'] as $day)
                                @php $pct = $data['volume']['peak'] > 0 ? ($day['total'] / $data['volume']['peak']) * 100 : 0; @endphp
                                <div class="flex-fill d-flex flex-column justify-content-end h-100"
                                     title="{{ $day['date'] }}: {{ $day['total'] }}">
                                    <div class="bg-primary rounded-top"
                                         style="height: {{ max($pct, $day['total'] > 0 ? 3 : 0) }}%"></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="d-flex justify-content-between small text-body-secondary mt-1">
                            <span>{{ $data['volume']['series'][0]['date'] }}</span>
                            <span>{{ __('peak :n/day', ['n' => $data['volume']['peak']]) }}</span>
                            <span>{{ end($data['volume']['series'])['date'] }}</span>
                        </div>
                    @else
                        <p class="text-body-secondary mb-0">{{ __('No submissions in this period.') }}</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ── Verdict distribution ───────────────────────────────────── --}}
        <div class="col-12 col-xl-5">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('Verdict distribution') }}</h2>
                    <table class="table table-sm align-middle mb-3">
                        <tbody>
                            @foreach ($data['verdicts']['risk'] as $row)
                                <tr>
                                    <td style="width: 6rem">
                                        <span class="badge text-bg-{{ $row['colour'] }}">{{ $row['level'] }}</span>
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 8px;" role="progressbar"
                                             aria-valuenow="{{ round($row['share'] * 100) }}"
                                             aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar bg-{{ $row['colour'] }}"
                                                 style="width: {{ $row['share'] * 100 }}%"></div>
                                        </div>
                                    </td>
                                    <td class="text-end small">{{ number_format($row['count']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <p class="small text-body-secondary mb-0">
                        {{ __(':n verdicts were capped by the verified-address gate.', [
                            'n' => number_format($data['verdicts']['gated']),
                        ]) }}
                        <br>
                        <span class="fst-italic">{{ __('That is a safety mitigation, not model accuracy.') }}</span>
                    </p>
                </div>
            </div>
        </div>

        {{-- ── Model performance, read from metrics.json ───────────────────
             Only the serving models are shown. Every superseded artefact is
             behind the disclosure below.

             `ModelMetricsReader` returns every directory holding a
             metrics.json, which today is ten — two serving and eight retired
             (the random-split runs, the pre-ablation runs, the url_length
             removal test). Rendering all ten made this panel more than half the
             page, and the count grows with every retrain. The retired runs are
             real evidence and worth keeping reachable; they are simply not what
             anyone opens this page to see. --}}
        @php
            $servingModels = array_values(array_filter($data['models'], fn (array $m): bool => $m['is_shipped']));
            $otherModels = array_values(array_filter($data['models'], fn (array $m): bool => ! $m['is_shipped']));
        @endphp

        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('Model performance') }}</h2>
                    <p class="small text-body-secondary">
                        {{ __('Read from the committed metrics.json files, so these cannot drift from the figures in the report.') }}
                    </p>

                    @forelse ($servingModels as $model)
                        <x-admin.model-metrics :model="$model" />
                    @empty
                        <p class="text-warning-emphasis mb-0">
                            {{ __('No serving model found. Check scamsense.shipped_models and scamsense.models_path.') }}
                        </p>
                    @endforelse

                    @if ($otherModels)
                        <details class="mt-3">
                            <summary class="small fw-semibold py-1" style="cursor: pointer;">
                                {{ __('Show superseded and experimental runs (:n)', ['n' => count($otherModels)]) }}
                            </summary>
                            <p class="small text-body-secondary mt-2 mb-2">
                                {{ __('Kept for traceability. None of these is serving traffic.') }}
                            </p>
                            @foreach ($otherModels as $model)
                                <x-admin.model-metrics :model="$model" />
                            @endforeach
                        </details>
                    @endif
                </div>
            </div>
        </div>

        {{-- ── Indicators, threat intel, retraining set ───────────────── --}}
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('Most frequent indicators') }}</h2>
                    <table class="table table-sm small mb-0">
                        <tbody>
                            @forelse ($data['indicators'] as $indicator)
                                <tr>
                                    <td>
                                        {{ $indicator['code'] }}
                                        @unless ($indicator['is_model'])
                                            <span class="badge text-bg-light">{{ $indicator['source'] }}</span>
                                        @endunless
                                    </td>
                                    <td class="text-end">{{ number_format($indicator['count']) }}</td>
                                </tr>
                            @empty
                                <tr><td class="text-body-secondary">{{ __('None yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('Threat-intel cache') }}</h2>
                    <p class="display-6 mb-0">{{ number_format($data['threatIntel']['hit_rate'] * 100, 1) }}%</p>
                    <p class="small text-body-secondary">{{ __('entries still within their 24-hour life') }}</p>
                    <dl class="row small mb-0">
                        <dt class="col-7">{{ __('Cached lookups') }}</dt>
                        <dd class="col-5 text-end">{{ number_format($data['threatIntel']['total']) }}</dd>
                        <dt class="col-7">{{ __('Flagged malicious') }}</dt>
                        <dd class="col-5 text-end">{{ number_format($data['threatIntel']['malicious']) }}</dd>
                        <dt class="col-7">{{ __('Expired') }}</dt>
                        <dd class="col-5 text-end">{{ number_format($data['threatIntel']['expired']) }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('Retraining set') }}</h2>
                    <dl class="row small mb-2">
                        <dt class="col-7">{{ __('From the community') }}</dt>
                        <dd class="col-5 text-end">{{ number_format($data['training']['community']) }}</dd>
                        <dt class="col-7">{{ __('From the original corpus') }}</dt>
                        <dd class="col-5 text-end">{{ number_format($data['training']['corpus']) }}</dd>
                        <dt class="col-7">{{ __('Awaiting export') }}</dt>
                        <dd class="col-5 text-end">{{ number_format($data['training']['awaiting_export']) }}</dd>
                    </dl>
                    <p class="small text-body-secondary mb-0">
                        {{ __('Community rows reach this table only through explicit approval. Nothing here has been trained on until it is exported into a dataset version.') }}
                    </p>
                </div>
            </div>
        </div>

        {{-- ── Degradation and traceability ───────────────────────────── --}}
        {{-- The rate itself is a headline counter now; this panel carries only
             the breakdown, which is what turns "something is wrong" into
             "the ML service is unreachable". --}}
        <div class="col-12 col-lg-6" id="degraded">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('Why verdicts were incomplete') }}</h2>
                    <p class="small text-body-secondary">
                        {{ __(':n of :total verdicts could not be completed. A rising rate means a dependency is failing quietly.', [
                            'n' => number_format($data['partial']['partial']),
                            'total' => number_format($data['partial']['total']),
                        ]) }}
                    </p>
                    @if ($data['partial']['reasons'])
                        <table class="table table-sm small mb-2">
                            <tbody>
                                @foreach ($data['partial']['reasons'] as $reason => $count)
                                    <tr>
                                        <td class="font-monospace">{{ $reason }}</td>
                                        <td class="text-end">{{ number_format($count) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="small text-body-secondary mb-0">
                            {{ __('Run php artisan scamsense:doctor to find out which dependency is failing.') }}
                        </p>
                    @else
                        <p class="text-body-secondary mb-0">{{ __('Every verdict completed.') }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('Verdicts by model version') }}</h2>
                    <p class="small text-body-secondary">
                        {{ __('Every verdict records the model that produced it, so any screenshot is traceable.') }}
                    </p>
                    <table class="table table-sm small mb-0">
                        <tbody>
                            @foreach ($byModelVersion as $version => $count)
                                <tr>
                                    <td class="font-monospace">{{ $version }}</td>
                                    <td class="text-end">{{ number_format($count) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</x-app-layout>

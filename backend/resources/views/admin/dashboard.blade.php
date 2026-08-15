<x-app-layout>
    <x-slot name="header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h1 class="h4 mb-0">{{ __('Dashboard') }}</h1>
            <div class="btn-group btn-group-sm" role="group">
                @foreach ([7, 30, 90] as $option)
                    <a href="{{ route('admin.dashboard', ['days' => $option]) }}"
                       class="btn {{ $days === $option ? 'btn-secondary' : 'btn-outline-secondary' }}">
                        {{ $option }}d
                    </a>
                @endforeach
            </div>
        </div>
    </x-slot>

    {{-- ── Headline counters ──────────────────────────────────────────── --}}
    <div class="row g-3 mb-4">
        @foreach ([
            ['Submissions', $data['totals']['submissions'], 'primary', null],
            ['Pending review', $data['moderation']['pending'], 'warning', route('admin.moderation.index')],
            ['In retraining set', $data['training']['community'] + $data['training']['corpus'], 'success', null],
            ['Partial verdicts', $data['partial']['partial'], 'secondary', null],
        ] as [$label, $value, $colour, $link])
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <p class="text-body-secondary small mb-1">{{ __($label) }}</p>
                        <p class="h3 mb-0 text-{{ $colour }}">{{ number_format($value) }}</p>
                        @if ($link)
                            <a href="{{ $link }}" class="small">{{ __('Review') }}</a>
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
                    <h2 class="h6 fw-semibold">{{ __('Submissions over time') }}</h2>
                    <p class="small text-body-secondary">
                        {{ __(':total submissions across :days days. Days with no traffic are shown as zero.', [
                            'total' => number_format($data['volume']['total']),
                            'days' => $data['volume']['days'],
                        ]) }}
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

        {{-- ── Model performance, read from metrics.json ──────────────── --}}
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('Model performance') }}</h2>
                    <p class="small text-body-secondary">
                        {{ __('Read from the committed metrics.json files, so these cannot drift from the figures in the report.') }}
                    </p>

                    @forelse ($data['models'] as $model)
                        <div class="border rounded p-3 mb-2 {{ $model['is_shipped'] ? 'border-success border-2' : '' }}">
                            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                <span class="font-monospace small">{{ $model['name'] }}</span>
                                <span>
                                    @if ($model['is_shipped'])
                                        <span class="badge text-bg-success">{{ __('serving') }}</span>
                                    @elseif ($model['status'])
                                        <span class="badge text-bg-warning">{{ __('experiment') }}</span>
                                    @else
                                        <span class="badge text-bg-secondary">{{ __('superseded') }}</span>
                                    @endif
                                </span>
                            </div>

                            @if ($model['f1'] !== null)
                                <div class="row g-2 small">
                                    @foreach ([
                                        'F1' => $model['f1'],
                                        __('Precision') => $model['precision'],
                                        __('Recall') => $model['recall'],
                                        'ROC AUC' => $model['roc_auc'],
                                    ] as $metric => $value)
                                        <div class="col-6 col-md-3">
                                            <span class="text-body-secondary">{{ $metric }}</span>
                                            <span class="fw-semibold">
                                                {{ $value === null ? '—' : number_format((float) $value, 4) }}
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            {{-- The caveat travels with the figure. A metric shown
                                 without it is how "F1 on a 528-message corpus"
                                 becomes "97% accurate" in someone's slides. --}}
                            @if ($model['qualifier'])
                                <p class="small text-body-secondary fst-italic mt-2 mb-0">
                                    {{ $model['qualifier'] }}
                                </p>
                            @endif
                            @if ($model['status'])
                                <p class="small text-warning-emphasis mt-2 mb-0">{{ $model['status'] }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-body-secondary mb-0">
                            {{ __('No metrics.json found. Check scamsense.models_path.') }}
                        </p>
                    @endforelse
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
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('Degraded verdicts') }}</h2>
                    <p class="small text-body-secondary">
                        {{ __('A rising rate means a dependency is failing quietly.') }}
                    </p>
                    <p class="h4">{{ number_format($data['partial']['rate'] * 100, 1) }}%</p>
                    @if ($data['partial']['reasons'])
                        <table class="table table-sm small mb-0">
                            <tbody>
                                @foreach ($data['partial']['reasons'] as $reason => $count)
                                    <tr>
                                        <td class="font-monospace">{{ $reason }}</td>
                                        <td class="text-end">{{ number_format($count) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
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

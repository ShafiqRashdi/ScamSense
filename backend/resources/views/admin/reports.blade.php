<x-app-layout>
    <x-slot name="header">
        <h1 class="h4 mb-0">{{ __('Reports and downloads') }}</h1>
    </x-slot>

    {{-- ── Why this page exists ────────────────────────────────────────────
         The dashboard had grown to nine panels, five of which were reference
         rather than anything to act on today. Together they made a page to be
         searched rather than read, and they buried the degraded-verdict rate,
         which is the one figure worth checking daily.

         The split is by the question each panel answers. The dashboard answers
         "is anything wrong, and is there anything for me to do?". This page
         answers "what does the data say, and can I take it away?" -- which is
         why every panel below sits beside a download rather than trying to be
         a table somebody can sort. --}}

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h6 fw-semibold">{{ __('Downloads') }}</h2>
            <p class="small text-body-secondary">
                {{ __('CSV opens in Excel or Google Sheets. Every download is recorded against your account.') }}
            </p>

            <div class="sc-downloads">
                @foreach ([
                    ['admin.exports.submissions', 'Submissions and verdicts', 'Every submission with the verdict it produced. The stored text is already redacted.'],
                    ['admin.exports.feedback', 'Moderation decisions', 'The audit trail for the moderation gate: who approved or rejected each report, and when.'],
                    ['admin.exports.training', 'Retraining set', 'Approved rows and the dataset version each was exported into. A blank version means it has never been trained on.'],
                    ['admin.exports.indicators', 'Indicator frequency', 'How often each indicator code has fired.'],
                    ['admin.exports.model-versions', 'Verdicts by model version', 'Which model produced how many verdicts.'],
                    ['admin.exports.model-metrics', 'Model metrics (JSON)', 'The committed metrics.json files, qualifier included. JSON so a figure cannot be separated from its caveat.'],
                ] as [$route, $label, $note])
                    <a href="{{ route($route) }}" class="sc-download">
                        <span class="sc-download__label">{{ __($label) }}</span>
                        <span class="sc-download__note">{{ __($note) }}</span>
                    </a>
                @endforeach
            </div>

            {{-- Said plainly rather than left implicit. Submissions contain text
                 a third party wrote, and an administrator taking a copy of it
                 should be reminded that it is still personal data once it is
                 a file on a laptop. --}}
            <p class="small text-body-secondary mb-0 mt-3">
                {{ __('Downloaded files leave the retention policy behind. Delete them when you are finished with them.') }}
            </p>
        </div>
    </div>

    <div class="row g-4">

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

        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 fw-semibold">{{ __('Verdicts by model version') }}</h2>
                    <p class="small text-body-secondary">
                        {{ __('Every verdict records the model that produced it, so any screenshot is traceable.') }}
                    </p>
                    <table class="table table-sm small mb-0">
                        <tbody>
                            @foreach ($data['byModelVersion'] as $version => $count)
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

@props(['model'])

{{--
    One model artefact's figures.

    Extracted so the serving models and the superseded ones behind the
    disclosure render identically — a retired run shown in a different shape
    than a serving one invites the reader to assume the numbers were produced
    differently, when the only difference is which artefact is loaded.
--}}
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

    {{-- The caveat travels with the figure. A metric shown without it is how
         "F1 on a 528-message corpus" becomes "97% accurate" in someone's
         slides. --}}
    @if ($model['qualifier'])
        <p class="small text-body-secondary fst-italic mt-2 mb-0">{{ $model['qualifier'] }}</p>
    @endif

    @if ($model['status'])
        <p class="small text-warning-emphasis mt-2 mb-0">{{ $model['status'] }}</p>
    @endif
</div>

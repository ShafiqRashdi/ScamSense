<x-app-layout>
    <x-slot name="header">
        <h1 class="h4 mb-0">{{ __('Moderation queue') }}</h1>
    </x-slot>

    @php $defang = app(\App\Services\DefangService::class); @endphp

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    {{-- Counters that make the gate legible: how many are waiting, how many
         have been promoted, and how many are approved but not yet in any
         exported dataset version. --}}
    <div class="row g-3 mb-4">
        @foreach ([
            ['Pending review', $pendingCount, 'warning'],
            ['Approved', $approvedCount, 'success'],
            ['In retraining set', $promotedCount, 'primary'],
            ['Not yet exported', $unexportedCount, 'secondary'],
        ] as [$label, $count, $colour])
            <div class="col-6 col-md-3">
                <div class="card h-100">
                    <div class="card-body">
                        <p class="text-body-secondary small mb-1">{{ __($label) }}</p>
                        <p class="h3 mb-0 text-{{ $colour }}">{{ $count }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="alert alert-info small" role="note">
        <strong>{{ __('Approving adds this to the retraining set.') }}</strong>
        {{ __('Choose the label you believe is correct — not the one the reporter asserted. There is no bulk approve: each item is reviewed on its own.') }}
    </div>

    @forelse ($pending as $feedback)
        @php $submission = $feedback->verdict->submission; @endphp

        <div class="card mb-3">
            <div class="card-body">

                <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                    <span class="badge text-bg-{{ $feedback->agrees ? 'success' : 'danger' }}">
                        {{ $feedback->agrees ? __('Reporter agrees with the verdict') : __('Reporter disputes the verdict') }}
                    </span>
                    <span class="small text-body-secondary">
                        #{{ $feedback->id }} &middot; {{ $feedback->created_at->diffForHumans() }}
                    </span>
                </div>

                <dl class="row small mb-3">
                    <dt class="col-sm-3">{{ __('Our verdict') }}</dt>
                    <dd class="col-sm-9">
                        <span class="badge text-bg-{{ $feedback->verdict->risk_level->colour() }}">
                            {{ $feedback->verdict->label->value }} / {{ $feedback->verdict->risk_level->value }}
                        </span>
                        <span class="text-body-secondary">
                            {{ __('confidence') }} {{ $feedback->verdict->confidence }}
                            &middot; {{ $feedback->verdict->model_version ?? __('no model') }}
                        </span>
                    </dd>

                    <dt class="col-sm-3">{{ __('Submitted') }}</dt>
                    <dd class="col-sm-9">
                        {{-- Defanged here too: an administrator clicking a live
                             phishing link from the admin panel is the same
                             accident, with more privilege behind it. --}}
                        <pre class="bg-body-tertiary p-2 rounded small text-wrap text-break mb-0"><code>{{ $defang->defangUrlsIn($submission->content_redacted) }}</code></pre>
                    </dd>

                    @if ($feedback->comment)
                        <dt class="col-sm-3 mt-2">{{ __('Their comment') }}</dt>
                        <dd class="col-sm-9 mt-2">
                            <p class="mb-0 fst-italic">{{ $feedback->comment }}</p>
                        </dd>
                    @endif
                </dl>

                <div class="d-flex flex-wrap gap-2 align-items-end">
                    <form method="POST" action="{{ route('admin.moderation.approve', $feedback) }}"
                          class="d-flex flex-wrap gap-2 align-items-end">
                        @csrf
                        <div>
                            <label for="label-{{ $feedback->id }}" class="form-label small mb-1">
                                {{ __('Correct label') }}
                            </label>
                            {{-- No default selection: approving without deciding
                                 must be impossible. --}}
                            <select id="label-{{ $feedback->id }}" name="label" class="form-select form-select-sm" required>
                                <option value="" selected disabled>{{ __('Choose…') }}</option>
                                <option value="scam">{{ __('Scam') }}</option>
                                <option value="legitimate">{{ __('Legitimate') }}</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-success btn-sm">
                            {{ __('Approve and add to retraining set') }}
                        </button>
                    </form>

                    <form method="POST" action="{{ route('admin.moderation.reject', $feedback) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            {{ __('Reject') }}
                        </button>
                    </form>
                </div>

            </div>
        </div>
    @empty
        <div class="card">
            <div class="card-body text-center py-5">
                <p class="text-body-secondary mb-0">{{ __('Nothing waiting for review.') }}</p>
            </div>
        </div>
    @endforelse

    {{ $pending->links() }}
</x-app-layout>

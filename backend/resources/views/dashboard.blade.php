<x-app-layout>
    <x-slot name="header">
        <h1 class="h4 mb-0">{{ __('Admin dashboard') }}</h1>
    </x-slot>

    <div class="card">
        <div class="card-body">
            <p class="mb-2 fw-semibold">Placeholder — not implemented.</p>
            <p class="mb-0 text-body-secondary">
                The real admin area is Phase 8: moderation queue, submission volume over time,
                verdict distribution, model performance read from <code>metrics.json</code>, and
                the threat-intel cache hit rate. This page exists only so that Phase 0 can prove
                the authenticated route resolves.
            </p>
        </div>
    </div>
</x-app-layout>

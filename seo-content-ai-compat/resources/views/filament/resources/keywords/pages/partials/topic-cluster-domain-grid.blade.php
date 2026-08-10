@php
    /** @var list<array<string, mixed>> $domainCluster */
@endphp

@if ($domainCluster === [])
    <div class="rounded-xl border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.keyword.cluster_domain_empty') }}
        </p>
    </div>
@else
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-2">
        @foreach ($domainCluster as $card)
            @include('seo-content-ai::filament.resources.keywords.pages.partials.topic-cluster-domain-card', [
                'card' => $card,
                'selectedKeywordId' => $selectedKeywordId,
            ])
        @endforeach
    </div>
@endif

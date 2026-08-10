@php
    $cssPath = base_path('addons/seo/resources/css/topic-cluster.css');
    $workspaceCssPath = base_path('addons/seo/resources/css/keyword-workspace.css');
    $pillars = $this->getPillarList();
    $selectedPillar = $this->getSelectedPillarSummary();
    $domainCluster = $this->getDomainCluster();
    $canMutate = \Omnichannel\Addons\Seo\Support\SeoAccessControl::canMutateInSeoPanel();
@endphp

<x-filament-panels::page class="topic-cluster-page">
    @if (is_readable($cssPath))
        <style>{!! file_get_contents($cssPath) !!}</style>
    @endif
    @if (is_readable($workspaceCssPath))
        <style>{!! file_get_contents($workspaceCssPath) !!}</style>
    @endif

    <div class="keyword-workspace-shell max-w-full space-y-5">
    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
        'activeKey' => $this->getActiveKeywordWorkspaceKey(),
        'navItems' => $this->getKeywordWorkspaceNavItems(),
    ])

    <div class="topic-cluster-layout mt-4 space-y-4">
        @include('seo-content-ai::filament.resources.keywords.pages.partials.topic-cluster-pillar-list', [
            'pillars' => $pillars,
            'selectedKeywordId' => $selectedKeywordId,
            'canMutate' => $canMutate,
        ])

        @if ($selectedPillar !== null && ! $clusterModalOpen)
            <section class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900/40">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ __('seo-content-ai::filament.keyword.cluster_domain_card_hint') }}
                        </h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.keyword.cluster_builder_pillar_hint', ['phrase' => $selectedPillar['phrase']]) }}
                        </p>
                    </div>
                    <span class="topic-cluster-pillar-badge">
                        {{ __('seo-content-ai::filament.keyword.cluster_is_pillar') }}: {{ $selectedPillar['phrase'] }}
                    </span>
                </div>

                @if ($domainCluster === [])
                    <div class="rounded-lg border border-dashed border-gray-300 px-6 py-10 text-center dark:border-white/15">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('seo-content-ai::filament.keyword.cluster_domain_empty') }}
                        </p>
                    </div>
                @else
                    <div class="grid gap-4 xl:grid-cols-2">
                        @foreach ($domainCluster as $domainCard)
                            @include('seo-content-ai::filament.resources.keywords.pages.partials.topic-cluster-domain-card', [
                                'domainCard' => $domainCard,
                                'canMutate' => $canMutate,
                                'selectedKeywordId' => $selectedKeywordId,
                            ])
                        @endforeach
                    </div>
                @endif
            </section>
        @elseif ($selectedPillar === null)
            <div class="rounded-xl border border-dashed border-gray-300 px-6 py-12 text-center dark:border-white/15">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.keyword.cluster_panel_empty') }}
                </p>
            </div>
        @endif
    </div>

    @include('seo-content-ai::filament.resources.keywords.pages.partials.topic-cluster-builder-modal', [
        'selectedPillar' => $selectedPillar,
        'canMutate' => $canMutate,
    ])
    </div>
</x-filament-panels::page>

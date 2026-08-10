@php
    $cssPath = base_path('addons/seo/resources/css/keyword-workspace.css');
@endphp

<x-filament-panels::page class="keyword-workspace-page">
    @if (is_readable($cssPath))
        <style>{!! file_get_contents($cssPath) !!}</style>
    @endif

    <div class="keyword-workspace-shell max-w-full space-y-5">
    @include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-workspace-nav', [
        'activeKey' => $this->getActiveKeywordWorkspaceKey(),
        'navItems' => $this->getKeywordWorkspaceNavItems(),
    ])

    <div class="rounded-xl border border-dashed border-gray-300 px-8 py-16 text-center dark:border-white/15">
        <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 dark:bg-white/10">
            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="h-7 w-7 text-gray-400" />
        </div>
        <h2 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">
            {{ $this->getTitle() }}
        </h2>
        <p class="mx-auto mt-2 max-w-lg text-sm text-gray-500 dark:text-gray-400">
            {{ $this->getPlaceholderDescription() }}
        </p>
    </div>
    </div>
</x-filament-panels::page>

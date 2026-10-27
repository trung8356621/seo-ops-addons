@php
    /** @var \Omnichannel\Addons\ContentProjects\Models\SeoProject $project */
    $activeMode = $activeMode ?? 'progress';
    $canAccessRun = \Omnichannel\Addons\Seo\Support\SeoAccessControl::canAccessContentProjectRun($project)
        && ! \Omnichannel\Addons\Seo\Support\SeoAccessControl::isContentManager();
    $progressUrl = \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getProgressUrl($project);
    $detailsUrl = \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getDetailsUrl($project);
@endphp
@if ($canAccessRun)
<div class="mb-4 flex justify-end">
    <div
        class="inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-white/10 dark:bg-gray-900"
        role="tablist"
        aria-label="{{ __('seo-content-ai::filament.projects.progress_mode_tabs') }}"
    >
        <a
            href="{{ $progressUrl }}"
            role="tab"
            aria-selected="{{ $activeMode === 'progress' ? 'true' : 'false' }}"
            @class([
                'rounded-md px-3 py-1.5 text-sm font-medium transition',
                'bg-primary-600 text-white shadow-sm' => $activeMode === 'progress',
                'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $activeMode !== 'progress',
            ])
        >
            {{ __('seo-content-ai::filament.projects.progress_tab_progress') }}
        </a>
        <a
            href="{{ $detailsUrl }}"
            role="tab"
            aria-selected="{{ $activeMode === 'details' ? 'true' : 'false' }}"
            @class([
                'rounded-md px-3 py-1.5 text-sm font-medium transition',
                'bg-primary-600 text-white shadow-sm' => $activeMode === 'details',
                'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5' => $activeMode !== 'details',
            ])
        >
            {{ __('seo-content-ai::filament.projects.progress_tab_details') }}
        </a>
    </div>
</div>
@endif

@php
    /** @var \Omnichannel\Addons\SearchFoundation\Models\Keyword $record */
    $record = $getRecord();
    $linkCount = (int) ($record->site_links_count ?? $record->links?->count() ?? 0);
    $domainCount = $record->relationLoaded('links')
        ? $record->links->pluck('site_id')->filter(static fn (mixed $id): bool => (int) $id > 0)->unique()->count()
        : 0;
@endphp

@if ($linkCount <= 0)
    <span class="text-xs text-gray-400 dark:text-gray-500">—</span>
@else
    <button
        type="button"
        class="js-keyword-destinations-open inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-300/60 transition hover:bg-slate-200/80 focus:outline-none focus:ring-2 focus:ring-primary-500/40 dark:bg-white/5 dark:text-slate-200 dark:ring-white/10 dark:hover:bg-white/10"
        data-keyword-id="{{ $record->getKey() }}"
        data-keyword-phrase="{{ $record->phrase }}"
        title="{{ __('seo-content-ai::filament.keyword.destinations_open_details') }}"
    >
        <x-filament::icon icon="heroicon-o-map" class="h-4 w-4 shrink-0 opacity-80" />
        <span>{{ __('seo-content-ai::filament.keyword.destinations_button_summary', [
            'domains' => max(1, $domainCount),
            'links' => $linkCount,
        ]) }}</span>
    </button>
@endif

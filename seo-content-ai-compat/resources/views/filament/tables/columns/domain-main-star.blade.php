@php
    use Omnichannel\Addons\Seo\Services\SeoMainDomainService;

    /** @var \App\Models\Site $record */
    $record = $getRecord();
    $isMain = app(SeoMainDomainService::class)->isMain($record);
@endphp

<div class="flex items-center">
    @if($isMain)
        <x-filament::icon
            icon="heroicon-s-star"
            class="h-5 w-5 text-warning-500"
            title="{{ __('seo-content-ai::filament.domain.main_domain_active') }}"
        />
    @else
        <button
            type="button"
            wire:click="mountTableAction('set_as_main', '{{ $record->getKey() }}')"
            wire:loading.attr="disabled"
            wire:target="mountTableAction('set_as_main', '{{ $record->getKey() }}')"
            class="rounded p-0.5 text-gray-400 transition hover:text-warning-500 focus:outline-none focus:ring-2 focus:ring-warning-500/40"
            title="{{ __('seo-content-ai::filament.domain.set_as_main') }}"
        >
            <x-filament::icon icon="heroicon-o-star" class="h-5 w-5" />
        </button>
    @endif
</div>

@php
    /** @var \Omnichannel\Addons\SearchFoundation\Models\Keyword|null $record */
    $record = $getRecord();
    $phrase = (string) ($record?->phrase ?? '');
@endphp

<button
    type="button"
    class="keyword-row-action keyword-row-action--copy"
    data-keyword-copy-phrase="{{ $phrase }}"
    title="{{ __('seo-content-ai::filament.keyword.quick_copy') }}"
    aria-label="{{ __('seo-content-ai::filament.keyword.quick_copy') }}"
>
    <x-filament::icon icon="heroicon-o-clipboard-document" class="h-5 w-5" />
</button>

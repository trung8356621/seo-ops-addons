@php
    use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;

    /** @var \Omnichannel\Addons\SearchFoundation\Models\Keyword $record */
    $record = $getRecord();
    $livewire = $getLivewire();
    $dnaMap = property_exists($livewire, 'dictionaryKeywordDnaMap') && is_array($livewire->dictionaryKeywordDnaMap)
        ? $livewire->dictionaryKeywordDnaMap
        : [];
    $siteId = (int) (KeywordResource::resolveKeywordSiteId($record) ?? 0) ?: null;
@endphp

@include('seo-content-ai::filament.resources.keywords.pages.partials.keyword-item', [
    'keyword' => $record,
    'context' => KeywordItemPresenter::CONTEXT_DICTIONARY,
    'siteId' => $siteId,
    'dnaValues' => $dnaMap[(int) $record->id] ?? null,
    'clusterKey' => '',
    'showCheckbox' => false,
])

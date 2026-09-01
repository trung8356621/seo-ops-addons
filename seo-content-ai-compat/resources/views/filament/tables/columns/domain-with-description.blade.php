@php
    use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;

    /** @var \App\Models\Site $record */
    $record = $getRecord();
    $summary = app(SiteDomainPromptContextService::class)->tableSummaryForSite($record);
    $description = (string) ($summary['short_description'] ?? '');
@endphp

<div class="seo-domain-table-cell min-w-0 py-1">
    <div class="seo-domain-table-cell__name truncate font-semibold text-gray-950 dark:text-white">
        {{ $record->domain }}
    </div>
    @if($description !== '')
        <div class="mt-0.5 max-w-xs truncate text-sm text-gray-500 dark:text-gray-400" title="{{ $description }}">
            {{ $description }}
        </div>
    @endif
</div>

@php
    use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;

    /** @var \App\Models\Site $record */
    $record = $getRecord();
    $summary = app(SiteDomainPromptContextService::class)->tableSummaryForSite($record);
    $description = (string) ($summary['short_description'] ?? '');
@endphp

<div class="min-w-0 py-1">
    <div class="truncate font-medium text-gray-950 dark:text-white" style="font-size: 1rem">
        {{ $record->domain }}
    </div>
    @if($description !== '')
        <div class="mt-0.5 max-w-xs whitespace-normal break-words text-sm text-gray-500 dark:text-gray-400">
            {{ $description }}
        </div>
    @endif
</div>

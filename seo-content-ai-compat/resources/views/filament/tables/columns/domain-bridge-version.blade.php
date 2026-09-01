@php
    use Omnichannel\Addons\SearchFoundation\Support\DomainListPresentation;

    /** @var \App\Models\Site $record */
    $record = $getRecord();
    $bridge = DomainListPresentation::bridgeVersion($record);
@endphp

<div class="min-w-0 py-0.5 text-sm leading-snug" @if($bridge['title']) title="{{ $bridge['title'] }}" @endif>
    <div class="font-medium text-gray-950 dark:text-white">{{ $bridge['line'] }}</div>
    @if(filled($bridge['detail']))
        <div class="text-xs text-gray-500 dark:text-gray-400">{{ $bridge['detail'] }}</div>
    @endif
</div>

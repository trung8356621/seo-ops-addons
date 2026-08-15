@php
    use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordTagResolver;

    $record = $getRecord();
    $tags = app(KeywordTagResolver::class)->displayTags($record);
@endphp

<div class="flex flex-wrap items-center gap-1">
    @forelse ($tags as $tag)
        <span class="{{ $tag['badge_class'] }}">{{ $tag['label'] }}</span>
    @empty
        <span class="text-[12px] text-gray-400">—</span>
    @endforelse
</div>

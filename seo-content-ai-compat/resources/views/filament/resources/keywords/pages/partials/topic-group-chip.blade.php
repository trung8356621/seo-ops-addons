@php
    $groupId = (int) ($row['keyword_group_id'] ?? 0);
    $groupName = trim((string) ($row['keyword_group_name'] ?? ''));
@endphp
@if ($groupId > 0 && $groupName !== '')
    <a
        href="{{ \Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource::getUrl('groups', ['group' => $groupId]) }}"
        class="topic-group-chip"
        @click.stop
    >{{ __('seo-content-ai::filament.keyword.keyword_group_chip', ['name' => $groupName]) }}</a>
@endif

@php
    /** @var \Omnichannel\Addons\SearchFoundation\Models\Keyword $record */
    $record = $getRecord();
    $isChild = $record->parent_id !== null && (int) $record->parent_id > 0;
    $childCount = (int) ($record->children_count ?? 0);
    $recordId = (int) $record->getKey();
    $expandedParentIds = is_array($this->expandedParentIds ?? null) ? $this->expandedParentIds : [];
    $isExpanded = in_array($recordId, $expandedParentIds, true);
    $showAccordion = ! $isChild && $childCount > 0 && ($this->parentId ?? null) === null;
@endphp

<div @class([
    'flex min-w-0 items-start gap-1.5',
    'border-l-2 border-primary-300 pl-3 dark:border-primary-500/50' => $isChild,
])>
    @if ($showAccordion)
        <button
            type="button"
            wire:click.stop="toggleParentExpand({{ $recordId }})"
            class="relative z-40 mt-0.5 inline-flex shrink-0 rounded-md p-0.5 text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus:ring-2 focus:ring-primary-500/40 dark:text-gray-400 dark:hover:bg-white/10 dark:hover:text-gray-200"
            title="{{ $isExpanded
                ? __('seo-content-ai::filament.keyword.collapse_children')
                : __('seo-content-ai::filament.keyword.expand_children') }}"
            aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
        >
            <x-filament::icon
                :icon="$isExpanded ? 'heroicon-m-chevron-down' : 'heroicon-m-chevron-right'"
                class="h-4 w-4 shrink-0"
            />
        </button>
    @else
        <span class="mt-0.5 inline-block w-5 shrink-0" aria-hidden="true"></span>
    @endif

    <div class="min-w-0 flex-1">
        <div class="break-words font-bold text-gray-950 dark:text-white">
            {{ $record->phrase }}
        </div>

        @if ($showAccordion)
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.keyword.child_count', ['count' => $childCount]) }}
            </div>
        @endif
    </div>
</div>

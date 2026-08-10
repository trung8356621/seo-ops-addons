@php
    /** @var list<array<string, mixed>> $nodes */
@endphp

<ul class="space-y-1">
    @foreach ($nodes as $node)
        @php
            $nodeId = (int) ($node['id'] ?? 0);
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $isSelected = ($selectedKeywordId ?? null) === $nodeId;
            $childSelected = collect($children)->contains(
                static fn (mixed $child): bool => is_array($child) && (int) ($child['id'] ?? 0) === (int) ($selectedKeywordId ?? 0),
            );
            $defaultOpen = $isSelected || $childSelected;
        @endphp

        <li
            wire:key="cluster-tree-root-{{ $nodeId }}"
            x-data="{ open: {{ $defaultOpen ? 'true' : 'false' }} }"
            @class([
                'rounded-lg border',
                'border-rose-100 bg-rose-50/40 dark:border-rose-500/20 dark:bg-rose-500/5' => $isSelected,
                'border-transparent' => ! $isSelected,
            ])
        >
            <div class="flex items-start gap-1">
                @if ($children !== [])
                    <button
                        type="button"
                        @click="open = !open"
                        class="mt-2 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md text-gray-400 hover:bg-white hover:text-gray-700 dark:hover:bg-white/10"
                        :aria-expanded="open.toString()"
                    >
                        <x-filament::icon
                            icon="heroicon-m-chevron-right"
                            class="h-4 w-4 transition-transform duration-200"
                            x-bind:class="open ? 'rotate-90' : ''"
                        />
                    </button>
                @else
                    <span class="mt-2 inline-block h-6 w-6 shrink-0"></span>
                @endif

                <button
                    type="button"
                    wire:click="selectKeyword({{ $nodeId }})"
                    @class([
                        'topic-cluster-tree-node flex-1 text-left',
                        'is-selected' => $isSelected,
                    ])
                >
                    @include('seo-content-ai::filament.resources.keywords.pages.partials.topic-cluster-tree-node', [
                        'node' => $node,
                    ])
                </button>
            </div>

            @if ($children !== [])
                <div
                    x-show="open"
                    x-collapse
                    x-cloak
                    class="ml-7 border-l border-gray-200 pl-3 dark:border-white/10"
                >
                    <ul class="space-y-1 py-1">
                        @foreach ($children as $child)
                            @php
                                $childId = (int) ($child['id'] ?? 0);
                                $childSelected = ($selectedKeywordId ?? null) === $childId;
                            @endphp
                            <li wire:key="cluster-tree-child-{{ $childId }}">
                                <button
                                    type="button"
                                    wire:click="selectKeyword({{ $childId }})"
                                    @class([
                                        'topic-cluster-tree-node w-full text-left',
                                        'is-selected' => $childSelected,
                                    ])
                                >
                                    @include('seo-content-ai::filament.resources.keywords.pages.partials.topic-cluster-tree-node', [
                                        'node' => $child,
                                        'isChild' => true,
                                    ])
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </li>
    @endforeach
</ul>

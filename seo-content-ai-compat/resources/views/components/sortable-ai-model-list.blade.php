@php
    $canReorder = $canReorder ?? false;
    $area = $area ?? 'text';
    $models = $models ?? [];
@endphp
<div
    class="seo-ai-sort"
    wire:key="sort-{{ $area }}"
    x-data="seoAiSortableList()"
    data-area="{{ $area }}"
    data-can-reorder="{{ $canReorder ? '1' : '0' }}"
>
    @if (! $canReorder && $models !== [])
        <p class="seo-ai-muted">{{ __('seo-content-ai::filament.ai_center.clear_filters_to_reorder') }}</p>
    @endif
    <div class="seo-ai-sort__list" x-ref="list" @dragover.prevent="over($event)" @drop.prevent="end($event)">
        @forelse ($models as $index => $row)
            @php($targetId = (int) ($row['ids'][0] ?? 0))
            <div
                class="seo-ai-sort-item"
                wire:key="sort-item-{{ $row['identity'] ?? $targetId }}"
                data-target-id="{{ $targetId }}"
            >
                @if ($canReorder)
                    <button
                        type="button"
                        class="seo-ai-grip"
                        draggable="true"
                        @dragstart="start($event)"
                        @dragend="end($event)"
                        aria-label="{{ __('seo-content-ai::filament.ai_center.reorder') }}"
                    >☰</button>
                    <div class="seo-ai-sort-item__move">
                        <button type="button" class="seo-ai-sort-item__nudge" @click="nudge({{ $targetId }}, -1)" aria-label="Up">▲</button>
                        <button type="button" class="seo-ai-sort-item__nudge" @click="nudge({{ $targetId }}, 1)" aria-label="Down">▼</button>
                    </div>
                @else
                    <span class="seo-ai-grip is-disabled" aria-hidden="true">☰</span>
                @endif
                <span class="seo-ai-sort-item__n">{{ $index + 1 }}</span>
                <div class="seo-ai-sort-item__body">
                    <div class="seo-ai-sort-item__name">
                        @if (! empty($row['short_code']))
                            <span class="seo-ai-code seo-ai-code--{{ $row['badge_variant'] ?? 'badge-1' }}">{{ $row['short_code'] }}</span>
                        @endif
                        <span>{{ $row['model_name'] ?? $row['label'] }}</span>
                    </div>
                </div>
                @if (! empty($row['is_free']))
                    <span class="seo-ai-status seo-ai-status--free">FREE</span>
                @endif
                <span @class(['seo-ai-status', ($row['status'] ?? '') === 'active' ? 'seo-ai-status--active' : 'seo-ai-status--inactive'])>
                    {{ ($row['status'] ?? '') === 'active' ? __('seo-content-ai::filament.ai_center.status_active') : __('seo-content-ai::filament.ai_center.status_inactive') }}
                </span>
                <button
                    type="button"
                    class="seo-ai-switch is-on"
                    wire:click="toggleHidden(@js($row['ids']), true)"
                    aria-pressed="true"
                ></button>
            </div>
        @empty
            <p class="seo-ai-muted">
                {{ __('seo-content-ai::filament.ai_center.no_enabled_models') }}
                <button type="button" class="seo-ai-link" wire:click="openModelPicker">
                    {{ __('seo-content-ai::filament.ai_center.add_area_models.'.$area) }}
                </button>
            </p>
        @endforelse
    </div>
</div>

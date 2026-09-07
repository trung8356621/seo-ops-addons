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
                        @php($routeBadges = [])
                        @foreach (($row['routes'] ?? []) as $route)
                            @if (is_array($route) && filled($route['short_code'] ?? null))
                                @php($routeBadges[] = $route)
                            @endif
                        @endforeach
                        @if ($routeBadges !== [])
                            @foreach ($routeBadges as $routeBadge)
                                <span class="seo-ai-code seo-ai-code--{{ $routeBadge['badge_variant'] ?? 'badge-1' }}">{{ $routeBadge['short_code'] }}</span>
                            @endforeach
                        @elseif (! empty($row['short_code']))
                            <span class="seo-ai-code seo-ai-code--{{ $row['badge_variant'] ?? 'badge-1' }}">{{ $row['short_code'] }}</span>
                        @endif
                        <span>{{ $row['model_name'] ?? $row['label'] }}</span>
                        @if (! empty($row['is_free_pool']))
                            <span class="seo-ai-muted" style="display:block;font-size:0.75rem;margin-top:0.15rem;">
                                {{ $row['subtitle'] ?? ('Auto managed · '.((int) ($row['free_pool_count'] ?? $row['member_count'] ?? 0)).' models') }}
                            </span>
                        @elseif (count($routeBadges) >= 2)
                            <span class="seo-ai-muted" style="margin-left:0.35rem;font-size:0.75rem;">{{ count($routeBadges) }} routes</span>
                        @endif
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

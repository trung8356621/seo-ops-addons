@php
    $currentPage = (int) ($pagination['current_page'] ?? 1);
    $lastPage = (int) ($pagination['last_page'] ?? 1);
    $from = (int) ($pagination['from'] ?? 0);
    $to = (int) ($pagination['to'] ?? 0);
    $total = (int) ($pagination['total'] ?? $totalFiltered ?? 0);
    $perPage = (int) ($pagination['per_page'] ?? 25);
    $pageNumbers = $pagination['page_numbers'] ?? [];
@endphp

<footer class="performance-hub-pagination">
    <div class="performance-hub-pagination__meta">
        <span>
            {{ __('seo-content-ai::filament.performance_hub.pagination_showing', [
                'from' => $from,
                'to' => $to,
                'total' => $total,
            ]) }}
        </span>
        <label class="performance-hub-pagination__size">
            <span>{{ __('seo-content-ai::filament.performance_hub.pagination_per_page') }}</span>
            <x-select wire:model.live="gscPerPage" class="performance-hub-select performance-hub-select--compact">
                @foreach ([10, 25, 50, 100] as $size)
                    <option value="{{ $size }}">{{ $size }}</option>
                @endforeach
            </x-select>
        </label>
    </div>

    @if ($lastPage > 1)
        <nav class="performance-hub-pagination__nav" aria-label="{{ __('seo-content-ai::filament.performance_hub.pagination_label') }}">
            <button
                type="button"
                wire:click="gotoGscPage({{ max(1, $currentPage - 1) }})"
                wire:loading.attr="disabled"
                wire:target="gotoGscPage"
                @disabled($currentPage <= 1)
                class="performance-hub-pagination__btn"
            >
                {{ __('seo-content-ai::filament.performance_hub.pagination_prev') }}
            </button>

            @foreach ($pageNumbers as $pageNumber)
                @if ($pageNumber === '...')
                    <span class="performance-hub-pagination__ellipsis">…</span>
                @else
                    <button
                        type="button"
                        wire:click="gotoGscPage({{ (int) $pageNumber }})"
                        wire:loading.attr="disabled"
                        wire:target="gotoGscPage"
                        @class([
                            'performance-hub-pagination__btn',
                            'is-active' => (int) $pageNumber === $currentPage,
                        ])
                        aria-current="{{ (int) $pageNumber === $currentPage ? 'page' : 'false' }}"
                    >
                        {{ (int) $pageNumber }}
                    </button>
                @endif
            @endforeach

            <button
                type="button"
                wire:click="gotoGscPage({{ min($lastPage, $currentPage + 1) }})"
                wire:loading.attr="disabled"
                wire:target="gotoGscPage"
                @disabled($currentPage >= $lastPage)
                class="performance-hub-pagination__btn"
            >
                {{ __('seo-content-ai::filament.performance_hub.pagination_next') }}
            </button>
        </nav>
    @endif
</footer>

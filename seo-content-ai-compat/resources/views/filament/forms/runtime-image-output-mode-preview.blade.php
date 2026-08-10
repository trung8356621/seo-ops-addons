@php
    /** @var array{mode_label: string, lines: list<string>} $summary */
    /** @var string $block */
    /** @var array{split_enabled: bool, split_grid_size: int} $config */
    $copyId = 'runtime-image-output-block-'.md5(($config['split_enabled'] ? '1' : '0').'-'.$config['split_grid_size'].'-'.strlen($block));
@endphp

<div
    class="seo-runtime-output-mode"
    wire:key="runtime-output-{{ $config['split_enabled'] ? 'on' : 'off' }}-{{ $config['split_grid_size'] }}"
>
    <div class="seo-runtime-output-mode__card rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/40 p-3 mb-3">
        <div class="text-sm font-medium text-gray-900 dark:text-gray-100 mb-2">
            {{ __('seo-content-ai::filament.prompt.post_processing.runtime_card_title') }}
        </div>
        <ul class="seo-runtime-output-mode__lines text-sm text-gray-700 dark:text-gray-300 space-y-1 list-none m-0 p-0">
            @foreach ($summary['lines'] as $line)
                <li>- {{ $line }}</li>
            @endforeach
        </ul>
    </div>

    <div
        x-data="{ open: false, copied: false }"
        class="seo-runtime-output-mode__accordion"
    >
        <button
            type="button"
            class="fi-link text-sm font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400"
            x-on:click="open = !open"
        >
            <span x-text="open ? @js(__('seo-content-ai::filament.prompt.post_processing.runtime_hide_block')) : @js(__('seo-content-ai::filament.prompt.post_processing.runtime_view_block'))"></span>
        </button>

        <div x-show="open" x-cloak class="mt-3">
            <div class="flex items-center justify-between gap-2 mb-2">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.prompt.post_processing.runtime_block_label') }}
                </span>
                <button
                    type="button"
                    class="text-xs font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400"
                    x-on:click="
                        navigator.clipboard.writeText($refs.blockText.innerText).then(() => {
                            copied = true;
                            setTimeout(() => copied = false, 1500);
                        })
                    "
                >
                    <span x-show="!copied">{{ __('seo-content-ai::filament.prompt.post_processing.runtime_copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('seo-content-ai::filament.prompt.post_processing.runtime_copied') }}</span>
                </button>
            </div>
            <pre
                id="{{ $copyId }}"
                x-ref="blockText"
                class="seo-runtime-output-mode__pre whitespace-pre-wrap break-words font-mono text-xs leading-relaxed rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-950 p-3 max-h-80 overflow-auto text-gray-800 dark:text-gray-200"
            >{{ $block }}</pre>
        </div>
    </div>
</div>

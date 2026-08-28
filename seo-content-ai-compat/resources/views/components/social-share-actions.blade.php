@props([
    'url' => '',
    'title' => '',
    'compact' => true,
])

@php
    $title = is_string($title) ? $title : '';
    $url = trim(is_string($url) ? $url : '');
    $compact = (bool) $compact;
    $presented = app(\Omnichannel\Addons\Social\Support\SocialShareActionsPresenter::class)
        ->present($url, $title, $compact);
    $actions = $presented['actions'] ?? [];
    $canShare = (bool) ($presented['can_share'] ?? false);
    $btnClass = 'fi-social-share-btn inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-gray-200 bg-white text-gray-600 transition hover:border-gray-300 hover:bg-gray-50 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500/40 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-gray-600 dark:hover:bg-gray-800 dark:hover:text-white';
@endphp

<x-seo-content-ai::safe-clipboard />

<div {{ $attributes->class(['fi-social-share-actions inline-flex flex-wrap items-center gap-1.5']) }}>
    @if (! $canShare)
        <span class="text-[11px] text-gray-400 dark:text-gray-500">—</span>
    @else
        @foreach ($actions as $action)
            @php
                $key = (string) ($action['key'] ?? '');
                $tooltip = (string) ($action['tooltip'] ?? '');
                $mode = (string) ($action['mode'] ?? 'share_intent');
                $href = $action['href'] ?? null;
                $copyUrl = $action['copy_url'] ?? null;
            @endphp
            @if ($mode === 'copy_link')
                <button
                    type="button"
                    class="{{ $btnClass }}"
                    title="{{ $tooltip !== '' ? $tooltip : 'Copy link' }}"
                    aria-label="{{ $tooltip !== '' ? $tooltip : 'Copy link' }}"
                    x-data="{ copied: false }"
                    x-on:click.stop.prevent="
                        const ok = await window.omiCopyText(@js($copyUrl));
                        if (ok) {
                            copied = true;
                            setTimeout(() => copied = false, 1500);
                        } else if (window.FilamentNotification) {
                            new window.FilamentNotification()
                                .title(@js(__('seo-content-ai::filament.keyword.quick_copy_failed')))
                                .body(@js(__('seo-content-ai::filament.keyword.quick_copy_failed_body')))
                                .warning()
                                .send();
                        }
                    "
                >
                    <span x-show="!copied" class="inline-flex">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.172 13.828a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                    </span>
                    <span x-cloak x-show="copied" class="text-success-600 dark:text-success-400">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                    </span>
                </button>
            @elseif (is_string($href) && $href !== '')
                <a
                    href="{{ $href }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="{{ $btnClass }}"
                    title="{{ $tooltip }}"
                    aria-label="{{ $tooltip }}"
                >
                    @if ($key === 'facebook')
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14 8h3V5h-3c-2.2 0-4 1.8-4 4v2H8v3h2v7h3v-7h2.6l.4-3H13V9c0-.6.4-1 1-1z"/></svg>
                    @elseif ($key === 'linkedin')
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M6.5 8.5A1.75 1.75 0 116.5 5a1.75 1.75 0 010 3.5zM5 10h3v9H5v-9zm5 0h2.9v1.2h.1c.4-.8 1.4-1.4 2.9-1.4 3.1 0 3.7 2 3.7 4.7V19h-3v-4.2c0-1 0-2.3-1.4-2.3s-1.6 1.1-1.6 2.2V19h-3v-9z"/></svg>
                    @elseif ($key === 'x')
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.7 3H20l-6.3 7.2L21 21h-5.5l-4.3-5.6L6 21H3.7l6.8-7.8L3 3h5.6l3.9 5.1L17.7 3zm-1 16.2h1.5L7.4 4.7H5.8l10.9 14.5z"/></svg>
                    @else
                        <span class="text-[10px] font-semibold uppercase">{{ $action['compact_label'] ?? '' }}</span>
                    @endif
                </a>
            @endif
        @endforeach
    @endif
</div>

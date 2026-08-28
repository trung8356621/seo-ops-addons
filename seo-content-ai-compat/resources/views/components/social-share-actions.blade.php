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
    $btnClass = 'inline-flex min-w-[1.5rem] items-center justify-center rounded-md px-1.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50 dark:text-gray-200 dark:ring-gray-700 dark:hover:bg-gray-800';
@endphp

<div {{ $attributes->class(['fi-social-share-actions inline-flex flex-wrap items-center gap-1']) }}>
    @if (! $canShare)
        <span class="text-[11px] text-gray-400 dark:text-gray-500">—</span>
    @else
        @foreach ($actions as $action)
            @php
                $label = $compact
                    ? (string) ($action['compact_label'] ?? '')
                    : (string) ($action['platform_label'] ?? '');
                $tooltip = (string) ($action['tooltip'] ?? '');
                $mode = (string) ($action['mode'] ?? 'share_intent');
                $href = $action['href'] ?? null;
                $copyUrl = $action['copy_url'] ?? null;
            @endphp
            @if ($mode === 'copy_link')
                <button
                    type="button"
                    class="{{ $btnClass }}"
                    title="{{ $tooltip }}"
                    x-data="{ copied: false }"
                    x-on:click.stop="
                        navigator.clipboard.writeText(@js($copyUrl)).then(() => {
                            copied = true;
                            setTimeout(() => copied = false, 1500);
                        }).catch(() => {})
                    "
                >
                    <span x-text="copied ? '✓' : @js($label)"></span>
                </button>
            @elseif (is_string($href) && $href !== '')
                <a
                    href="{{ $href }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="{{ $btnClass }}"
                    title="{{ $tooltip }}"
                >{{ $label }}</a>
            @endif
        @endforeach
    @endif
</div>

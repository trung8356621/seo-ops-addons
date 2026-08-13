@php
    /** @var array<string, mixed> $item */
    $mapId = (int) ($item['id'] ?? 0);
@endphp

<article
    wire:key="anchor-audit-card-{{ $mapId }}"
    class="anchor-audit-card overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900/50"
>
    <header class="flex flex-wrap items-center gap-3 border-b border-gray-100 px-4 py-3 dark:border-white/10">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold uppercase text-slate-600 dark:bg-white/10 dark:text-slate-200">
            {{ $item['domain_initials'] ?? '?' }}
        </span>

        <div class="min-w-0 flex-1">
            @if (! empty($item['domain_url']))
                <a
                    href="{{ $item['domain_url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary-600 hover:text-primary-700 hover:underline dark:text-primary-400 dark:hover:text-primary-300"
                >
                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-4 w-4 shrink-0" />
                    <span class="truncate">{{ $item['domain_url'] }}</span>
                </a>
            @else
                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $item['domain'] ?? '—' }}</p>
            @endif

            <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                {{ $item['source_title'] ?? '—' }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($item['can_assign_content_project'] ?? false)
                <x-filament::icon-button
                    type="button"
                    icon="heroicon-o-folder-plus"
                    size="sm"
                    color="warning"
                    wire:click="mountAction('assignToContentProject', { mapId: {{ $mapId }} })"
                    :tooltip="\Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract::label()"
                    :label="\Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract::label()"
                    data-assign-content-project-trigger
                />
            @endif

            @if (! empty($item['source_edit_url']))
                <a
                    href="{{ $item['source_edit_url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200"
                >
                    <x-filament::icon icon="heroicon-m-pencil-square" class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.workspace_edit_article') }}
                </a>
            @endif

            @if ($item['can_ignore'] ?? false)
                <button
                    type="button"
                    wire:click="markLinkMapAsIgnored({{ $mapId }})"
                    wire:loading.attr="disabled"
                    wire:target="markLinkMapAsIgnored"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-white/15 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10"
                >
                    <span wire:loading wire:target="markLinkMapAsIgnored">
                        <x-filament::loading-indicator class="h-4 w-4" />
                    </span>
                    <span wire:loading.remove wire:target="markLinkMapAsIgnored">
                        <x-filament::icon icon="heroicon-m-eye-slash" class="h-4 w-4" />
                    </span>
                    {{ __('seo-content-ai::filament.keyword.workspace_mark_ignored') }}
                </button>
            @endif
        </div>
    </header>

    <div class="px-4 py-4">
        <div class="anchor-audit-context-box bg-slate-50/70 px-4 py-3">
            <p class="text-sm leading-relaxed text-gray-800 dark:text-gray-100">
                @if (($item['context_before'] ?? '') !== '')
                    <span class="text-sm italic text-slate-400">{{ $item['context_before'] }}</span>
                @endif

                <span class="text-slate-400 text-sm italic">
                    {{ $item['anchor_text'] ?? '' }}
                </span>

                @if (($item['context_after'] ?? '') !== '')
                    <span class="text-sm italic text-slate-400">{{ $item['context_after'] }}</span>
                @endif
            </p>
        </div>
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 px-4 py-3 dark:border-white/10">
        <div class="flex min-w-0 flex-1 items-center gap-2 text-gray-500 dark:text-gray-400">
            <x-filament::icon icon="heroicon-m-arrow-right" class="h-4 w-4 shrink-0" />
            <span class="truncate font-mono text-xs">{{ $item['target_label'] ?? '—' }}</span>
        </div>
    </footer>
</article>

@php
    /** @var array<string, mixed> $row */
    $network = is_array($row['network'] ?? null) ? $row['network'] : [];
    $targetTone = (string) ($row['target_tone'] ?? 'internal');
@endphp

<tr wire:key="link-triage-row-{{ (int) ($row['id'] ?? 0) }}" class="link-triage-row">
    <td class="link-triage-td align-top">
        <p class="link-triage-anchor">{{ $row['anchor_text'] ?? '—' }}</p>

        @if (! empty($row['source_edit_url']))
            <a
                href="{{ $row['source_edit_url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                class="link-triage-source-link group mt-1.5 inline-flex max-w-full items-center gap-1.5 text-sm font-semibold text-primary-600 hover:text-primary-700 hover:underline dark:text-primary-400 dark:hover:text-primary-300"
            >
                <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-4 w-4 shrink-0" />
                <span class="truncate">{{ $row['source_path_label'] ?? '—' }}</span>
            </a>
        @else
            <p class="mt-1.5 truncate text-sm text-gray-500 dark:text-gray-400">
                {{ $row['source_path_label'] ?? '—' }}
            </p>
        @endif
    </td>

    <td class="link-triage-td align-top">
        <div class="flex flex-col items-start gap-2">
            <div class="flex flex-wrap gap-1.5">
                @if (in_array(($network['tone'] ?? ''), ['broken', 'restricted'], true))
                    <span class="{{ $network['badge_class'] ?? '' }}">{{ $network['label'] }}</span>
                @endif

                @if ($row['weak_context'] ?? false)
                    <span class="bg-purple-50 text-purple-700 dark:bg-purple-500/10 dark:text-purple-300 border border-purple-200 dark:border-purple-800/30 px-2 py-0.5 rounded text-xs font-semibold">
                        {{ __('seo-content-ai::filament.keyword.link_triage_badge_weak_context') }}
                    </span>
                @endif
            </div>

            @if ($row['can_mark_link_ok'] ?? false)
                <button
                    type="button"
                    wire:click="markLinkMapAsActive({{ (int) ($row['id'] ?? 0) }})"
                    wire:loading.attr="disabled"
                    wire:target="markLinkMapAsActive"
                    class="link-triage-action-btn link-triage-action-btn--link-ok"
                    title="{{ __('seo-content-ai::filament.keyword.link_triage_mark_active_hint') }}"
                >
                    <span wire:loading.remove wire:target="markLinkMapAsActive">
                        <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4" />
                    </span>
                    <span wire:loading wire:target="markLinkMapAsActive">
                        <x-filament::loading-indicator class="h-4 w-4" />
                    </span>
                    {{ __('seo-content-ai::filament.keyword.link_triage_mark_active') }}
                </button>
            @endif
        </div>
    </td>

    <td class="link-triage-td align-top">
        @if (! empty($row['target_url']))
            <a
                href="{{ $row['target_url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                title="{{ $row['target_url'] }}"
                @class([
                    'block truncate max-w-xs text-xs font-mono underline-offset-2 hover:underline',
                    'text-indigo-600 hover:text-indigo-500 dark:text-indigo-300' => $targetTone === 'internal',
                    'text-amber-700 hover:text-amber-600 dark:text-amber-300' => $targetTone === 'external',
                    'text-emerald-700 hover:text-emerald-600 dark:text-emerald-300' => $targetTone === 'wiki_trust',
                ])
            >
                {{ $row['target_label'] ?? $row['target_url'] }}
            </a>
        @else
            <span class="text-xs text-gray-400">—</span>
        @endif
    </td>

    <td class="link-triage-td align-top">
        <div class="flex flex-wrap items-center gap-2">
            @if (! empty($row['source_edit_url']))
                <a
                    href="{{ $row['source_edit_url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="link-triage-action-btn link-triage-action-btn--edit"
                >
                    <x-filament::icon icon="heroicon-m-pencil-square" class="h-4 w-4" />
                    {{ __('seo-content-ai::filament.keyword.workspace_edit_article') }}
                </a>
            @endif

            @if ($row['can_assign_content_project'] ?? false)
                <x-filament::icon-button
                    type="button"
                    icon="heroicon-o-folder-plus"
                    size="sm"
                    color="warning"
                    wire:click="mountAction('assignToContentProject', { mapId: {{ (int) ($row['id'] ?? 0) }} })"
                    wire:loading.attr="disabled"
                    :tooltip="\Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract::label()"
                    :label="\Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract::label()"
                    data-assign-content-project-trigger
                />
            @endif
        </div>
    </td>
</tr>

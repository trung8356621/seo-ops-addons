@php
    /** @var \Omnichannel\Addons\SearchFoundation\Models\Keyword $record */
    use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;

    $groups = KeywordResource::resolveLinkDestinationGroups($record);

    $mainHeading = __('seo-content-ai::filament.keyword.destinations_dropdown_main_heading');
    $internalHeading = __('seo-content-ai::filament.keyword.destinations_dropdown_internal_heading');
    $sourceCaption = __('seo-content-ai::filament.keyword.destinations_action_view_source');
    $targetCaption = __('seo-content-ai::filament.keyword.destinations_action_view_target');
@endphp

@if ($groups === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">—</p>
@else
    <div class="space-y-4">
        @foreach ($groups as $group)
            @php
                $domain = (string) ($group['domain'] ?? '');
                $mainLinks = is_array($group['main_links'] ?? null) ? $group['main_links'] : [];
                $internalLinks = is_array($group['internal_links'] ?? null) ? $group['internal_links'] : [];
                $badge = is_array($group['badge'] ?? null) ? $group['badge'] : [];
                $icon = (string) ($badge['icon'] ?? 'heroicon-m-bookmark-square');
            @endphp

            <div class="space-y-3 rounded-xl border border-slate-200/60 bg-slate-50/60 p-4 shadow-inner dark:border-white/10 dark:bg-white/[0.03]">
                <div class="flex items-center gap-2 border-b border-slate-200/50 pb-2 dark:border-white/10">
                    <x-filament::icon :icon="$icon" class="h-4 w-4 shrink-0 text-slate-500 dark:text-slate-400" />
                    <span class="text-sm font-semibold tracking-tight text-slate-800 dark:text-slate-100">{{ $domain }}</span>
                </div>

                @if ($mainLinks !== [])
                    <div class="space-y-2">
                        @foreach ($mainLinks as $mainLink)
                            @php
                                $mainHref = (string) ($mainLink['edit_url'] ?? $mainLink['url'] ?? '');
                            @endphp
                            @if ($mainHref !== '')
                                <div class="flex w-full items-center justify-between rounded-lg border border-slate-200/80 border-l-4 border-l-emerald-500 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/50 dark:border-l-emerald-400">
                                    <span class="rounded px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-200">
                                        🎯 {{ $mainHeading }}
                                    </span>
                                    <a
                                        href="{{ $mainHref }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="ml-3 min-w-0 text-right text-xs font-semibold text-slate-800 transition-colors hover:text-blue-600 hover:underline dark:text-slate-100 dark:hover:text-blue-400"
                                    >
                                        <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="inline h-4 w-4" />
                                    </a>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif

                @if ($internalLinks !== [])
                    <div @class(['space-y-1', 'pt-1' => $mainLinks !== []])>
                        <div class="mb-2 flex items-center space-x-1 text-[10px] font-bold uppercase tracking-widest text-slate-400 dark:text-slate-500">
                            <x-filament::icon icon="heroicon-m-link" class="h-3.5 w-3.5 shrink-0" />
                            <span>{{ $internalHeading }}</span>
                        </div>

                        @foreach ($internalLinks as $internalLink)
                            @php
                                $sourceHref = (string) ($internalLink['source_edit_url'] ?? $internalLink['source_url'] ?? '');
                                $destinationUrl = (string) ($internalLink['destination_url'] ?? $internalLink['url'] ?? '');
                            @endphp

                            <div class="my-2 flex w-full items-center justify-between rounded-lg border border-slate-200/60 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900/50">
                                <div class="flex w-[45%] items-center space-x-2 text-left">
                                    @if ($sourceHref !== '')
                                        <a
                                            href="{{ $sourceHref }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="truncate text-xs font-medium text-slate-700 transition-colors hover:text-blue-600 dark:text-slate-200 dark:hover:text-blue-400"
                                            title="{{ $sourceCaption }}"
                                        >
                                            📄 {{ $sourceCaption }}
                                        </a>
                                    @else
                                        <span class="truncate text-xs font-medium text-slate-400">📄 {{ $sourceCaption }}</span>
                                    @endif
                                </div>

                                <div class="flex w-[10%] items-center justify-center px-2">
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4 text-slate-400" />
                                </div>

                                <div class="flex w-[45%] items-center justify-end space-x-2 text-right">
                                    @if ($destinationUrl !== '')
                                        <a
                                            href="{{ $destinationUrl }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="truncate text-xs font-medium text-blue-600 transition-colors hover:underline dark:text-blue-400"
                                            title="{{ $targetCaption }}"
                                        >
                                            🎯 {{ $targetCaption }}
                                        </a>
                                    @else
                                        <span class="truncate text-xs font-medium text-slate-400">🎯 {{ $targetCaption }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif

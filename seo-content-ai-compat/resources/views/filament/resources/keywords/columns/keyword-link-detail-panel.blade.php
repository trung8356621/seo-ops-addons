@php
    /** @var \Omnichannel\Addons\SearchFoundation\Models\Keyword $record */
    use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;

    $groups = KeywordResource::resolveLinkDestinationGroups($record);

    /** @var list<array{key: string, label: string, url: string, domain: string, shorthand: string}> $sourceCards */
    $sourceCards = [];
    /** @var list<array{type: string, label: string, url: string, domain: string, shorthand: string}> $targetCards */
    $targetCards = [];

    foreach ($groups as $group) {
        $domain = (string) ($group['domain'] ?? '');

        foreach (is_array($group['main_links'] ?? null) ? $group['main_links'] : [] as $mainLink) {
            $url = (string) ($mainLink['edit_url'] ?? $mainLink['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $targetCards[] = [
                'type' => 'main',
                'label' => (string) ($mainLink['display'] ?? $mainLink['url'] ?? $url),
                'url' => $url,
                'domain' => $domain,
                'shorthand' => (string) ($mainLink['shorthand'] ?? $url),
            ];
        }

        foreach (is_array($group['internal_links'] ?? null) ? $group['internal_links'] : [] as $internalLink) {
            $sourceArticleId = (int) ($internalLink['source_article_id'] ?? 0);
            $sourceUrl = (string) ($internalLink['source_edit_url'] ?? $internalLink['source_url'] ?? '');
            $sourceLabel = trim((string) ($internalLink['source_display'] ?? ''));
            $sourceKey = $sourceArticleId > 0
                ? 'article:'.$sourceArticleId
                : 'url:'.md5($sourceUrl.'|'.$sourceLabel);

            if ($sourceKey !== 'url:'.md5('|') && ! array_key_exists($sourceKey, $sourceCards)) {
                $sourceCards[$sourceKey] = [
                    'key' => $sourceKey,
                    'label' => $sourceLabel !== '' ? $sourceLabel : ($sourceUrl !== '' ? $sourceUrl : '—'),
                    'url' => $sourceUrl,
                    'domain' => $domain,
                    'shorthand' => (string) ($internalLink['source_shorthand'] ?? $sourceLabel),
                ];
            }

            $destinationUrl = (string) ($internalLink['destination_url'] ?? $internalLink['url'] ?? '');
            if ($destinationUrl === '') {
                continue;
            }

            $targetCards[] = [
                'type' => 'internal',
                'label' => (string) ($internalLink['destination_display'] ?? $destinationUrl),
                'url' => $destinationUrl,
                'domain' => $domain,
                'shorthand' => (string) ($internalLink['destination_shorthand'] ?? $destinationUrl),
            ];
        }
    }

    $sourceCards = array_values($sourceCards);
    $sourceHeading = __('seo-content-ai::filament.keyword.link_detail_source_heading');
    $targetHeading = __('seo-content-ai::filament.keyword.link_detail_target_heading');
    $mainBadge = __('seo-content-ai::filament.keyword.destinations_dropdown_main_heading');
@endphp

<div class="space-y-5">
    <header class="border-b border-gray-200 pb-3 dark:border-white/10">
        <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('seo-content-ai::filament.keyword.phrase_short') }}
        </p>
        <h3 class="mt-1 text-base font-semibold text-gray-950 dark:text-white">
            {{ $record->phrase }}
        </h3>
    </header>

    @if ($sourceCards === [] && $targetCards === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">—</p>
    @else
        <section class="space-y-3">
            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                {{ $sourceHeading }}
            </h4>

            @if ($sourceCards === [])
                <p class="rounded-lg border border-dashed border-gray-200 px-3 py-4 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                    —
                </p>
            @else
                <ul class="grid gap-2 sm:grid-cols-2">
                    @foreach ($sourceCards as $source)
                        <li>
                            @if (($source['url'] ?? '') !== '')
                                <a
                                    href="{{ $source['url'] }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="group flex flex-col gap-1 rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition hover:border-primary-300 hover:shadow-md dark:border-white/10 dark:bg-gray-900/60 dark:hover:border-primary-500/40"
                                >
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                                        {{ $source['domain'] }}
                                    </span>
                                    <span class="line-clamp-2 text-sm font-medium text-gray-900 group-hover:text-primary-600 dark:text-gray-100 dark:group-hover:text-primary-400">
                                        {{ $source['label'] }}
                                    </span>
                                    <span class="truncate text-xs text-gray-500 dark:text-gray-400">
                                        {{ $source['shorthand'] }}
                                    </span>
                                </a>
                            @else
                                <div class="flex flex-col gap-1 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-gray-400">{{ $source['domain'] }}</span>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $source['label'] }}</span>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="flex items-center justify-center py-1 text-gray-400 dark:text-gray-500" aria-hidden="true">
            <x-filament::icon icon="heroicon-m-arrow-down" class="h-6 w-6 lg:hidden" />
            <x-filament::icon icon="heroicon-m-arrow-right" class="hidden h-6 w-6 lg:block" />
        </div>

        <section class="space-y-3">
            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-100">
                {{ $targetHeading }}
            </h4>

            @if ($targetCards === [])
                <p class="rounded-lg border border-dashed border-gray-200 px-3 py-4 text-sm text-gray-500 dark:border-white/10 dark:text-gray-400">
                    —
                </p>
            @else
                <ul class="grid gap-2 sm:grid-cols-2">
                    @foreach ($targetCards as $target)
                        <li>
                            <a
                                href="{{ $target['url'] }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="group flex flex-col gap-1 rounded-lg border p-3 shadow-sm transition hover:shadow-md {{ $target['type'] === 'main'
                                    ? 'border-emerald-200 bg-emerald-50/80 hover:border-emerald-300 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:hover:border-emerald-400/50'
                                    : 'border-blue-200 bg-blue-50/50 hover:border-blue-300 dark:border-blue-500/25 dark:bg-blue-500/10 dark:hover:border-blue-400/40' }}"
                            >
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        {{ $target['domain'] }}
                                    </span>
                                    @if ($target['type'] === 'main')
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-200">
                                            {{ $mainBadge }}
                                        </span>
                                    @endif
                                </div>
                                <span class="line-clamp-2 text-sm font-medium text-gray-900 group-hover:text-primary-600 dark:text-gray-100 dark:group-hover:text-primary-400">
                                    {{ $target['label'] }}
                                </span>
                                <span class="truncate text-xs text-gray-500 dark:text-gray-400">
                                    {{ $target['shorthand'] }}
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>

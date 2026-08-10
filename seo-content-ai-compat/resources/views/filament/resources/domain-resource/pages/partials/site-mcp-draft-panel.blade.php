@php
    /** @var array<string, mixed> $preview */
    $preview = $preview ?? [];
    $site = $preview['site'] ?? [];
    $content = $preview['content_context'] ?? [];
    $contact = $preview['contact'] ?? [];
    $pages = $preview['important_pages'] ?? [];
    $generation = $preview['generation'] ?? [];
    $counts = $preview['counts'] ?? [];
    $keyword = $preview['keyword_context'] ?? [];
    $mainTopics = $keyword['main_topics'] ?? [];
    $warnings = $keyword['warnings'] ?? [];
    $articleContext = $preview['article_context'] ?? [];
    $keywordPreview = $preview['keyword_preview'] ?? [];
    $websiteType = mb_strtolower(trim((string) ($site['website_type'] ?? 'news')));
    $isNewsSite = in_array($websiteType, ['news', ''], true);
    $mainTopicsEmptyHint = $isNewsSite
        ? __('(empty — expected for news)')
        : __('(empty — chưa có product_cat parent=0 đã xác minh; regenerate sau sync taxonomy)');
    $importantPagesEmptyHint = $isNewsSite
        ? __('Không auto-select (news).')
        : __('Không auto-select — chưa có product_cat parent=0 đã xác minh.');
@endphp

<div
    class="flex h-full min-h-0 flex-col"
    x-data="{
        open: 'full',
        useSiteMcp: true,
        selected: @js(array_values($mainTopics)),
        allTopics: @js(array_values($mainTopics)),
        selectAll() { this.selected = [...this.allTopics]; },
        clearAll() { this.selected = []; },
        toggle(topic) {
            if (this.selected.includes(topic)) {
                this.selected = this.selected.filter((t) => t !== topic);
            } else {
                this.selected = [...this.selected, topic];
            }
        },
        copyText(text) {
            if (! text) return;
            navigator.clipboard?.writeText(String(text));
        },
        keywordBlock() {
            if (! this.useSiteMcp) {
                return [
                    '=========================',
                    'KEYWORD CONTEXT PREVIEW',
                    '=========================',
                    '',
                    '(Use Site MCP disabled for this generation)',
                    '',
                    '=========================',
                ].join('\\n');
            }
            const lines = [
                '=========================',
                'KEYWORD CONTEXT PREVIEW',
                '=========================',
                '',
                'Website Type',
                @js((string) ($site['website_type'] ?? '')) || '(empty)',
                '',
                'Company',
                @js((string) ($site['company_short_identity'] ?? '')) || '(empty)',
                '',
                'Short Description',
                @js((string) ($site['short_description'] ?? '')) || '(empty)',
                '',
                'Main Topics',
            ];
            if (this.selected.length === 0) {
                lines.push('(none selected)');
            } else {
                this.selected.forEach((t) => lines.push('- ' + t));
            }
            lines.push('');
            lines.push('=========================');
            return lines.join('\\n');
        },
    }"
>
    <div class="flex shrink-0 items-start justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
        <div>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Site MCP Draft') }}</h3>
            <p class="mt-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                {{ __('Draft only — official data unchanged') }}
            </p>
        </div>
        <x-filament::button
            type="button"
            size="xs"
            color="gray"
            wire:click="closeSiteMcpDraftPanel"
        >
            {{ __('Close') }}
        </x-filament::button>
    </div>

    <div class="seo-site-mcp-draft-panel__body space-y-3 px-4 py-4 text-sm">
        <div wire:loading wire:target="generateSiteMcpDraftAction" class="space-y-3 animate-pulse">
            <div class="h-4 w-2/3 rounded bg-gray-200 dark:bg-gray-700"></div>
            <div class="h-4 w-full rounded bg-gray-200 dark:bg-gray-700"></div>
            <div class="h-24 w-full rounded bg-gray-200 dark:bg-gray-700"></div>
        </div>

        <div wire:loading.remove wire:target="generateSiteMcpDraftAction" class="space-y-3">
            @if(! ($preview['has_draft'] ?? false))
                <p class="text-gray-500">{{ __('Chưa có draft. Bấm Generate / Regenerate ở header.') }}</p>
            @else
                {{-- Accordion 1: Full Site MCP Draft --}}
                <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm font-semibold text-gray-900 dark:text-white"
                        @click="open = open === 'full' ? '' : 'full'"
                    >
                        <span>{{ __('Full Site MCP Draft') }}</span>
                        <span class="text-xs text-gray-400" x-text="open === 'full' ? '−' : '+'"></span>
                    </button>
                    <div x-show="open === 'full'" x-cloak class="space-y-4 border-t border-gray-200 px-3 py-3 dark:border-gray-700">
                        <section class="space-y-2">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Company') }}</h4>
                            @foreach([
                                'Company Short Identity' => $site['company_short_identity'] ?? '',
                                'Short Description' => $site['short_description'] ?? '',
                                'Website Type' => $site['website_type'] ?? '',
                            ] as $label => $value)
                                <div class="flex items-start justify-between gap-2 rounded border border-gray-100 px-2 py-1.5 dark:border-gray-800">
                                    <div class="min-w-0">
                                        <div class="text-[11px] font-medium text-gray-500">{{ __($label) }}</div>
                                        <div class="break-words text-xs text-gray-800 dark:text-gray-200">{{ $value !== '' ? $value : '—' }}</div>
                                    </div>
                                    <button type="button" class="shrink-0 text-[11px] font-medium text-primary-600" @click="copyText(@js((string) $value))">{{ __('Copy') }}</button>
                                </div>
                            @endforeach
                        </section>

                        <section class="space-y-2">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Contacts') }}</h4>
                            <div>
                                <div class="mb-1 text-[11px] font-medium text-gray-500">{{ __('Phones') }}</div>
                                @forelse(($contact['phones'] ?? []) as $phone)
                                    @php $phoneVal = is_array($phone) ? ($phone['value'] ?? '') : $phone; @endphp
                                    <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                                        <span>{{ $phoneVal }}</span>
                                        <button type="button" class="text-[11px] text-primary-600" @click="copyText(@js((string) $phoneVal))">{{ __('Copy') }}</button>
                                    </div>
                                @empty
                                    <p class="text-xs text-gray-400">{{ __('(empty)') }}</p>
                                @endforelse
                            </div>
                            <div>
                                <div class="mb-1 text-[11px] font-medium text-gray-500">{{ __('Emails') }}</div>
                                @forelse(($contact['emails'] ?? []) as $email)
                                    @php $emailVal = is_array($email) ? ($email['value'] ?? '') : $email; @endphp
                                    <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                                        <span>{{ $emailVal }}</span>
                                        <button type="button" class="text-[11px] text-primary-600" @click="copyText(@js((string) $emailVal))">{{ __('Copy') }}</button>
                                    </div>
                                @empty
                                    <p class="text-xs text-gray-400">{{ __('(empty)') }}</p>
                                @endforelse
                            </div>
                            <div>
                                <div class="mb-1 text-[11px] font-medium text-gray-500">{{ __('Socials') }}</div>
                                @forelse(($contact['socials'] ?? []) as $social)
                                    @php
                                        $net = is_array($social) ? ($social['network'] ?? '') : '';
                                        $url = is_array($social) ? ($social['url'] ?? $social['value'] ?? '') : $social;
                                    @endphp
                                    <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                                        <span>{{ $net }}: {{ $url }}</span>
                                        <button type="button" class="text-[11px] text-primary-600" @click="copyText(@js((string) $url))">{{ __('Copy') }}</button>
                                    </div>
                                @empty
                                    <p class="text-xs text-gray-400">{{ __('(empty)') }}</p>
                                @endforelse
                            </div>
                        </section>

                        <section class="space-y-2">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Main Topics') }}</h4>
                            <ul class="list-disc space-y-0.5 pl-5 text-xs">
                                @forelse($mainTopics as $topic)
                                    <li class="flex items-center justify-between gap-2">
                                        <span>{{ $topic }}</span>
                                        <button type="button" class="text-[11px] text-primary-600" @click="copyText(@js((string) $topic))">{{ __('Copy') }}</button>
                                    </li>
                                @empty
                                    <li class="text-gray-400">{{ $mainTopicsEmptyHint }}</li>
                                @endforelse
                            </ul>
                        </section>

                        <section class="space-y-2">
                            <h4 class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Important Pages') }}</h4>
                            <p class="text-[11px] text-gray-400">{{ __('URLs internal only — never sent to AI.') }}</p>
                            @forelse($pages as $page)
                                <div class="rounded border border-gray-100 px-2 py-1.5 text-xs dark:border-gray-800">
                                    <div class="font-medium">{{ $page['title'] ?? '—' }}</div>
                                    <div class="text-gray-500">
                                        kw: {{ $page['keyword'] ?? '—' }}
                                        · {{ $page['type'] ?? $page['page_type'] ?? '' }}
                                        · {{ $page['source'] ?? '' }}
                                        · conf={{ $page['confidence'] ?? '' }}
                                    </div>
                                    @if(! empty($page['seo_title']))
                                        <div class="text-gray-400">SEO: {{ $page['seo_title'] }}</div>
                                    @endif
                                    @if(! empty($page['url']))
                                        <div class="mt-0.5 break-all text-gray-400">{{ $page['url'] }}</div>
                                    @endif
                                </div>
                            @empty
                                <p class="text-xs text-gray-400">{{ $importantPagesEmptyHint }}</p>
                            @endforelse
                        </section>

                        @if($warnings !== [])
                            <section class="space-y-1">
                                <h4 class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">{{ __('Warnings') }}</h4>
                                <ul class="space-y-0.5 pl-5 text-xs text-amber-800 dark:text-amber-200">
                                    @foreach($warnings as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif

                        <section class="space-y-1 rounded-lg bg-gray-50 px-3 py-2 text-xs dark:bg-gray-950">
                            <h4 class="font-semibold text-gray-900 dark:text-white">{{ __('Generation Metadata') }}</h4>
                            <div>generated_at: {{ $generation['generated_at'] ?? '—' }}</div>
                            <div>source: {{ $generation['source'] ?? '—' }}</div>
                            <div>sync_run: {{ $generation['sync_run'] ?? 'null' }}</div>
                            <div>version: {{ $generation['version'] ?? '—' }}</div>
                            <div>official_site_mcp_exists: {{ ($generation['official_site_mcp_exists'] ?? false) ? 'true' : 'false' }}</div>
                            <div class="font-medium text-emerald-700 dark:text-emerald-300">official_fields_modified: false</div>
                            <div class="mt-1 text-gray-500">
                                stats:
                                post={{ (int) ($counts['post'] ?? 0) }},
                                page={{ (int) ($counts['page'] ?? 0) }},
                                product={{ (int) ($counts['product'] ?? 0) }} (excluded),
                                product_cat={{ (int) ($counts['product_cat'] ?? 0) }},
                                root_product_cat={{ (int) ($counts['root_product_cat'] ?? 0) }},
                                attachment={{ (int) ($counts['attachment'] ?? 0) }}
                            </div>
                            @if(in_array('ROOT_PRODUCT_CATEGORIES_NOT_AVAILABLE', $warnings, true))
                                <div class="mt-1 font-medium text-amber-700 dark:text-amber-300">
                                    ROOT_PRODUCT_CATEGORIES_NOT_AVAILABLE
                                </div>
                            @endif
                        </section>
                    </div>
                </div>

                {{-- Accordion 2: Article Context Preview --}}
                <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm font-semibold text-gray-900 dark:text-white"
                        @click="open = open === 'article' ? '' : 'article'"
                    >
                        <span>{{ __('Article Context Preview') }}</span>
                        <span class="text-xs text-gray-400" x-text="open === 'article' ? '−' : '+'"></span>
                    </button>
                    <div x-show="open === 'article'" x-cloak class="space-y-2 border-t border-gray-200 px-3 py-3 dark:border-gray-700">
                        <p class="text-[11px] text-gray-500">{{ __('Exact context Article Generation / Rewrite will receive. Not raw Site MCP.') }}</p>
                        @if(($articleContext['has_unresolved'] ?? false) === true)
                            <div class="rounded border border-amber-300 bg-amber-50 px-2 py-1.5 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                                {{ __('Unresolved placeholders must never survive into the final prompt:') }}
                                {{ implode(', ', $articleContext['unresolved'] ?? []) }}
                            </div>
                        @endif
                        <pre class="max-h-80 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-2 text-[11px] leading-relaxed text-gray-800 dark:bg-gray-950 dark:text-gray-200">{{ $articleContext['text'] ?? '' }}</pre>
                        <button type="button" class="text-[11px] font-medium text-primary-600" @click="copyText(@js((string) ($articleContext['text'] ?? '')))">{{ __('Copy') }}</button>
                    </div>
                </div>

                {{-- Accordion 3: New Keyword Context Preview --}}
                <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm font-semibold text-gray-900 dark:text-white"
                        @click="open = open === 'keyword' ? '' : 'keyword'"
                    >
                        <span>{{ __('New Keyword Context Preview') }}</span>
                        <span class="text-xs text-gray-400" x-text="open === 'keyword' ? '−' : '+'"></span>
                    </button>
                    <div x-show="open === 'keyword'" x-cloak class="space-y-3 border-t border-gray-200 px-3 py-3 dark:border-gray-700">
                        <p class="text-[11px] text-gray-500">{{ __('Exact context Keyword Discovery will receive. Checkboxes affect CURRENT generation only — never official Site MCP.') }}</p>

                        <label class="flex items-center gap-2 text-xs font-medium text-gray-800 dark:text-gray-200">
                            <input type="checkbox" x-model="useSiteMcp" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            {{ __('Use Site MCP') }}
                        </label>

                        <div class="space-y-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-[11px] font-medium uppercase tracking-wide text-gray-500">{{ __('Main Topics') }}</span>
                                <div class="flex gap-2">
                                    <button type="button" class="text-[11px] text-primary-600" @click="selectAll()">{{ __('Select all') }}</button>
                                    <button type="button" class="text-[11px] text-gray-500" @click="clearAll()">{{ __('Clear all') }}</button>
                                </div>
                            </div>
                            <template x-for="topic in allTopics" :key="topic">
                                <label class="flex items-center gap-2 text-xs text-gray-800 dark:text-gray-200">
                                    <input
                                        type="checkbox"
                                        class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                                        :checked="selected.includes(topic)"
                                        @change="toggle(topic)"
                                    >
                                    <span x-text="topic"></span>
                                </label>
                            </template>
                            @if($mainTopics === [])
                                <p class="text-xs text-gray-400">{{ $mainTopicsEmptyHint }}</p>
                            @endif
                        </div>

                        <pre class="max-h-80 overflow-auto whitespace-pre-wrap rounded bg-gray-50 p-2 text-[11px] leading-relaxed text-gray-800 dark:bg-gray-950 dark:text-gray-200" x-text="keywordBlock()"></pre>
                        <button type="button" class="text-[11px] font-medium text-primary-600" @click="copyText(keywordBlock())">{{ __('Copy') }}</button>
                    </div>
                </div>

                <details class="rounded-lg border border-dashed border-gray-300 px-3 py-2 dark:border-gray-600">
                    <summary class="cursor-pointer text-xs font-medium text-gray-600 dark:text-gray-300">
                        {{ __('Developer: raw JSON') }}
                    </summary>
                    <pre class="mt-2 max-h-64 overflow-auto text-[11px] leading-snug text-gray-700 dark:text-gray-300">{{ $preview['raw_json'] ?? '' }}</pre>
                </details>
            @endif
        </div>
    </div>
</div>

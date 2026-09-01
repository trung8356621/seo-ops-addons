@php
    $card = $this->getSiteHealthCard();
    $sections = $card['sections'] ?? [];
    $syncRunning = (bool) ($siteSyncV2Running ?? false);
    $syncStuck = (bool) ($siteSyncV2Stuck ?? false);
    $syncFailed = in_array((string) ($siteSyncV2Status ?? ''), ['failed', 'needs_attention'], true);

    $primaryKeys = ['publishing', 'site_sync', 'seo_ops_data', 'focus_keywords', 'link_health'];
    $primaryLabels = [
        'publishing' => 'Publishing',
        'site_sync' => 'Site Sync',
        'seo_ops_data' => 'SEO Data',
        'focus_keywords' => 'Focus Keywords',
        'link_health' => 'Links',
    ];
    $technicalKeys = ['wordpress', 'seo_snapshot', 'capabilities'];
@endphp

<div class="domain-site-health self-start h-auto">
    <div class="mb-3 flex flex-wrap items-center justify-end gap-2">
        <button
            type="button"
            wire:click="startLinkAnalysis"
            wire:loading.attr="disabled"
            wire:target="startLinkAnalysis"
            class="inline-flex items-center rounded-md border border-gray-200 bg-white px-2 py-1 text-[12px] font-semibold text-primary-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-primary-300"
        >
            <span wire:loading.remove wire:target="startLinkAnalysis">Phân tích lại links</span>
            <span wire:loading wire:target="startLinkAnalysis" class="opacity-50">Đang xếp hàng…</span>
        </button>
    </div>

    <div class="domain-site-health__ops domain-site-health__sections">
        @foreach ($primaryKeys as $sectionKey)
            @php
                $section = $sections[$sectionKey] ?? null;
                if ($section === null) {
                    continue;
                }
                $ok = (bool) ($section['ok'] ?? false);
                $dataSeverity = $sectionKey === 'seo_ops_data'
                    ? (string) (($section['payload']['severity'] ?? ($ok ? 'green' : 'yellow')))
                    : '';
                $focusPayload = $sectionKey === 'focus_keywords' && is_array($section['payload'] ?? null)
                    ? $section['payload']
                    : [];
                $focusMissing = (int) ($focusPayload['missing_focus_keyword_articles'] ?? 0);
                $focusFilterUrl = is_string($focusPayload['filter_url'] ?? null) ? $focusPayload['filter_url'] : null;
                $tone = match (true) {
                    $sectionKey === 'site_sync' && $syncFailed => 'failed',
                    $sectionKey === 'site_sync' && $syncStuck => 'stale',
                    $sectionKey === 'site_sync' && $syncRunning => 'running',
                    $sectionKey === 'seo_ops_data' && $dataSeverity === 'red' => 'failed',
                    $sectionKey === 'seo_ops_data' && $dataSeverity === 'yellow' => 'stale',
                    $sectionKey === 'focus_keywords' && ! $ok => 'stale',
                    $ok => 'healthy',
                    default => 'stale',
                };
                $mark = match ($tone) {
                    'running' => '↻',
                    'failed' => '✕',
                    'stale' => '⚠',
                    default => '✓',
                };
                $lines = collect($section['lines'] ?? [])->filter()->values();
                $summaryLine = $lines->first() ?? ($ok ? 'Healthy' : 'Needs attention');
                $secondaryLine = in_array($sectionKey, ['seo_ops_data', 'focus_keywords'], true) ? $lines->get(1) : null;
            @endphp
            <div @class([
                'domain-site-health__op h-auto rounded-lg border-l-[3px] bg-white px-2.5 py-1.5 dark:bg-gray-900',
                'border-l-success-600 border border-success-100 dark:border-success-500/20' => $tone === 'healthy',
                'border-l-primary-600 border border-primary-100 dark:border-primary-500/20' => $tone === 'running',
                'border-l-warning-500 border border-warning-100 dark:border-warning-500/20' => $tone === 'stale',
                'border-l-danger-600 border border-danger-100 dark:border-danger-500/20' => $tone === 'failed',
            ])>
                <p @class([
                    'text-[12px] font-semibold uppercase tracking-wide',
                    'text-success-700 dark:text-success-400' => $tone === 'healthy',
                    'text-primary-700 dark:text-primary-300' => $tone === 'running',
                    'text-warning-700 dark:text-warning-400' => $tone === 'stale',
                    'text-danger-700 dark:text-danger-400' => $tone === 'failed',
                ])>
                    <span aria-hidden="true">{{ $mark }}</span>
                    {{ $primaryLabels[$sectionKey] ?? ($section['label'] ?? '') }}
                </p>
                <p class="text-[13px] leading-snug text-gray-800 dark:text-gray-200">{{ $summaryLine }}</p>
                @if (filled($secondaryLine))
                    @if ($sectionKey === 'focus_keywords' && $focusMissing > 0 && filled($focusFilterUrl))
                        <p class="text-[12px] leading-snug">
                            <a
                                href="{{ $focusFilterUrl }}"
                                class="font-medium text-warning-700 underline decoration-warning-400/60 underline-offset-2 hover:text-warning-800 dark:text-warning-400 dark:hover:text-warning-300"
                            >{{ $secondaryLine }}</a>
                        </p>
                    @else
                        <p class="text-[12px] leading-snug text-gray-500 dark:text-gray-400">{{ $secondaryLine }}</p>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    <div class="domain-site-health__metrics mt-3" role="group" aria-label="Link metrics">
        @foreach ([
            'internal_links' => ['label' => 'Internal links', 'checked' => true],
            'orphan_pages' => ['label' => 'Orphan pages', 'checked' => true],
            'link_opportunities' => ['label' => 'Link opportunities', 'checked' => (bool) ($card['link_opportunities_checked'] ?? false)],
            'broken_links' => ['label' => 'Broken links', 'checked' => (bool) ($card['broken_links_checked'] ?? false)],
        ] as $metricKey => $metric)
            @php
                $checked = (bool) ($metric['checked'] ?? false);
                $raw = $card[$metricKey] ?? null;
                $display = $checked && $raw !== null
                    ? number_format((int) $raw)
                    : '—';
            @endphp
            <div class="domain-site-health__metric">
                <p class="domain-site-health__metric-label">{{ $metric['label'] }}</p>
                <p class="domain-site-health__metric-value">{{ $display }}</p>
                @if (! $checked)
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">Not checked</p>
                @endif
            </div>
        @endforeach
    </div>

    <details class="domain-site-health__tech mt-3 text-[12px] text-gray-500 dark:text-gray-400">
        <summary class="cursor-pointer font-medium text-gray-700 dark:text-gray-200">Technical details</summary>
        <div class="mt-2 space-y-2">
            @foreach ($technicalKeys as $sectionKey)
                @php $section = $sections[$sectionKey] ?? null; @endphp
                @continue($section === null)
                <div class="rounded-md border border-gray-100 px-2.5 py-1.5 dark:border-gray-800">
                    <p class="font-semibold text-gray-700 dark:text-gray-200">{{ $section['label'] ?? $sectionKey }}</p>
                    @foreach (($section['lines'] ?? []) as $line)
                        @continue(! filled($line))
                        <p class="leading-snug">{{ $line }}</p>
                    @endforeach
                </div>
            @endforeach
            @if (! empty($sections['seo_ops_data']['payload']['data_health']['fields'] ?? null)
                || ! empty($sections['seo_ops_data']['lines'] ?? null))
                <div class="rounded-md border border-gray-100 px-2.5 py-1.5 dark:border-gray-800">
                    <p class="font-semibold text-gray-700 dark:text-gray-200">SEO Ops data (detail)</p>
                    @php
                        $dhFields = is_array($sections['seo_ops_data']['payload']['data_health']['fields'] ?? null)
                            ? $sections['seo_ops_data']['payload']['data_health']['fields']
                            : [];
                    @endphp
                    @if ($dhFields !== [])
                        <div class="mt-1 overflow-x-auto">
                            <table class="w-full min-w-[32rem] border-collapse text-[11px] tabular-nums">
                                <thead>
                                    <tr class="border-b border-gray-200 text-left dark:border-gray-700">
                                        <th class="py-1 pr-2 font-semibold">Field</th>
                                        <th class="py-1 px-1 text-right font-semibold">Applicable</th>
                                        <th class="py-1 px-1 text-right font-semibold">Present</th>
                                        <th class="py-1 px-1 text-right font-semibold">Missing</th>
                                        <th class="py-1 px-1 text-right font-semibold">N/A</th>
                                        <th class="py-1 pl-1 text-right font-semibold">Source absent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($dhFields as $dhField)
                                        @continue(! is_array($dhField))
                                        <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                                            <td class="py-1 pr-2">{{ $dhField['label'] ?? $dhField['key'] ?? '' }}</td>
                                            <td class="py-1 px-1 text-right">{{ number_format((int) ($dhField['applicable'] ?? $dhField['total'] ?? 0)) }}</td>
                                            <td class="py-1 px-1 text-right">{{ number_format((int) ($dhField['raw_present'] ?? $dhField['present'] ?? 0)) }}</td>
                                            <td class="py-1 px-1 text-right">{{ number_format((int) ($dhField['missing'] ?? 0)) }}</td>
                                            <td class="py-1 px-1 text-right">{{ number_format((int) ($dhField['not_applicable'] ?? 0)) }}</td>
                                            <td class="py-1 pl-1 text-right">{{ number_format((int) ($dhField['source_absent'] ?? 0)) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        @foreach (($sections['seo_ops_data']['lines'] ?? []) as $line)
                            @continue(! filled($line))
                            <p class="leading-snug">{{ $line }}</p>
                        @endforeach
                    @endif
                </div>
            @endif
            @php
                $fk = is_array($sections['focus_keywords']['payload'] ?? null)
                    ? $sections['focus_keywords']['payload']
                    : (is_array($card['focus_keyword_coverage'] ?? null) ? $card['focus_keyword_coverage'] : []);
                $fkBreakdown = is_array($fk['source_breakdown'] ?? null) ? $fk['source_breakdown'] : [];
            @endphp
            @if ($fk !== [])
                <div class="rounded-md border border-gray-100 px-2.5 py-1.5 dark:border-gray-800">
                    <p class="font-semibold text-gray-700 dark:text-gray-200">Focus Keyword Coverage (detail)</p>
                    <p class="leading-snug">Eligible SEO articles {{ number_format((int) ($fk['eligible_article_count'] ?? 0)) }}</p>
                    <p class="leading-snug">With effective focus keyword {{ number_format((int) ($fk['articles_with_focus_keyword'] ?? 0)) }}</p>
                    <p class="leading-snug">Missing focus keyword {{ number_format((int) ($fk['missing_focus_keyword_articles'] ?? 0)) }}</p>
                    <p class="mt-1 leading-snug">Unique focus keyword phrases {{ number_format((int) ($fk['unique_effective_focus_phrases'] ?? 0)) }}</p>
                    <p class="leading-snug">Focus/article relations {{ number_format((int) ($fk['focus_article_relations'] ?? 0)) }}</p>
                    <p class="mt-1 leading-snug">Manual-covered articles {{ number_format((int) ($fkBreakdown['manual'] ?? 0)) }}</p>
                    <p class="leading-snug">Provider-covered articles {{ number_format((int) ($fkBreakdown['provider'] ?? 0)) }}</p>
                    <p class="leading-snug">Workspace-only articles {{ number_format((int) ($fkBreakdown['workspace'] ?? 0)) }}</p>
                    @if (filled($fkBreakdown['semantics'] ?? null))
                        <p class="mt-1 text-[11px] leading-snug text-gray-500 dark:text-gray-400">{{ $fkBreakdown['semantics'] }}</p>
                    @endif
                </div>
            @endif
            <div class="rounded-md border border-gray-100 px-2.5 py-1.5 font-mono dark:border-gray-800">
                <p>health: {{ $card['health'] ?? 'unknown' }}</p>
                @if (filled($card['dictionary_version'] ?? null))
                    <p>dictionary_version: {{ $card['dictionary_version'] }}</p>
                @endif
            </div>
        </div>
    </details>
</div>

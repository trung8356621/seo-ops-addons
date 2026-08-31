@php
    $card = $this->getSiteHealthCard();
    $sections = $card['sections'] ?? [];
    $syncRunning = (bool) ($siteSyncV2Running ?? false);
    $syncStuck = (bool) ($siteSyncV2Stuck ?? false);
    $syncFailed = ($siteSyncV2Status ?? '') === 'failed';
@endphp

<div class="domain-site-health self-start h-auto rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-[13px] font-semibold text-gray-800 dark:text-gray-100">{{ $card['domain'] ?? '' }}</h3>
        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="reconcileSiteWordPressState"
                wire:loading.attr="disabled"
                wire:target="reconcileSiteWordPressState"
                class="inline-flex items-center rounded-md border border-gray-200 bg-white px-2 py-1 text-[12px] font-semibold text-primary-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-primary-300"
            >
                <span wire:loading.remove wire:target="reconcileSiteWordPressState">Kiểm tra lại trạng thái</span>
                <span wire:loading wire:target="reconcileSiteWordPressState" class="opacity-50">Đang kiểm tra…</span>
            </button>
            <button
                type="button"
                wire:click="startLinkAnalysis"
                wire:loading.attr="disabled"
                wire:target="startLinkAnalysis"
                class="inline-flex items-center rounded-md border border-gray-200 bg-white px-2 py-1 text-[12px] font-semibold text-primary-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-primary-300"
            >
                Phân tích lại
            </button>
        </div>
    </div>
    <div class="domain-site-health__sections">
        @foreach ($sections as $sectionKey => $section)
            @php
                $ok = (bool) ($section['ok'] ?? false);
                $dataSeverity = $sectionKey === 'seo_ops_data'
                    ? (string) (($section['payload']['severity'] ?? ($ok ? 'green' : 'yellow')))
                    : '';
                $tone = match (true) {
                    $sectionKey === 'site_sync' && $syncFailed => 'failed',
                    $sectionKey === 'site_sync' && $syncStuck => 'stale',
                    $sectionKey === 'site_sync' && $syncRunning => 'running',
                    $sectionKey === 'wordpress' && ! $ok => 'failed',
                    $sectionKey === 'seo_ops_data' && $dataSeverity === 'red' => 'failed',
                    $sectionKey === 'seo_ops_data' && $dataSeverity === 'yellow' => 'stale',
                    $ok => 'healthy',
                    default => 'stale',
                };
                $mark = match ($tone) {
                    'running' => '↻',
                    'failed' => '✕',
                    'stale' => '⚠',
                    default => '✓',
                };
            @endphp
            <div @class([
                'h-auto rounded-lg border-l-[3px] bg-white px-2.5 py-1.5 dark:bg-gray-900',
                'border-l-success-600 border border-success-100 dark:border-success-500/20' => $tone === 'healthy',
                'border-l-primary-600 border border-primary-100 dark:border-primary-500/20' => $tone === 'running',
                'border-l-warning-500 border border-warning-100 dark:border-warning-500/20' => $tone === 'stale',
                'border-l-danger-600 border border-danger-100 dark:border-danger-500/20' => $tone === 'failed',
            ])>
                <p @class([
                    'text-[13px] font-semibold leading-snug',
                    'text-success-700 dark:text-success-400' => $tone === 'healthy',
                    'text-primary-700 dark:text-primary-300' => $tone === 'running',
                    'text-warning-700 dark:text-warning-400' => $tone === 'stale',
                    'text-danger-700 dark:text-danger-400' => $tone === 'failed',
                ])>
                    <span aria-hidden="true">{{ $mark }}</span>
                    {{ $section['label'] ?? '' }}
                </p>
                @foreach (($section['lines'] ?? []) as $line)
                    @continue(! filled($line))
                    <p class="text-[12px] leading-snug text-gray-700 dark:text-gray-300">{{ $line }}</p>
                @endforeach
            </div>
        @endforeach
    </div>
    <div class="mt-3 grid grid-cols-2 gap-2 text-[12px] text-gray-700 dark:text-gray-200 sm:grid-cols-4">
        <div>Internal links<br><strong>{{ number_format((int) ($card['internal_links'] ?? 0)) }}</strong></div>
        <div>Orphan pages<br><strong>{{ number_format((int) ($card['orphan_pages'] ?? 0)) }}</strong></div>
        <div>Link opportunities<br><strong>{{ number_format((int) ($card['link_opportunities'] ?? 0)) }}</strong></div>
        <div>Broken links<br><strong>{{ number_format((int) ($card['broken_links'] ?? 0)) }}</strong></div>
    </div>
</div>

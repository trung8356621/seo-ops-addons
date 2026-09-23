@php
    $langCoverage = method_exists($this, 'getSiteSyncLanguageCoverage') ? $this->getSiteSyncLanguageCoverage() : [];
    $isMultilingual = method_exists($this, 'isOverviewMultilingual') && $this->isOverviewMultilingual();
    $syncDisabled = ($incrementalSyncRunning ?? false)
        || ($metadataSyncRunning ?? false)
        || ($keywordResyncRunning ?? false)
        || ($siteSyncV2Running ?? false);
    $siteId = (int) ($this->getRecord()?->getKey() ?? 0);
    $storageKey = 'domain-overview-lang-tab-'.$siteId;
    $secondaries = collect($langCoverage)->where('role', 'secondary')->values()->all();
    $snapshots = is_array($this->languageTabSnapshots ?? null) ? $this->languageTabSnapshots : [];
    $remoteLoading = (bool) ($this->languageTabRemoteLoading ?? false);
@endphp

@if ($isMultilingual && $langCoverage !== [])
    {{--
      Alpine owns active-tab chrome so wire:poll remorph cannot reset visibility.
      Language literals are Blade-rendered ('vi') — never @js() inside Alpine/wire attributes.
      Sync buttons use wire:click with rendered literals; they open confirmation only.
    --}}
    <div
        class="mb-4 domain-language-tabs"
        wire:key="domain-language-tabs-{{ $siteId }}"
        x-data="{
            activeTab: 'overview',
            storageKey: '{{ $storageKey }}',
            init() {
                const saved = sessionStorage.getItem(this.storageKey);
                if (saved) {
                    this.activeTab = saved;
                }
            },
            select(tab) {
                if (this.activeTab === tab) {
                    return;
                }
                this.activeTab = tab;
                sessionStorage.setItem(this.storageKey, tab);
                $wire.setOverviewLanguageTab(tab);
            },
            tabClass(tab) {
                return this.activeTab === tab
                    ? 'bg-primary-600 text-white'
                    : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200';
            }
        }"
    >
        <div class="mb-4 flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-700">
            <button
                type="button"
                @click="select('overview')"
                :class="tabClass('overview')"
                class="rounded-md px-3 py-1.5 text-sm font-medium"
            >
                Tổng quan
            </button>
            @foreach ($langCoverage as $row)
                @php
                    $roleLabel = ($row['role'] ?? '') === 'primary' ? 'Chính' : 'Phụ';
                    $tabKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($row['language'] ?? '')) ?? '';
                @endphp
                @continue($tabKey === '')
                <button
                    type="button"
                    @click="select('{{ $tabKey }}')"
                    :class="tabClass('{{ $tabKey }}')"
                    class="rounded-md px-3 py-1.5 text-sm font-medium"
                >
                    {{ ($row['flag'] ?? '').' '.($row['label'] ?? $tabKey) }} · {{ $roleLabel }}
                </button>
            @endforeach
        </div>

        {{-- Overview: compact aggregate — primary sync only (resolves to primary language) --}}
        <div x-show="activeTab === 'overview'" x-cloak class="mb-4 space-y-3">
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                <p class="mb-2 text-[13px] font-semibold text-gray-800 dark:text-gray-100">Tóm tắt đa ngôn ngữ</p>
                <ul class="space-y-1 text-[12px] text-gray-600 dark:text-gray-300">
                    @foreach ($langCoverage as $row)
                        <li>
                            <span class="font-medium">{{ ($row['label'] ?? $row['language']).(($row['role'] ?? '') === 'primary' ? ' · Chính' : ' · Phụ') }}</span>
                            — WP {{ number_format((int) ($row['available_on_wp'] ?? 0)) }},
                            SEO Ops {{ number_format((int) ($row['synced_to_seo'] ?? 0)) }}
                        </li>
                    @endforeach
                </ul>
                <p class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
                    Ngôn ngữ chính là nguồn đồng bộ mặc định. Ngôn ngữ phụ chưa sync không bị coi là lỗi.
                </p>
            </div>
            @php
                $overviewPrimary = collect($langCoverage)->firstWhere('role', 'primary');
                $overviewPrimaryKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($overviewPrimary['language'] ?? '')) ?? '';
                $overviewPrimaryLabel = is_array($overviewPrimary) ? (string) ($overviewPrimary['label'] ?? $overviewPrimaryKey) : '';
            @endphp
            @if ($overviewPrimaryKey !== '' && ! ($siteSyncV2Running ?? false))
                <div data-domain-lang-sync-actions="overview-primary">
                    <x-filament::button
                        type="button"
                        color="success"
                        size="sm"
                        icon="heroicon-o-arrow-path"
                        wire:click="openSiteSyncPreflight"
                        wire:loading.attr="disabled"
                        :disabled="$syncDisabled"
                    >
                        Đồng bộ &amp; kiểm tra {{ $overviewPrimaryLabel }}
                    </x-filament::button>
                </div>
            @endif
        </div>

        @foreach ($langCoverage as $row)
            @php
                $tabKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($row['language'] ?? '')) ?? '';
                $isPrimary = ($row['role'] ?? '') === 'primary';
                $syncedCount = (int) ($row['synced_to_seo'] ?? 0);
                $showFullHealth = $isPrimary || $syncedCount > 0;
                $snapshot = is_array($snapshots[$tabKey] ?? null) ? $snapshots[$tabKey] : null;
            @endphp
            @continue($tabKey === '')

            <div
                x-show="activeTab === '{{ $tabKey }}'"
                x-cloak
                class="mb-4 space-y-3"
                wire:key="domain-lang-panel-{{ $tabKey }}"
            >
                @if ($isPrimary)
                    <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-100">
                        {{ ($row['flag'] ?? '').' '.($row['label'] ?? $tabKey) }} · Chính
                    </p>

                    @if ($showFullHealth && is_array($snapshot))
                        @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-language-health-panel', [
                            'snapshot' => $snapshot,
                            'remoteLoading' => $remoteLoading && ($this->overviewLanguageTab ?? '') === $tabKey,
                        ])
                    @elseif ($showFullHealth)
                        <div class="rounded-lg border border-dashed border-gray-200 p-3 text-[12px] text-gray-500 dark:border-gray-700">
                            Đang tải trạng thái ngôn ngữ…
                        </div>
                    @endif

                    @if ($secondaries !== [])
                        <div class="rounded-lg border border-gray-200 p-3 text-[12px] dark:border-gray-700">
                            <p class="mb-1 font-semibold text-gray-800 dark:text-gray-100">Phủ bản dịch</p>
                            <ul class="space-y-0.5 text-gray-600 dark:text-gray-300">
                                @foreach ($secondaries as $sec)
                                    <li>
                                        {{ $sec['label'] ?? $sec['language'] }}:
                                        WP {{ number_format((int) ($sec['available_on_wp'] ?? 0)) }}
                                        · SEO Ops {{ number_format((int) ($sec['synced_to_seo'] ?? 0)) }}
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    {{-- Single canonical action area for this language tab --}}
                    <div class="flex flex-wrap items-center gap-2" data-domain-lang-sync-actions="{{ $tabKey }}">
                        <x-filament::button
                            type="button"
                            color="success"
                            size="sm"
                            icon="heroicon-o-arrow-path"
                            wire:click="runScopedSiteSyncAction('{{ $tabKey }}', false)"
                            wire:loading.attr="disabled"
                            wire:target="runScopedSiteSyncAction('{{ $tabKey }}', false),runScopedSiteSyncAction('{{ $tabKey }}', true)"
                            :disabled="$syncDisabled"
                        >
                            Đồng bộ &amp; kiểm tra {{ $row['label'] ?? $tabKey }}
                        </x-filament::button>
                        <x-filament::button
                            type="button"
                            color="gray"
                            size="sm"
                            wire:click="runScopedSiteSyncAction('{{ $tabKey }}', true)"
                            wire:loading.attr="disabled"
                            wire:target="runScopedSiteSyncAction('{{ $tabKey }}', false),runScopedSiteSyncAction('{{ $tabKey }}', true)"
                            :disabled="$syncDisabled"
                        >
                            Đồng bộ toàn bộ {{ $row['label'] ?? $tabKey }}
                        </x-filament::button>
                    </div>
                @else
                    <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-100">
                        {{ ($row['flag'] ?? '').' '.($row['label'] ?? $tabKey) }} · Phụ
                    </p>

                    @if (! $showFullHealth)
                        <div class="space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                            <p>
                                Có trên WordPress: <span class="font-semibold">{{ number_format((int) ($row['available_on_wp'] ?? 0)) }}</span>
                            </p>
                            <p>
                                Đã sync SEO Ops: <span class="font-semibold">{{ number_format($syncedCount) }}</span>
                            </p>
                            @if (! ($row['sync_enabled'] ?? false))
                                <p class="text-warning-700 dark:text-warning-400">
                                    {{ $row['gate_message'] ?: ('Chưa thể đồng bộ '.$row['label'].'. Hãy hoàn tất đồng bộ ngôn ngữ chính trước.') }}
                                </p>
                            @else
                                <p class="text-[12px] text-gray-500 dark:text-gray-400">
                                    Ngôn ngữ phụ chưa đồng bộ — đây không phải lỗi Data Health.
                                </p>
                            @endif
                        </div>
                    @elseif (is_array($snapshot))
                        @include('seo-content-ai::filament.resources.domain-resource.pages.partials.domain-language-health-panel', [
                            'snapshot' => $snapshot,
                            'remoteLoading' => $remoteLoading && ($this->overviewLanguageTab ?? '') === $tabKey,
                        ])
                    @else
                        <div class="rounded-lg border border-dashed border-gray-200 p-3 text-[12px] text-gray-500 dark:border-gray-700">
                            Đang tải trạng thái ngôn ngữ…
                        </div>
                    @endif

                    <div data-domain-lang-sync-actions="{{ $tabKey }}">
                        <x-filament::button
                            type="button"
                            color="success"
                            size="sm"
                            icon="heroicon-o-arrow-path"
                            wire:click="runScopedSiteSyncAction('{{ $tabKey }}', false)"
                            wire:loading.attr="disabled"
                            wire:target="runScopedSiteSyncAction('{{ $tabKey }}', false)"
                            :disabled="!($row['sync_enabled'] ?? false) || $syncDisabled"
                        >
                            Đồng bộ {{ $row['label'] ?? $tabKey }}
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif

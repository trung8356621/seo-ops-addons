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
@endphp

@if ($isMultilingual && $langCoverage !== [])
    {{--
      Tab UI is Alpine-owned so wire:poll remorph cannot reset the active tab.
      Livewire only warms local snapshots (renderless) — it does not own visibility.
    --}}
    <div
        class="mb-4"
        x-data="{
            activeTab: 'overview',
            storageKey: @js($storageKey),
            loading: false,
            init() {
                const saved = sessionStorage.getItem(this.storageKey);
                if (saved) {
                    this.activeTab = saved;
                }
            },
            select(tab) {
                if (this.activeTab === tab || this.loading) {
                    return;
                }
                this.activeTab = tab;
                sessionStorage.setItem(this.storageKey, tab);
                this.loading = true;
                this.$wire.setOverviewLanguageTab(tab)
                    .catch(() => {})
                    .finally(() => { this.loading = false; });
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
                    $tabKey = (string) ($row['language'] ?? '');
                @endphp
                <button
                    type="button"
                    @click="select(@js($tabKey))"
                    :class="tabClass(@js($tabKey))"
                    class="rounded-md px-3 py-1.5 text-sm font-medium"
                >
                    {{ ($row['flag'] ?? '').' '.($row['label'] ?? $tabKey) }} · {{ $roleLabel }}
                </button>
            @endforeach
        </div>

        {{-- Overview summary --}}
        <div x-show="activeTab === 'overview'" x-cloak class="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
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

        @foreach ($langCoverage as $row)
            @php
                $tabKey = (string) ($row['language'] ?? '');
                $isPrimary = ($row['role'] ?? '') === 'primary';
                $syncedCount = (int) ($row['synced_to_seo'] ?? 0);
                $showFullHealth = $isPrimary || $syncedCount > 0;
            @endphp
            <div x-show="activeTab === @js($tabKey)" x-cloak class="mb-4 space-y-3">
                @if ($isPrimary)
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                        <p class="mb-1 text-[13px] font-semibold text-gray-800 dark:text-gray-100">
                            {{ ($row['flag'] ?? '').' '.($row['label'] ?? $tabKey) }} · Chính
                        </p>
                        <p class="text-[12px] text-gray-600 dark:text-gray-300">
                            Có trên WordPress: <span class="font-semibold">{{ number_format((int) ($row['available_on_wp'] ?? 0)) }}</span>
                            · Đã sync SEO Ops: <span class="font-semibold">{{ number_format($syncedCount) }}</span>
                        </p>
                    </div>

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

                    <div class="flex flex-wrap items-center gap-2">
                        <x-filament::button
                            type="button"
                            color="success"
                            size="sm"
                            icon="heroicon-o-arrow-path"
                            wire:click="runScopedSiteSyncAction(@js($tabKey), false)"
                            wire:loading.attr="disabled"
                            :disabled="$syncDisabled"
                        >
                            Đồng bộ &amp; kiểm tra {{ $row['label'] ?? $tabKey }}
                        </x-filament::button>
                        <x-filament::button
                            type="button"
                            color="gray"
                            size="sm"
                            wire:click="runScopedSiteSyncAction(@js($tabKey), true)"
                            wire:loading.attr="disabled"
                            :disabled="$syncDisabled"
                        >
                            Đồng bộ toàn bộ {{ $row['label'] ?? $tabKey }}
                        </x-filament::button>
                    </div>
                @else
                    <div class="space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
                        <p class="text-[13px] font-semibold text-gray-800 dark:text-gray-100">
                            {{ ($row['flag'] ?? '').' '.($row['label'] ?? $tabKey) }} · Phụ
                        </p>
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
                        @elseif (! $showFullHealth)
                            <p class="text-[12px] text-gray-500 dark:text-gray-400">
                                Ngôn ngữ phụ chưa đồng bộ — đây không phải lỗi Data Health.
                            </p>
                        @endif
                    </div>

                    <x-filament::button
                        type="button"
                        color="success"
                        size="sm"
                        icon="heroicon-o-arrow-path"
                        wire:click="runScopedSiteSyncAction(@js($tabKey), false)"
                        wire:loading.attr="disabled"
                        :disabled="!($row['sync_enabled'] ?? false) || $syncDisabled"
                    >
                        Đồng bộ {{ $row['label'] ?? $tabKey }}
                    </x-filament::button>
                @endif
            </div>
        @endforeach
    </div>
@endif

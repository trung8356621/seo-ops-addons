@php
    $langCoverage = method_exists($this, 'getSiteSyncLanguageCoverage') ? $this->getSiteSyncLanguageCoverage() : [];
    $isMultilingual = method_exists($this, 'isOverviewMultilingual') && $this->isOverviewMultilingual();
    $activeTab = $this->overviewLanguageTab ?? 'overview';
    $activeLangRow = null;
    foreach ($langCoverage as $row) {
        if (($row['language'] ?? '') === $activeTab) {
            $activeLangRow = $row;
            break;
        }
    }
@endphp

@if ($isMultilingual && $langCoverage !== [])
    <div class="mb-4 flex flex-wrap gap-2 border-b border-gray-200 pb-3 dark:border-gray-700">
        <button
            type="button"
            wire:click="setOverviewLanguageTab('overview')"
            class="rounded-md px-3 py-1.5 text-sm font-medium {{ $activeTab === 'overview' ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' }}"
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
                wire:click="setOverviewLanguageTab(@js($tabKey))"
                class="rounded-md px-3 py-1.5 text-sm font-medium {{ $activeTab === $tabKey ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200' }}"
            >
                {{ ($row['flag'] ?? '').' '.($row['label'] ?? $tabKey) }} · {{ $roleLabel }}
            </button>
        @endforeach
    </div>
@endif

@if ($isMultilingual && $activeLangRow !== null && ($activeLangRow['role'] ?? '') === 'secondary')
    <div class="mb-4 space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm dark:border-gray-700 dark:bg-gray-900">
        <p>
            Có trên WordPress: <span class="font-semibold">{{ number_format((int) ($activeLangRow['available_on_wp'] ?? 0)) }}</span>
        </p>
        <p>
            Đã sync SEO Ops: <span class="font-semibold">{{ number_format((int) ($activeLangRow['synced_to_seo'] ?? 0)) }}</span>
        </p>
        @if (! ($activeLangRow['sync_enabled'] ?? false))
            <p class="text-warning-700 dark:text-warning-400">
                {{ $activeLangRow['gate_message'] ?: ('Chưa thể đồng bộ '.$activeLangRow['label'].'. Hãy hoàn tất đồng bộ ngôn ngữ chính trước.') }}
            </p>
        @endif
        <x-filament::button
            type="button"
            color="success"
            size="sm"
            icon="heroicon-o-arrow-path"
            wire:click="runScopedSiteSyncAction(@js($activeLangRow['language']), false)"
            wire:loading.attr="disabled"
            :disabled="!($activeLangRow['sync_enabled'] ?? false) || ($siteSyncV2Running ?? false)"
        >
            Đồng bộ {{ $activeLangRow['label'] ?? $activeLangRow['language'] }}
        </x-filament::button>
    </div>
@endif

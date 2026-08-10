@php
    $queueBootstrap = $this->getQueueBootstrapData();
    $runStats = $this->getRunStatsPayload();
@endphp

<x-filament-panels::page>
    @push('styles')
        @vite('addons/content-projects/resources/css/project-run-queue.css')
        <style>
            .seo-run-items-wrap,
            .seo-run-items-wrap table,
            .seo-run-items-wrap thead,
            .seo-run-items-wrap tbody,
            .seo-run-items-wrap tr,
            .seo-run-items-wrap th,
            .seo-run-items-wrap td {
                overflow: visible !important;
            }
            .seo-run-row-actions {
                overflow: visible !important;
                white-space: nowrap;
                position: relative;
                z-index: 1;
                padding-right: 2.75rem;
            }
            .seo-run-actions-menu {
                position: relative;
                display: inline-block;
            }
            .seo-run-actions-dropdown {
                position: absolute;
                bottom: calc(100% + 0.25rem);
                top: auto;
                right: 0;
                z-index: 60;
                min-width: 16rem;
                max-width: 22rem;
                overflow: hidden;
                border-radius: 0.5rem;
                background: #fff;
                padding: 0.25rem 0;
                text-align: left;
                box-shadow: 0 10px 15px -3px rgba(15, 23, 42, 0.12), 0 4px 6px -4px rgba(15, 23, 42, 0.08);
                border: 1px solid #e5e7eb;
            }
            .dark .seo-run-actions-dropdown { background: rgb(17 24 39); border-color: rgb(55 65 81); }
            .seo-run-actions-dropdown__item {
                display: block;
                width: 100%;
                padding: 0.5rem 0.75rem;
                text-align: left;
                font-size: 0.875rem;
                line-height: 1.25rem;
                color: #374151;
                white-space: nowrap;
                background: transparent;
                border: 0;
                cursor: pointer;
            }
            .seo-run-actions-dropdown__item:hover { background: #f9fafb; }
            .seo-run-actions-dropdown__item:disabled { opacity: 0.5; cursor: not-allowed; }
            .dark .seo-run-actions-dropdown__item { color: #e5e7eb; }
            .dark .seo-run-actions-dropdown__item:hover { background: rgb(31 41 55); }
            .seo-run-actions-dropdown__item--warning { color: #b45309; }
            .seo-run-actions-dropdown__item--warning:hover { background: #fffbeb; }
            .dark .seo-run-actions-dropdown__item--warning { color: #fbbf24; }
            .dark .seo-run-actions-dropdown__item--warning:hover { background: rgba(245, 158, 11, 0.1); }
            a.seo-run-actions-dropdown__item { text-decoration: none; }
            .seo-run-retry-badge {
                position: absolute;
                top: -0.35rem;
                right: -0.35rem;
                min-width: 1.1rem;
                height: 1.1rem;
                padding: 0 0.25rem;
                border-radius: 9999px;
                background: #2563eb;
                color: #fff;
                font-size: 0.68rem;
                font-weight: 700;
                line-height: 1.1rem;
                text-align: center;
                box-shadow: 0 0 0 2px #fff;
            }
            .dark .seo-run-retry-badge { box-shadow: 0 0 0 2px rgb(17 24 39); }
            [x-cloak] { display: none !important; }
        </style>
    @endpush

    <div
        class="space-y-6"
        data-seo-run-queue
        x-data="seoProjectRunQueue(@js($queueBootstrap))"
        @seo-run-archive="archiveTaskRow($event.detail)"
        @seo-run-mark-running="markRowRunning($event.detail)"
        @seo-run-start-queue="handleStartQueue($event.detail)"
    >
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_mode') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                    {{ $this->projectRun->isTestMode()
                        ? __('seo-content-ai::filament.projects.run_mode_test')
                        : __('seo-content-ai::filament.projects.run_mode_full') }}
                </p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">Engine</p>
                <p class="mt-1">
                    @php
                        $engineLabel = (string) ($queueBootstrap['engineLabel'] ?? 'Legacy');
                        $isPhpEngine = ($queueBootstrap['orchestration'] ?? '') === 'php';
                    @endphp
                    <span @class([
                        'inline-flex items-center rounded-md px-2 py-0.5 text-sm font-semibold',
                        'bg-info-50 text-info-700 ring-1 ring-inset ring-info-600/20 dark:bg-info-400/10 dark:text-info-400' => $isPhpEngine,
                        'bg-gray-100 text-gray-700 ring-1 ring-inset ring-gray-500/20 dark:bg-gray-500/10 dark:text-gray-300' => ! $isPhpEngine,
                    ])>Engine: {{ $engineLabel }}</span>
                </p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_total') }}</p>
                <p class="mt-1 text-lg font-semibold text-gray-950 dark:text-white" data-run-stat="total">{{ (int) $runStats['total'] }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_succeeded') }}</p>
                <p class="mt-1 text-lg font-semibold text-success-600 dark:text-success-400" data-run-stat="succeeded">{{ (int) $runStats['succeeded'] }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_failed_count') }}</p>
                <p class="mt-1 text-lg font-semibold text-danger-600 dark:text-danger-400" data-run-stat="failed">{{ (int) $runStats['failed'] }}</p>
            </x-filament::section>

            <x-filament::section>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('seo-content-ai::filament.projects.run_pending_count') }}</p>
                <p class="mt-1 text-lg font-semibold text-warning-600 dark:text-warning-400" data-run-stat="pending">{{ (int) $runStats['pending'] }}</p>
            </x-filament::section>
        </div>

        <x-filament::section>
            <x-slot name="heading">
                {{ __('seo-content-ai::filament.projects.run_items_heading') }}
            </x-slot>
            <x-slot name="description">{{ __('seo-content-ai::filament.projects.run_items_description') }}</x-slot>

            <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
                @if ($this->canSyncAllItems())
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg bg-success-600 px-3 py-1.5 text-sm font-semibold text-white shadow-sm hover:bg-success-500 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-success-500 dark:hover:bg-success-400"
                        x-cloak
                        x-show="!$store.seoRunQueue.isRunning && !syncAllBusy"
                        x-on:click.stop="openSyncAllConfirm()"
                    >
                        <x-filament::icon icon="heroicon-o-cloud-arrow-up" class="h-4 w-4" />
                        <span>{{ __('seo-content-ai::filament.projects.run_sync_all') }}</span>
                    </button>
                @endif
                <button
                    type="button"
                    class="seo-run-stop-button inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-60"
                    x-cloak
                    x-show="(config.engineUiRunning === true) || config.runStatus === 'running' || config.runStatus === 'stopping' || ($store.seoRunQueue.isRunning && !['completed','cancelled','failed'].includes(String(config.runStatus || '')))"
                    x-on:click.stop="forceStopRunQueue()"
                    x-bind:disabled="$store.seoRunQueue.stopRequested || forceStopBusy"
                >
                    <x-filament::icon icon="heroicon-o-stop" class="h-4 w-4" />
                    <span x-show="!$store.seoRunQueue.stopRequested && !forceStopBusy">{{ __('seo-content-ai::filament.projects.run_stop') }}</span>
                    <span x-show="$store.seoRunQueue.stopRequested || forceStopBusy" x-cloak>{{ __('seo-content-ai::filament.projects.run_stopping') }}</span>
                </button>
            </div>

            <div
                class="mb-3 flex flex-wrap items-start gap-3 rounded-lg border border-primary-200 bg-primary-50 px-3 py-2 text-sm dark:border-primary-500/30 dark:bg-primary-500/10"
                x-cloak
                x-show="selectedTaskIds.length > 0"
            >
                <span class="pt-1.5 font-medium text-primary-800 dark:text-primary-200" x-text="bulkSelectedLabel()"></span>
                <div class="flex min-w-0 flex-1 flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex flex-col items-start rounded-md bg-white px-2.5 py-1.5 text-left text-xs font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-600"
                        x-bind:disabled="bulkBusy || !canBulkAction('regenerate_outline')"
                        x-on:click="openBulkRerunPreview('regenerate_outline')"
                        x-bind:title="!canBulkAction('regenerate_outline') ? 'Workflow thiếu role outline' : ''"
                    >
                        <span x-text="config.labels?.bulkActionOutline ?? 'Tạo lại dàn ý'"></span>
                        <span class="mt-0.5 font-normal text-[11px] text-gray-500 dark:text-gray-400" x-text="config.labels?.bulkActionOutlineHelp ?? 'Chạy lại node outline. Không chạy lại bài viết.'"></span>
                    </button>
                    <button
                        type="button"
                        class="inline-flex flex-col items-start rounded-md bg-white px-2.5 py-1.5 text-left text-xs font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-600"
                        x-bind:disabled="bulkBusy || !canBulkAction('regenerate_article')"
                        x-on:click="openBulkRerunPreview('regenerate_article')"
                        x-bind:title="!canBulkAction('regenerate_article') ? 'Workflow thiếu role viết bài' : ''"
                    >
                        <span x-text="config.labels?.bulkActionArticle ?? 'Tạo lại bài từ dàn ý'"></span>
                        <span class="mt-0.5 font-normal text-[11px] text-gray-500 dark:text-gray-400" x-text="config.labels?.bulkActionArticleHelp ?? 'Dùng dàn ý hiện tại, chỉ chạy node viết bài.'"></span>
                    </button>
                    <button
                        type="button"
                        class="inline-flex flex-col items-start rounded-md bg-white px-2.5 py-1.5 text-left text-xs font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-600"
                        x-bind:disabled="bulkBusy || !canBulkAction('regenerate_outline_and_article')"
                        x-on:click="openBulkRerunPreview('regenerate_outline_and_article')"
                        x-bind:title="!canBulkAction('regenerate_outline_and_article') ? 'Workflow thiếu role outline hoặc viết bài' : ''"
                    >
                        <span x-text="config.labels?.bulkActionOutlineAndArticle ?? 'Tạo lại dàn ý và bài viết'"></span>
                        <span class="mt-0.5 font-normal text-[11px] text-gray-500 dark:text-gray-400" x-text="config.labels?.bulkActionOutlineAndArticleHelp ?? 'Tạo outline mới rồi viết bài từ artifact đó.'"></span>
                    </button>
                    <button
                        type="button"
                        class="inline-flex flex-col items-start rounded-md bg-white px-2.5 py-1.5 text-left text-xs font-semibold text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-gray-900 dark:text-gray-200 dark:ring-gray-600"
                        x-bind:disabled="bulkBusy || !(config.genericPickerSteps || []).length"
                        x-on:click="openGenericStepPicker()"
                        x-bind:title="(config.genericPickerSteps || []).length ? 'Chọn bước khác từ workflow' : 'Không có bước generic'"
                    >
                        <span x-text="config.labels?.bulkActionGenericStep ?? 'Chạy lại bước...'"></span>
                        <span class="mt-0.5 font-normal text-[11px] text-gray-500 dark:text-gray-400">Chỉ bước này, không chạy bước sau</span>
                    </button>
                </div>
            </div>

            <div class="seo-run-items-wrap overflow-visible">
                <table class="w-full table-fixed text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <tr>
                            <th class="w-10 px-2 py-2">
                                <input
                                    type="checkbox"
                                    class="rounded border-gray-300 text-primary-600"
                                    x-bind:checked="allVisibleSelected()"
                                    x-on:change="toggleSelectAll($event.target.checked)"
                                >
                            </th>
                            <th class="w-10 px-3 py-2">#</th>
                            <th class="w-36 px-3 py-2">{{ __('seo-content-ai::filament.projects.article_type') }}</th>
                            <th class="px-3 py-2">{{ __('seo-content-ai::filament.projects.keyword') }}</th>
                            <th class="w-28 px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_status') }}</th>
                            <th class="w-32 px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_last_saved') }}</th>
                            <th class="w-40 px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_message') }}</th>
                            <th class="w-32 px-3 py-2">{{ __('seo-content-ai::filament.projects.run_item_run_at') }}</th>
                            <th class="w-24 px-2 py-2 text-right">{{ __('seo-content-ai::filament.projects.run_item_actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($this->getAllItems() as $index => $item)
                            @php
                                $taskId = (int) ($item['task_id'] ?? 0);
                                $runItemId = (int) ($item['run_item_id'] ?? 0);
                                $rowKey = (string) ($item['id'] ?? ($runItemId > 0 ? 'run-item-'.$runItemId : 'run-row-'.($taskId > 0 ? $taskId : $index)));
                                $itemStatus = (string) ($item['status'] ?? '');
                                $articleId = (int) ($item['article_id'] ?? 0);
                                $retryCount = (int) ($item['retry_count'] ?? 0);
                                $isApproved = (bool) ($item['article_is_approved'] ?? false);
                                $reviewStatus = (string) ($item['article_review_status'] ?? '');
                                $taskExists = (bool) ($item['task_exists'] ?? true);
                                $canRetry = $this->canRetryRunItem($item);
                                $runIsTerminal = in_array((string) ($this->projectRun?->status ?? ''), [
                                    \Omnichannel\Addons\ContentProjects\Models\SeoProjectRun::STATUS_COMPLETED,
                                    \Omnichannel\Addons\ContentProjects\Models\SeoProjectRun::STATUS_CANCELLED,
                                    \Omnichannel\Addons\ContentProjects\Models\SeoProjectRun::STATUS_FAILED,
                                ], true);
                            @endphp
                            <tr
                                class="align-top {{ in_array($itemStatus, ['pending', 'manual'], true) ? 'bg-warning-50/40 dark:bg-warning-500/5' : '' }}"
                                wire:key="{{ $rowKey }}"
                                @if ($taskId > 0 && $taskExists)
                                    data-run-task-id="{{ $taskId }}"
                                    x-show="isRowVisible({{ $taskId }})"
                                    x-cloak
                                @endif
                                data-run-item-status="{{ $itemStatus }}"
                            >
                                <td class="px-2 py-3">
                                    @if ($taskId > 0 && $taskExists && ! $this->itemIsImproveType($item))
                                        <input
                                            type="checkbox"
                                            class="rounded border-gray-300 text-primary-600"
                                            value="{{ $taskId }}"
                                            x-model.number="selectedTaskIds"
                                        >
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">{{ $index + 1 }}</td>
                                <td class="px-3 py-3">
                                    {{ $this->itemTypeLabel($item) }}
                                </td>
                                <td class="px-3 py-3 font-medium text-gray-950 dark:text-white">
                                    <div class="min-w-0 wrap-break-word">
                                        @if (! $taskExists)
                                            <div class="mb-1 text-xs font-normal text-warning-600 dark:text-warning-400">
                                                Task gốc không còn tồn tại — chỉ xem lịch sử.
                                            </div>
                                        @elseif (! empty($item['duplicate_identity_detected']))
                                            <div class="mb-1 text-xs font-normal text-warning-600 dark:text-warning-400">
                                                Trùng identity với task khác (ID khác).
                                            </div>
                                        @elseif (! empty($item['is_legacy']))
                                            <div class="mb-1 text-xs font-normal text-gray-500">Legacy run</div>
                                        @endif
                                        @if ($editUrl = $this->itemKeywordEditUrl($item))
                                            <a
                                                href="{{ $editUrl }}"
                                                class="text-primary-600 hover:underline dark:text-primary-400"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                {{ $this->itemKeywordLabel($item) }}
                                            </a>
                                        @else
                                            <span>{{ $this->itemKeywordLabel($item) }}</span>
                                            @if ($itemStatus === 'success' && (int) ($item['article_id'] ?? 0) > 0 && ! (bool) ($item['article_editor_ready'] ?? true))
                                                <p
                                                    class="mt-1 text-xs font-normal text-warning-700 dark:text-warning-400"
                                                    data-run-article-preparing="{{ (int) ($item['article_id'] ?? 0) }}"
                                                >
                                                    {{ $item['article_editor_preparing_message'] ?? __('seo-content-ai::filament.projects.article_editor_preparing_body') }}
                                                </p>
                                            @endif
                                        @endif

                                        @if ($rewriteNotes = $this->itemRewriteNotes($item))
                                            <p class="mt-1 max-w-xl text-xs font-normal leading-5 text-gray-500 dark:text-gray-400">
                                                {{ __('seo-content-ai::filament.projects.rewrite_notes') }}:
                                                {{ $rewriteNotes }}
                                            </p>
                                        @endif

                                        @if (filled($item['loai_san_pham'] ?? null))
                                            <p class="mt-1 max-w-xl text-xs font-normal leading-5 text-gray-500 dark:text-gray-400">
                                                {{ __('seo-content-ai::filament.projects.loai_san_pham') }}:
                                                {{ $item['loai_san_pham'] }}
                                            </p>
                                        @endif

                                        @if (filled($item['gallery_description'] ?? null))
                                            <p class="mt-1 max-w-xl text-xs font-normal leading-5 text-gray-500 dark:text-gray-400">
                                                {{ $item['gallery_description'] }}
                                            </p>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-3" data-run-status>
                                    @php
                                        $rowLabel = (string) ($item['row_status_label'] ?? '');
                                        $rowCode = (string) ($item['row_status_code'] ?? '');
                                        $rowTooltip = (string) ($item['row_status_tooltip'] ?? '');
                                    @endphp
                                    @if ($rowLabel !== '')
                                        <span
                                            class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium
                                                {{ $rowCode === 'running' ? 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' : '' }}
                                                {{ $rowCode === 'failed' ? 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' : '' }}
                                                {{ $rowCode === 'completed' ? 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400' : '' }}
                                                {{ $rowCode === 'manual_edit' ? 'bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400' : '' }}
                                                {{ $rowCode === 'ignored_stale' ? 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200' : '' }}
                                                {{ in_array($rowCode, ['pending', ''], true) ? 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' : '' }}
                                            "
                                            @if ($rowTooltip !== '') title="{{ $rowTooltip }}" @endif
                                        >
                                            {{ $rowLabel }}
                                        </span>
                                    @elseif ($itemStatus === 'success')
                                        <span class="inline-flex rounded-md bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                            OK
                                        </span>
                                    @elseif ($itemStatus === 'pending')
                                        <span class="inline-flex rounded-md bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                                            {{ __('seo-content-ai::filament.projects.run_item_pending') }}
                                        </span>
                                    @elseif ($itemStatus === 'manual')
                                        <span class="inline-flex rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-500/10 dark:text-gray-300">
                                            {{ __('seo-content-ai::filament.projects.run_item_manual') }}
                                        </span>
                                    @else
                                        <span class="inline-flex rounded-md bg-danger-50 px-2 py-0.5 text-xs font-medium text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
                                            {{ __('seo-content-ai::filament.projects.run_item_failed') }}
                                        </span>
                                    @endif
                                    @php
                                        $busySteps = collect($item['workflow_steps'] ?? [])
                                            ->filter(static fn (array $step): bool => (bool) ($step['busy'] ?? false) && ! $runIsTerminal)
                                            ->values();
                                    @endphp
                                        @foreach ($busySteps as $busyStep)
                                            <div class="mt-1 text-[11px] text-warning-700 dark:text-warning-400" data-run-busy-step>
                                                {{ $busyStep['label'] ?? '' }}: Đang chạy
                                                <button
                                                    type="button"
                                                    class="ml-1 underline"
                                                    @click="
                                                        const root = $el.closest('[data-seo-run-queue]');
                                                        const queue = root ? Alpine.$data(root) : null;
                                                        if (queue && typeof queue.cancelWorkflowStep === 'function') {
                                                            queue.cancelWorkflowStep({{ (int) ($item['task_id'] ?? 0) }}, @js($busyStep['node_id'] ?? ''));
                                                        }
                                                    "
                                                >Ngắt</button>
                                            </div>
                                        @endforeach
                                </td>
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300">
                                    <div title="{{ $item['last_saved_tooltip'] ?? ($item['last_saved_source_label'] ?? '') }}">
                                        <div>{{ $item['last_saved_display'] ?? '—' }}</div>
                                        @if (! empty($item['last_saved_source_label']))
                                            <div class="text-[11px] text-gray-400">{{ $item['last_saved_source_label'] }}</div>
                                        @endif
                                    </div>
                                </td>
                                <td class="max-w-[10rem] px-3 py-3 text-gray-600 dark:text-gray-300" data-run-message>
                                    @php
                                        $noteText = '';
                                        if ($itemStatus === 'failed') {
                                            $noteText = $this->displayItemError($item);
                                        } elseif ($itemStatus === 'pending') {
                                            $noteText = __('seo-content-ai::filament.projects.run_item_pending_hint');
                                        } elseif ($itemStatus === 'manual') {
                                            $noteText = (string) ($item['message'] ?? __('seo-content-ai::filament.projects.run_item_manual_hint'));
                                        } else {
                                            $noteText = (string) ($item['message'] ?? '');
                                        }
                                    @endphp
                                    <div class="line-clamp-2 wrap-break-word" title="{{ $noteText }}">
                                        @if ($itemStatus === 'failed')
                                            <span class="font-medium text-danger-600 dark:text-danger-400">{{ $noteText }}</span>
                                        @else
                                            {{ $noteText }}
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-gray-600 dark:text-gray-300" data-run-last-run>
                                    {{ $this->itemLastRunAt($item) }}
                                </td>
                                <td class="relative w-24 px-2 py-3 text-right seo-run-row-actions" data-run-actions>
                                    @php
                                        $canArchiveItem = $this->canArchiveRunItem($item);
                                        $stepsUrl = $this->itemStepsUrl($item);
                                        $showMarkFixed = $itemStatus === 'failed' && $articleId > 0 && $taskExists
                                            && ! $this->itemIsImproveType($item);
                                        $showFirstRun = $canRetry && ! $isApproved && $itemStatus === 'pending'
                                            && ! $this->itemIsImproveType($item);
                                        $hasRowActions = $canArchiveItem || filled($stepsUrl) || $showMarkFixed || $showFirstRun
                                            || ($canRetry && ! $isApproved && ! $this->itemIsImproveType($item)
                                                && is_array($item['workflow_steps'] ?? null) && ($item['workflow_steps'] ?? []) !== []);
                                    @endphp

                                    @if (($taskId > 0 || filled($stepsUrl)) && $hasRowActions)
                                        <div
                                            class="seo-run-actions-menu relative inline-block text-left"
                                            x-data="{ open: false }"
                                            x-bind:class="open ? 'z-20' : ''"
                                            @keydown.escape.window="open = false"
                                            @scroll.window="open = false"
                                        >
                                            <button
                                                type="button"
                                                class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-500 ring-1 ring-gray-300 transition hover:bg-gray-50 hover:text-gray-700 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-800"
                                                @click="open = ! open"
                                                :aria-expanded="open"
                                                title="{{ __('seo-content-ai::filament.projects.more_actions') }}"
                                            >
                                                <x-filament::icon icon="heroicon-m-ellipsis-vertical" class="h-4 w-4" />
                                                <span
                                                    class="seo-run-retry-badge"
                                                    data-run-retry-badge
                                                    @if ($retryCount > 0)
                                                        title="{{ __('seo-content-ai::filament.projects.run_item_rerun_badge_tooltip', ['count' => $retryCount]) }}"
                                                    @else
                                                        style="display: none;"
                                                    @endif
                                                >{{ $retryCount }}</span>
                                            </button>

                                            <div
                                                x-show="open"
                                                x-cloak
                                                x-transition
                                                class="seo-run-actions-dropdown"
                                                @click.outside="open = false"
                                            >
                                                @if ($canArchiveItem)
                                                    <button
                                                        type="button"
                                                        class="seo-run-actions-dropdown__item seo-run-actions-dropdown__item--warning"
                                                        @click="
                                                            open = false;
                                                            const root = $el.closest('[data-seo-run-queue]');
                                                            const queue = root ? Alpine.$data(root) : null;
                                                            if (queue && typeof queue.archiveTaskRow === 'function') {
                                                                queue.archiveTaskRow({{ $taskId }});
                                                            }
                                                        "
                                                    >
                                                        {{ __('seo-content-ai::filament.projects.archive_item') }}
                                                    </button>
                                                @endif

                                                @if ($showFirstRun)
                                                    <button
                                                        type="button"
                                                        class="seo-run-actions-dropdown__item"
                                                        @click="
                                                            open = false;
                                                            const root = $el.closest('[data-seo-run-queue]');
                                                            const queue = root ? Alpine.$data(root) : null;
                                                            if (! queue || typeof queue.runSingleTask !== 'function') {
                                                                window.alert('Queue UI chưa sẵn sàng — Ctrl+F5 rồi thử lại.');
                                                                return;
                                                            }
                                                            queue.runSingleTask({{ $taskId }});
                                                        "
                                                    >
                                                        {{ __('seo-content-ai::filament.projects.run_run_item') }}
                                                    </button>
                                                @endif

                                                @if ($stepsUrl)
                                                    <a
                                                        href="{{ $stepsUrl }}"
                                                        target="_blank"
                                                        rel="noopener"
                                                        class="seo-run-actions-dropdown__item"
                                                        @click="open = false"
                                                    >
                                                        {{ __('seo-content-ai::filament.projects.run_view_steps') }}
                                                    </a>
                                                @endif

                                                @php
                                                    $workflowSteps = is_array($item['workflow_steps'] ?? null) ? $item['workflow_steps'] : [];
                                                    $showStepRetry = $canRetry && ! $isApproved && ! $this->itemIsImproveType($item)
                                                        && $workflowSteps !== [];
                                                @endphp
                                                @if ($showStepRetry)
                                                    <div class="border-t border-gray-100 px-3 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-400 dark:border-gray-700">
                                                        {{ __('seo-content-ai::filament.projects.run_retry_item') }}
                                                    </div>
                                                    @foreach ($workflowSteps as $step)
                                                        @php
                                                            $stepBusy = (bool) ($step['busy'] ?? false) && ! $runIsTerminal;
                                                            $stepLabel = (string) ($step['label'] ?? $step['title'] ?? '');
                                                            $stepMeta = $stepBusy
                                                                ? 'Đang chạy'
                                                                : (filled($step['last_finished_at'] ?? null) ? 'Lần cuối: '.$step['last_finished_at'] : '');
                                                        @endphp
                                                        @if ($stepBusy)
                                                            <button
                                                                type="button"
                                                                class="seo-run-actions-dropdown__item text-danger-600 dark:text-danger-400"
                                                                @click="
                                                                    open = false;
                                                                    const root = $el.closest('[data-seo-run-queue]');
                                                                    const queue = root ? Alpine.$data(root) : null;
                                                                    if (! queue || typeof queue.cancelWorkflowStep !== 'function') {
                                                                        window.alert('Queue UI chưa sẵn sàng — Ctrl+F5 rồi thử lại.');
                                                                        return;
                                                                    }
                                                                    queue.cancelWorkflowStep({{ $taskId }}, @js($step['node_id']));
                                                                "
                                                            >
                                                                <span class="flex items-center justify-between gap-3">
                                                                    <span>Ngắt: {{ $stepLabel }}</span>
                                                                    <span class="text-[11px] font-normal text-gray-400">{{ $stepMeta }}</span>
                                                                </span>
                                                            </button>
                                                        @else
                                                            <button
                                                                type="button"
                                                                class="seo-run-actions-dropdown__item"
                                                                @click="
                                                                    open = false;
                                                                    const root = $el.closest('[data-seo-run-queue]');
                                                                    const queue = root ? Alpine.$data(root) : null;
                                                                    if (! queue || typeof queue.retryWorkflowStep !== 'function') {
                                                                        window.alert('Queue UI chưa sẵn sàng — Ctrl+F5 rồi thử lại.');
                                                                        return;
                                                                    }
                                                                    queue.retryWorkflowStep({{ $taskId }}, @js($step['node_id']));
                                                                "
                                                            >
                                                                <span class="flex items-center justify-between gap-3">
                                                                    <span>{{ $stepLabel }}</span>
                                                                    @if ($stepMeta !== '')
                                                                        <span class="text-[11px] font-normal text-gray-400">{{ $stepMeta }}</span>
                                                                    @endif
                                                                </span>
                                                            </button>
                                                        @endif
                                                    @endforeach
                                                @endif

                                                @if ($showMarkFixed)
                                                    <button
                                                        type="button"
                                                        class="seo-run-actions-dropdown__item"
                                                        wire:click="markItemFixed({{ $taskId }}, {{ $articleId }})"
                                                        wire:confirm="Xác nhận bài viết đã được sửa lỗi thủ công?"
                                                        wire:loading.attr="disabled"
                                                        wire:target="markItemFixed({{ $taskId }}, {{ $articleId }})"
                                                        @click="open = false"
                                                    >
                                                        {{ __('seo-content-ai::filament.projects.run_mark_fixed') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">
                                    {{ __('seo-content-ai::filament.projects.run_items_empty') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Generic step picker modal (Phase 2.1 — no browser prompt) --}}
        <div
            x-show="genericStepOpen"
            x-cloak
            x-transition.opacity.duration.150ms
            class="fixed inset-0 z-[210] flex items-center justify-center p-4 sm:p-6"
            style="display: none;"
            role="dialog"
            aria-modal="true"
            aria-labelledby="seo-generic-step-title"
        >
            <div
                class="absolute inset-0 bg-gray-950/60 dark:bg-gray-950/75"
                x-on:click="closeGenericStepPicker()"
            ></div>
            <div
                class="relative z-10 w-full max-w-lg overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                x-on:click.stop
                x-on:keydown.escape.window="closeGenericStepPicker()"
            >
                <div class="border-b border-gray-200 px-6 py-4 dark:border-white/10">
                    <h3 id="seo-generic-step-title" class="text-base font-semibold text-gray-950 dark:text-white">
                        Chạy lại bước...
                    </h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Chỉ chạy lại bước này, không chạy các bước sau.
                    </p>
                </div>
                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-gray-700 dark:text-gray-200">Bước cần chạy lại</label>
                        <x-select
                            x-model="genericSelectedNodeId"
                            x-on:change="refreshGenericStepPreview()"
                            class="w-full"
                        >
                            <template x-for="step in genericPickerSteps()" :key="step.node_id">
                                <option
                                    x-bind:value="step.node_id"
                                    x-text="step.label || step.node_id"
                                ></option>
                            </template>
                        </x-select>
                        <template x-if="genericSelectedStep()?.source_requirements?.length">
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                Nguồn cần:
                                <span x-text="(genericSelectedStep()?.source_requirements || []).join(', ')"></span>
                            </p>
                        </template>
                    </div>

                    <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-700 dark:border-white/10 dark:bg-white/5 dark:text-gray-200">
                        <p>Chế độ: Chỉ chạy bước này</p>
                        <template x-if="genericStepLoading">
                            <p class="mt-1 animate-pulse">Đang kiểm tra...</p>
                        </template>
                        <template x-if="!genericStepLoading && genericPreview">
                            <div class="mt-1 space-y-0.5">
                                <p>Đã chọn: <span x-text="genericPreview.selected_count"></span></p>
                                <p>Hợp lệ: <span x-text="genericPreview.valid_count"></span></p>
                                <p>Không hợp lệ: <span x-text="genericPreview.invalid_count"></span></p>
                                <p>Bước: <span x-text="genericPreview.label || genericSelectedStep()?.label"></span></p>
                            </div>
                        </template>
                        <template x-if="genericPreviewError">
                            <p class="mt-2 font-medium text-danger-600 dark:text-danger-400" x-text="genericPreviewError"></p>
                        </template>
                    </div>

                    <template x-if="(genericPreview?.invalid || []).length">
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                            <p class="mb-1 font-semibold">Không thể chạy lại (một số bài):</p>
                            <ul class="list-disc space-y-0.5 pl-4">
                                <template x-for="row in (genericPreview.invalid || []).slice(0, 5)" :key="row.task_id">
                                    <li>
                                        <span x-text="row.label"></span>
                                        — <span x-text="row.reason"></span>
                                    </li>
                                </template>
                            </ul>
                            <template x-if="(genericPreview.invalid || []).length > 5">
                                <p class="mt-1" x-text="'+ ' + ((genericPreview.invalid || []).length - 5) + ' lỗi khác'"></p>
                            </template>
                        </div>
                    </template>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                    <button
                        type="button"
                        class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 outline-none transition duration-75 hover:bg-gray-100 focus-visible:ring-2 dark:text-gray-200 dark:hover:bg-white/5"
                        x-on:click="closeGenericStepPicker()"
                    >Hủy</button>
                    <button
                        type="button"
                        class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm outline-none transition duration-75 hover:bg-primary-500 focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70"
                        x-bind:disabled="bulkBusy || genericStepLoading || !(genericPreview?.can_execute)"
                        x-on:click="confirmGenericStepRerun()"
                        x-text="(genericPreview?.invalid_count > 0) ? ('Chạy ' + (genericPreview?.valid_count || 0) + ' bài hợp lệ') : 'Chạy lại bước'"
                    ></button>
                </div>
            </div>
        </div>

        {{-- Bulk retry confirm modal --}}
        <div
            x-show="bulkConfirmOpen"
            x-cloak
            x-transition.opacity.duration.150ms
            class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6"
            style="display: none;"
            role="dialog"
            aria-modal="true"
            aria-labelledby="seo-bulk-retry-title"
        >
            <div
                class="absolute inset-0 bg-gray-950/60 dark:bg-gray-950/75"
                x-on:click="bulkConfirmOpen = false"
            ></div>
            <div
                class="relative z-10 w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                x-on:click.stop
                x-on:keydown.escape.window="bulkConfirmOpen = false"
            >
                <div class="border-b border-gray-200 px-6 py-4 dark:border-white/10">
                    <h3
                        id="seo-bulk-retry-title"
                        class="text-base font-semibold text-gray-950 dark:text-white"
                        x-text="config.labels?.bulkConfirmHeading ?? 'Xác nhận chạy lại prompt'"
                    ></h3>
                </div>
                <div class="space-y-3 px-6 py-5">
                    <p class="text-sm leading-6 text-gray-600 dark:text-gray-300" x-text="bulkConfirmText()"></p>
                    <template x-if="bulkPreview?.outline_node_title || bulkPreview?.article_node_title">
                        <ul class="list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-200">
                            <template x-if="bulkPreview?.outline_node_title">
                                <li>Outline: <span x-text="bulkPreview.outline_node_title"></span></li>
                            </template>
                            <template x-if="bulkPreview?.article_node_title">
                                <li>Bài viết: <span x-text="bulkPreview.article_node_title"></span></li>
                            </template>
                        </ul>
                    </template>
                    <template x-if="(bulkPreview?.invalid || []).length">
                        <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                            <p class="mb-1 font-semibold">Không hợp lệ:</p>
                            <ul class="list-disc space-y-0.5 pl-4">
                                <template x-for="row in (bulkPreview.invalid || []).slice(0, 8)" :key="row.task_id">
                                    <li>
                                        <span x-text="row.label"></span>
                                        — <span x-text="row.reason"></span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                    <button
                        type="button"
                        class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 outline-none transition duration-75 hover:bg-gray-100 focus-visible:ring-2 dark:text-gray-200 dark:hover:bg-white/5"
                        x-on:click="bulkConfirmOpen = false"
                        x-text="config.labels?.runSettingsCancel ?? 'Hủy'"
                    ></button>
                    <button
                        type="button"
                        class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm outline-none transition duration-75 hover:bg-primary-500 focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70"
                        x-bind:disabled="bulkBusy || !(bulkPreview?.can_execute)"
                        x-on:click="confirmBulkRetry()"
                        x-text="config.labels?.bulkExecute ?? 'Thực hiện'"
                    ></button>
                </div>
            </div>
        </div>

        {{-- Sync all confirm modal --}}
        <div
            x-show="syncAllOpen"
            x-cloak
            x-transition.opacity.duration.150ms
            class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6"
            style="display: none;"
            role="dialog"
            aria-modal="true"
            aria-labelledby="seo-sync-all-title"
        >
            <div
                class="absolute inset-0 bg-gray-950/60 dark:bg-gray-950/75"
                x-on:click="syncAllOpen = false"
            ></div>
            <div
                class="relative z-10 w-full max-w-md overflow-hidden rounded-xl bg-white shadow-xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
                x-on:click.stop
                x-on:keydown.escape.window="syncAllOpen = false"
            >
                <div class="border-b border-gray-200 px-6 py-4 dark:border-white/10">
                    <h3
                        id="seo-sync-all-title"
                        class="text-base font-semibold text-gray-950 dark:text-white"
                        x-text="config.labels?.syncAllConfirmHeading ?? 'Sync all completed articles?'"
                    ></h3>
                </div>
                <div class="px-6 py-5">
                    <p
                        class="text-sm leading-6 text-gray-600 dark:text-gray-300"
                        x-text="config.labels?.syncAllConfirmBody ?? ''"
                    ></p>
                </div>
                <div class="flex items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-white/10 dark:bg-white/5">
                    <button
                        type="button"
                        class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg px-3 py-2 text-sm font-semibold text-gray-700 outline-none transition duration-75 hover:bg-gray-100 focus-visible:ring-2 dark:text-gray-200 dark:hover:bg-white/5"
                        x-on:click="syncAllOpen = false"
                        x-text="config.labels?.syncAllCancel ?? 'Cancel'"
                    ></button>
                    <button
                        type="button"
                        class="fi-btn relative grid-flow-col items-center justify-center gap-1.5 rounded-lg bg-success-600 px-3 py-2 text-sm font-semibold text-white shadow-sm outline-none transition duration-75 hover:bg-success-500 focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70 dark:bg-success-500 dark:hover:bg-success-400"
                        x-bind:disabled="syncAllBusy"
                        x-on:click="confirmSyncAll()"
                    >
                        <span x-text="config.labels?.syncAll ?? 'Sync all'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        @vite('addons/content-projects/resources/js/project-run-queue.js')
    @endpush
</x-filament-panels::page>

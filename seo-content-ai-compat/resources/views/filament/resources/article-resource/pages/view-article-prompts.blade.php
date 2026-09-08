<x-filament-panels::page>
    @vite([
        'addons/content-projects/resources/css/project-run-step.css',
        'addons/content/resources/js/article-execution-history.jsx',
    ])

    @php
        $groups = $this->getAiCallGroups();
        $executionRuns = $activeTab === 'workflow' ? $this->getExecutionRuns() : [];
        $promptsForCanvas = $activeTab === 'workflow' ? $this->getPromptsForWorkflowCanvas() : [];
        $articleId = $this->getArticleId();
        $articleEditUrl = $this->getArticleEditUrl();
        $articleTitle = trim((string) ($this->articleRecord?->title ?? ''));
        $selectedCount = count($this->selectedRefs);
        $hasPendingConfirm = filled($this->pendingConfirmAction);
        $executionHistoryProps = [
            'runs' => $executionRuns,
            'prompts' => $promptsForCanvas,
            'labels' => [
                'emptyWorkflow' => __('seo-content-ai::filament.article_ai_history.empty_workflow'),
                'legacyUnmapped' => __('seo-content-ai::filament.article_ai_history.legacy_unmapped'),
                'legacyDefinition' => __('seo-content-ai::filament.article_ai_history.legacy_workflow_definition'),
                'selectNode' => __('seo-content-ai::filament.article_ai_history.select_node'),
                'inspectorHeading' => __('seo-content-ai::filament.article_ai_history.inspector_heading'),
                'prompt' => __('seo-content-ai::filament.article_ai_history.inspector_type_prompt'),
                'action' => __('seo-content-ai::filament.article_ai_history.inspector_type_action'),
                'filter' => __('seo-content-ai::filament.article_ai_history.inspector_type_filter'),
                'aiCalls' => __('seo-content-ai::filament.article_ai_history.ai_calls_count'),
                'currentArticle' => __('seo-content-ai::filament.article_ai_history.current_article'),
                'articleContext' => __('seo-content-ai::filament.article_ai_history.context_inspector_heading'),
                'showFullWorkflow' => __('seo-content-ai::filament.article_ai_history.show_full_workflow'),
                'simplifiedWorkflow' => __('seo-content-ai::filament.article_ai_history.simplified_workflow'),
                'contextInspectorHeading' => __('seo-content-ai::filament.article_ai_history.context_inspector_heading'),
                'noContext' => __('seo-content-ai::filament.article_ai_history.no_context'),
                'context_articleId' => __('seo-content-ai::filament.article_ai_history.context_articleId'),
                'context_title' => __('seo-content-ai::filament.article_ai_history.context_title'),
                'context_postType' => __('seo-content-ai::filament.article_ai_history.context_postType'),
                'context_generationMode' => __('seo-content-ai::filament.article_ai_history.context_generationMode'),
                'context_keyword' => __('seo-content-ai::filament.article_ai_history.context_keyword'),
                'context_domain' => __('seo-content-ai::filament.article_ai_history.context_domain'),
                'contextRouting' => __('seo-content-ai::filament.article_ai_history.context_routing'),
                'nodeHistoryHeading' => __('seo-content-ai::filament.article_ai_history.node_history_heading'),
                'effectiveSuccess' => __('seo-content-ai::filament.article_ai_history.effective_success'),
                'emptyNodeHistory' => __('seo-content-ai::filament.article_ai_history.empty_node_history'),
            ],
        ];
    @endphp

    <script>
        window.__SEO_PROMPTS__ = @json($promptsForCanvas);
    </script>

    <div
        class="seo-run-history-page {{ $activeTab === 'workflow' ? 'seo-run-history-page--workflow-tool' : '' }}"
        x-data="{
            drawerOpen: false,
            drawerTitle: '',
            drawerPrompt: '',
            drawerResult: '',
            drawerMeta: '',
            openTwoCol(title, prompt, result, meta) {
                this.drawerTitle = title || '';
                this.drawerPrompt = prompt || '';
                this.drawerResult = result || '';
                this.drawerMeta = meta || '';
                this.drawerOpen = true;
            },
            async openRawAiCall(ref) {
                if (!ref) {
                    return;
                }
                const payload = await $wire.loadRawAiCallDetail(ref);
                if (payload?.success) {
                    this.openTwoCol(payload.title, payload.prompt, payload.output, payload.meta ?? '');
                } else {
                    this.openTwoCol('AI Call', payload?.message ?? 'Không tìm thấy AI call.', '', '');
                }
                this.drawerOpen = true;
            },
            closeDrawer() {
                this.drawerOpen = false;
            },
            async copyText(text) {
                if (!text) return;
                try {
                    await navigator.clipboard.writeText(text);
                } catch (_e) {
                    const input = document.createElement('textarea');
                    input.value = text;
                    input.style.position = 'fixed';
                    input.style.opacity = '0';
                    document.body.appendChild(input);
                    input.select();
                    document.execCommand('copy');
                    input.remove();
                }
            }
        }"
        x-on:close-ai-history-drawer.window="closeDrawer()"
        x-on:execution-history-preview.window="openRawAiCall($event.detail?.ref)"
        x-on:keydown.escape.window="if (drawerOpen) closeDrawer()"
    >
        <nav class="mb-4 flex gap-2 border-b border-gray-200 dark:border-gray-700">
            <button
                type="button"
                class="px-4 py-2 text-sm font-medium {{ $activeTab === 'ai_calls' ? 'border-b-2 border-primary-600 text-primary-600' : 'text-gray-500' }}"
                wire:click="setActiveTab('ai_calls')"
            >
                {{ __('seo-content-ai::filament.article_ai_history.tab_ai_calls') }}
            </button>
            <button
                type="button"
                class="px-4 py-2 text-sm font-medium {{ $activeTab === 'workflow' ? 'border-b-2 border-primary-600 text-primary-600' : 'text-gray-500' }}"
                wire:click="setActiveTab('workflow')"
            >
                {{ __('seo-content-ai::filament.article_ai_history.tab_workflow') }}
            </button>
        </nav>

        @if ($activeTab === 'workflow')
            <section class="seo-execution-history-workspace">
                <div
                    id="article-execution-history-root"
                    class="seo-execution-history-workflow-shell"
                    data-props='@json($executionHistoryProps)'
                    wire:ignore
                ></div>
            </section>
            <script>
                (() => {
                    const mount = () => {
                        const el = document.getElementById('article-execution-history-root');
                        if (!el || el.dataset.executionHistoryMounted === '1') {
                            return;
                        }
                        if (typeof window.mountArticleExecutionHistory !== 'function') {
                            return;
                        }
                        el.dataset.executionHistoryMounted = '1';
                        window.mountArticleExecutionHistory(el);
                    };
                    mount();
                    queueMicrotask(mount);
                    document.addEventListener('livewire:navigated', mount);
                    if (window.Livewire && typeof window.Livewire.hook === 'function') {
                        window.Livewire.hook('morph.updated', () => queueMicrotask(mount));
                    }
                })();
            </script>
        @else
        <section class="seo-run-history-summary">
            <div>
                <p class="seo-run-history-summary__eyebrow">ARTICLE #{{ $articleId }}</p>
                <h2 class="seo-run-history-summary__title">
                    {{ $articleTitle !== '' ? $articleTitle : 'Bài viết' }}
                </h2>
                <p class="seo-run-history-summary__description">
                    {{ __('seo-content-ai::filament.article_ai_history.page_description') }}
                </p>
            </div>

            @if ($articleEditUrl !== null)
                <x-filament::button
                    tag="a"
                    :href="$articleEditUrl"
                    icon="heroicon-o-pencil-square"
                    target="_blank"
                >
                    {{ __('seo-content-ai::filament.article_ai_history.back_to_article') }}
                </x-filament::button>
            @endif
        </section>

        <section class="seo-run-history-filters mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="min-w-[10rem]">
                <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.article_ai_history.filter_type') }}</label>
                <x-select wire:model.live="filterType" class="w-full">
                    <option value="all">{{ __('seo-content-ai::filament.article_ai_history.type_all') }}</option>
                    <option value="outline">{{ __('seo-content-ai::filament.article_ai_history.type_outline') }}</option>
                    <option value="content">{{ __('seo-content-ai::filament.article_ai_history.type_content') }}</option>
                    <option value="invalid">{{ __('seo-content-ai::filament.article_ai_history.type_invalid') }}</option>
                    <option value="other">{{ __('seo-content-ai::filament.article_ai_history.type_other') }}</option>
                </x-select>
            </div>
            <div class="min-w-[10rem]">
                <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.article_ai_history.filter_status') }}</label>
                <x-select wire:model.live="filterStatus" class="w-full">
                    <option value="all">{{ __('seo-content-ai::filament.article_ai_history.status_all') }}</option>
                    <option value="success">{{ __('seo-content-ai::filament.article_ai_history.status_success') }}</option>
                    <option value="error">{{ __('seo-content-ai::filament.article_ai_history.status_error') }}</option>
                    <option value="skipped">{{ __('seo-content-ai::filament.article_ai_history.status_skipped') }}</option>
                    <option value="applied">{{ __('seo-content-ai::filament.article_ai_history.status_applied') }}</option>
                    <option value="unapplied">{{ __('seo-content-ai::filament.article_ai_history.status_unapplied') }}</option>
                    <option value="deleted">{{ __('seo-content-ai::filament.article_ai_history.status_deleted') }}</option>
                </x-select>
            </div>
            <button
                type="button"
                class="fi-btn fi-btn-color-gray fi-btn-size-sm rounded-lg px-3 py-2 text-sm"
                wire:click="clearFilters"
                wire:loading.attr="disabled"
                wire:target="clearFilters"
            >
                {{ __('seo-content-ai::filament.article_ai_history.clear_filters') }}
            </button>

            @if ($selectedCount > 0)
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-500">{{ $selectedCount }} {{ __('seo-content-ai::filament.article_ai_history.selected') }}</span>
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-danger fi-btn-size-sm rounded-lg px-3 py-2 text-sm"
                        wire:click="bulkDeleteSelected(false)"
                        wire:confirm="{{ __('seo-content-ai::filament.article_ai_history.bulk_delete_confirm') }}"
                        wire:loading.attr="disabled"
                        wire:target="bulkDeleteSelected"
                    >
                        <span wire:loading.remove wire:target="bulkDeleteSelected">{{ __('seo-content-ai::filament.article_ai_history.bulk_delete') }}</span>
                        <span wire:loading wire:target="bulkDeleteSelected" class="inline-flex items-center gap-1">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        </span>
                    </button>
                    <button type="button" class="text-sm text-gray-500 underline" wire:click="clearSelection">
                        {{ __('seo-content-ai::filament.article_ai_history.clear_selection') }}
                    </button>
                </div>
            @endif
        </section>

        @if ($hasPendingConfirm)
            <section class="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/40">
                <p class="mb-3 text-sm text-amber-900 dark:text-amber-100">
                    {{ __('seo-content-ai::filament.article_ai_history.pending_confirm_banner') }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-warning fi-btn-size-sm rounded-lg px-3 py-2 text-sm"
                        wire:click="confirmPendingAction"
                        wire:loading.attr="disabled"
                        wire:target="confirmPendingAction"
                    >
                        {{ __('seo-content-ai::filament.article_ai_history.continue') }}
                    </button>
                    <button
                        type="button"
                        class="fi-btn fi-btn-color-gray fi-btn-size-sm rounded-lg px-3 py-2 text-sm"
                        wire:click="cancelPendingConfirm"
                    >
                        {{ __('seo-content-ai::filament.article_ai_history.cancel') }}
                    </button>
                </div>
            </section>
        @endif

        @forelse ($groups as $group)
            @php
                $runId = $group['run_id'] ?? null;
                $projectName = trim((string) ($group['project_name'] ?? ''));
                $ranAt = $group['ran_at'] ?? null;
                $prompts = is_array($group['prompts'] ?? null) ? $group['prompts'] : [];
                $maxAttempt = $group['max_attempt'] ?? null;
                $runStatus = strtoupper(trim((string) ($group['status'] ?? '')));
            @endphp

            <section class="seo-run-history-group" x-data="{ groupOpen: true }">
                <header class="seo-run-history-group__header cursor-pointer" x-on:click="groupOpen = ! groupOpen">
                    <div>
                        <p class="seo-run-history-group__eyebrow">
                            @if ($runId)
                                Run #{{ $runId }}
                                @if ($maxAttempt)
                                    · Attempt #{{ $maxAttempt }}
                                @endif
                            @else
                                {{ __('seo-content-ai::filament.article_ai_history.orphan_group') }}
                            @endif
                            @if ($projectName !== '')
                                · {{ $projectName }}
                            @endif
                        </p>
                        <h2 class="seo-run-history-group__title">
                            {{ count($prompts) }} {{ __('seo-content-ai::filament.article_ai_history.step_count_label') }}
                        </h2>
                    </div>

                    <div class="seo-run-history-group__meta">
                        @php
                            $groupDateLabel = \Omnichannel\Addons\Content\Support\ArticleAiHistoryCardPresenter::groupDateLabel($ranAt);
                        @endphp
                        @if ($groupDateLabel)
                            <span>{{ $groupDateLabel }}</span>
                        @endif
                        @if ($runStatus !== '')
                            <span class="seo-run-history-status">{{ $runStatus }}</span>
                        @endif
                    </div>
                </header>

                <div class="seo-run-history-items" x-show="groupOpen" x-cloak>
                    @foreach ($prompts as $index => $promptItem)
                        @php
                            $artifactRef = trim((string) ($promptItem['artifact_ref'] ?? ''));
                            $promptType = trim((string) ($promptItem['type'] ?? 'Prompt AI'));
                            $status = trim((string) ($promptItem['status'] ?? ''));
                            $hookKey = trim((string) ($promptItem['hook_key'] ?? $promptItem['execution_role'] ?? ''));
                            $artifactType = trim((string) ($promptItem['artifact_type'] ?? ''));
                            $promptText = trim((string) ($promptItem['prompt'] ?? ''));
                            $resultText = trim((string) ($promptItem['result'] ?? ''));
                            $normalized = trim((string) ($promptItem['normalized_artifact'] ?? ''));
                            $canApplyOutline = (bool) ($promptItem['can_apply_outline'] ?? false);
                            $canApplyContent = (bool) ($promptItem['can_apply_content'] ?? false);
                            $isDeleted = (bool) ($promptItem['is_deleted'] ?? false);
                            $applyCount = (int) ($promptItem['apply_count'] ?? 0);
                            $appliedLabel = trim((string) ($promptItem['applied_label'] ?? ''));
                            $executionType = trim((string) ($promptItem['execution_type'] ?? ''));
                            $model = trim((string) ($promptItem['model'] ?? $promptItem['render_model'] ?? ''));
                            $isFreeCandidate = array_key_exists('is_free_candidate', $promptItem)
                                ? (is_bool($promptItem['is_free_candidate']) ? $promptItem['is_free_candidate'] : null)
                                : null;
                            $modelCompact = \Omnichannel\Addons\Content\Support\ArticleAiHistoryCardPresenter::compactModel(
                                $model,
                                $isFreeCandidate,
                            );
                            $strategyResolved = strtolower(trim((string) (
                                $promptItem['pass_mode']
                                ?? $promptItem['generation_shape']
                                ?? $promptItem['strategy_resolved']
                                ?? $promptItem['generation_strategy']
                                ?? ''
                            )));
                            if ($strategyResolved === 'sectioned_free' || $strategyResolved === 'sectioned') {
                                $strategyResolved = 'multiple_pass';
                            }
                            $showStrategyTag = in_array($strategyResolved, ['single_pass', 'multiple_pass', 'sectioned'], true);
                            $tierTag = null;
                            if (array_key_exists('primary_is_free', $promptItem) || array_key_exists('is_free_candidate', $promptItem)) {
                                $isFree = (bool) ($promptItem['primary_is_free'] ?? $promptItem['is_free_candidate'] ?? false);
                                $tierTag = $isFree ? 'free' : 'paid';
                            }
                            $strategySource = trim((string) ($promptItem['strategy_source'] ?? ''));
                            $strategyOverrideLabel = trim((string) ($promptItem['strategy_override_label'] ?? ''));
                            $ranAtItem = $promptItem['ran_at'] ?? null;
                            $attempt = $promptItem['attempt'] ?? null;
                            $attemptMeta = \Omnichannel\Addons\Content\Support\ArticleAiHistoryCardPresenter::attemptMeta(
                                $attempt,
                                $ranAtItem,
                                $executionType !== '' ? $executionType : null,
                            );
                            $wordCountLabel = \Omnichannel\Addons\Content\Support\ArticleAiHistoryCardPresenter::wordCountLabel(
                                $promptItem['actual_word_count'] ?? null,
                            );
                            $isFailed = in_array($status, ['failed', 'error'], true);
                            $errorMessage = $isFailed
                                ? \Omnichannel\Addons\Content\Support\PromptAiCallErrorNormalizer::display($promptItem['message'] ?? null)
                                : null;
                            $lengthValidation = strtolower(trim((string) ($promptItem['length_validation_result'] ?? '')));
                            $errorCode = ($isFailed && $lengthValidation === 'truncated') ? 'OUTPUT_TRUNCATED' : null;
                            if ($errorCode !== null && is_string($errorMessage) && str_contains(strtoupper($errorMessage), 'OUTPUT_TRUNCATED')) {
                                $errorCode = null;
                            }
                            $selected = in_array($artifactRef, $this->selectedRefs, true);
                            $metaParts = array_values(array_filter([
                                $attemptMeta['attempt_label'],
                                $attemptMeta['time_label'],
                            ]));
                            $metaLine = implode(' · ', $metaParts);
                        @endphp

                        <div class="seo-run-history-item">
                            <div class="seo-run-history-item__row">
                                @if ($artifactRef !== '' && ! $isDeleted)
                                    <input
                                        type="checkbox"
                                        class="seo-run-history-item__check"
                                        @checked($selected)
                                        wire:click="toggleSelect({{ \Illuminate\Support\Js::from($artifactRef) }})"
                                    />
                                @endif
                                <div class="seo-run-history-item__toggle flex-1 pointer-events-none">
                                    <div class="seo-run-history-item__identity">
                                        <span class="seo-run-history-item__index">{{ $index + 1 }}</span>
                                        <div class="seo-run-history-item__copy min-w-0 flex-1">
                                            <div class="seo-run-history-item__title-row">
                                                <span class="seo-run-history-item__type">{{ $promptType }}</span>
                                            </div>

                                            @if ($attemptMeta['is_retry'] || $metaLine !== '')
                                                <p class="seo-run-history-item__meta">
                                                    @if ($attemptMeta['is_retry'])
                                                        <span class="seo-run-history-item__tag">Retry</span>
                                                    @endif
                                                    @if ($metaLine !== '')
                                                        <span>{{ $metaLine }}</span>
                                                    @endif
                                                </p>
                                            @endif

                                            @if ($showStrategyTag)
                                                <p class="seo-run-history-item__meta">
                                                    <span class="seo-run-history-item__tag">{{ $strategyResolved }}</span>
                                                    @if ($tierTag !== null)
                                                        <span class="seo-run-history-item__tag">{{ $tierTag }}</span>
                                                    @endif
                                                </p>
                                                <p class="seo-run-history-item__meta" title="Strategy provenance">
                                                    Shape: {{ $strategyResolved }}
                                                    @if ($tierTag !== null)
                                                        · Tier: {{ $tierTag }}
                                                    @endif
                                                    · Source: {{ $strategySource !== '' ? $strategySource : 'ai_center_primary_candidate' }}
                                                </p>
                                            @endif

                                            @if ($modelCompact !== '')
                                                <p class="seo-run-history-item__model" title="{{ $model }}">{{ $modelCompact }}</p>
                                            @endif

                                            @if (! $isFailed && $wordCountLabel !== null)
                                                <p class="seo-run-history-item__meta">{{ $wordCountLabel }}</p>
                                            @endif

                                            @if ($applyCount > 0)
                                                <p class="seo-run-history-item__meta">APPLIED · {{ $appliedLabel !== '' ? $appliedLabel : $applyCount }}</p>
                                            @endif

                                            @if ($isDeleted)
                                                <p class="seo-run-history-item__meta">DELETED</p>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($status !== '')
                                        <span class="seo-run-history-item__actions">
                                            <span class="seo-run-history-status {{ $isFailed ? 'is-failed' : '' }}">
                                                {{ strtoupper($status) }}
                                            </span>
                                        </span>
                                    @endif
                                </div>
                            </div>

                            @php
                                $previewResult = $normalized !== '' ? $normalized : $resultText;
                                $previewMeta = trim(implode(' · ', array_filter([
                                    filled($promptItem['message'] ?? null) ? (string) $promptItem['message'] : null,
                                    ($promptItem['has_raw_prompt'] ?? false) ? 'RENDERED PROMPT' : null,
                                    ($promptItem['has_raw_output'] ?? false) ? 'RAW OUTPUT' : null,
                                    ($promptItem['has_normalized_artifact'] ?? false) ? 'NORMALIZED ARTIFACT' : null,
                                ])));
                                $drawerTitle = $promptType.($artifactType !== '' ? ' · '.$artifactType : '');
                            @endphp

                            <div class="seo-run-history-item__actions-row">
                                @if (! $isDeleted)
                                    <button
                                        type="button"
                                        class="fi-btn fi-btn-color-gray fi-btn-size-sm rounded-lg px-2 py-1 text-xs"
                                        x-on:click="openTwoCol(
                                            {{ \Illuminate\Support\Js::from($drawerTitle) }},
                                            {{ \Illuminate\Support\Js::from($promptText) }},
                                            {{ \Illuminate\Support\Js::from($previewResult) }},
                                            {{ \Illuminate\Support\Js::from($previewMeta) }}
                                        )"
                                    >
                                        {{ __('seo-content-ai::filament.article_ai_history.preview') }}
                                    </button>

                                    @if ($canApplyOutline)
                                        <button
                                            type="button"
                                            class="fi-btn fi-btn-color-primary fi-btn-size-sm rounded-lg px-2 py-1 text-xs"
                                            wire:click="applyOutline({{ \Illuminate\Support\Js::from($artifactRef) }}, false)"
                                            wire:confirm="{{ __('seo-content-ai::filament.article_ai_history.apply_outline_confirm') }}"
                                            wire:loading.attr="disabled"
                                            wire:target="applyOutline"
                                        >
                                            {{ __('seo-content-ai::filament.article_ai_history.apply_outline') }}
                                        </button>
                                    @endif

                                    @if ($canApplyContent)
                                        <button
                                            type="button"
                                            class="fi-btn fi-btn-color-primary fi-btn-size-sm rounded-lg px-2 py-1 text-xs"
                                            wire:click="applyContent({{ \Illuminate\Support\Js::from($artifactRef) }}, false)"
                                            wire:confirm="{{ __('seo-content-ai::filament.article_ai_history.apply_content_confirm') }}"
                                            wire:loading.attr="disabled"
                                            wire:target="applyContent"
                                        >
                                            {{ __('seo-content-ai::filament.article_ai_history.apply_content') }}
                                        </button>
                                    @elseif (filled($promptItem['apply_block_reason'] ?? null) && str_contains($hookKey, 'article.content'))
                                        <button
                                            type="button"
                                            class="fi-btn fi-btn-color-gray fi-btn-size-sm rounded-lg px-2 py-1 text-xs opacity-60 cursor-not-allowed"
                                            disabled
                                            title="{{ $promptItem['apply_block_reason'] }}"
                                        >
                                            {{ __('seo-content-ai::filament.article_ai_history.apply_content') }}
                                        </button>
                                    @endif

                                    @if ($promptText !== '' || $resultText !== '')
                                        <button
                                            type="button"
                                            class="fi-btn fi-btn-color-gray fi-btn-size-sm rounded-lg px-2 py-1 text-xs"
                                            x-on:click="openTwoCol(
                                                {{ \Illuminate\Support\Js::from($drawerTitle.' · RENDERED') }},
                                                {{ \Illuminate\Support\Js::from($promptText) }},
                                                {{ \Illuminate\Support\Js::from($resultText) }},
                                                'RENDERED PROMPT · RAW OUTPUT'
                                            )"
                                        >
                                            {{ __('seo-content-ai::filament.article_ai_history.view_prompt') }} / {{ __('seo-content-ai::filament.article_ai_history.view_output') }}
                                        </button>
                                    @endif

                                    <button
                                        type="button"
                                        class="fi-btn fi-btn-color-danger fi-btn-size-sm rounded-lg px-2 py-1 text-xs"
                                        wire:click="deleteArtifact({{ \Illuminate\Support\Js::from($artifactRef) }}, false)"
                                        wire:confirm="{{ __('seo-content-ai::filament.article_ai_history.delete_confirm_q') }}"
                                        wire:loading.attr="disabled"
                                        wire:target="deleteArtifact"
                                    >
                                        {{ __('seo-content-ai::filament.article_ai_history.delete') }}
                                    </button>
                                @endif
                            </div>

                            @php
                                $applyBlockReason = trim((string) ($promptItem['apply_block_reason'] ?? ''));
                                $showApplyBlock = $applyBlockReason !== '' && str_contains($hookKey, 'article.content');
                                $showErrorBanner = $isFailed && ($wordCountLabel !== null || $errorCode !== null || filled($errorMessage));
                            @endphp

                            @if ($showApplyBlock || $showErrorBanner)
                                <div class="seo-run-history-item__notices mx-3 mb-2.5 space-y-1.5">
                                    @if ($showApplyBlock)
                                        <div class="seo-run-history-item__apply-block rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-snug text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200">
                                            {{ $applyBlockReason }}
                                        </div>
                                    @endif

                                    @if ($showErrorBanner)
                                        <div class="seo-run-history-item__error-banner rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 dark:border-red-900 dark:bg-red-950/60">
                                            @if ($wordCountLabel !== null)
                                                <p class="seo-run-history-item__error-words mb-1 text-xs font-bold text-red-800 dark:text-red-200">{{ $wordCountLabel }}</p>
                                            @endif
                                            @if ($errorCode !== null)
                                                <p class="seo-run-history-item__error-code m-0 text-[0.6875rem] font-extrabold tracking-wide text-red-700 dark:text-red-300">{{ $errorCode }}</p>
                                            @endif
                                            @if (filled($errorMessage))
                                                <p class="seo-run-history-item__error-msg mt-1 break-words text-xs leading-snug text-rose-800 dark:text-red-200">{{ $errorMessage }}</p>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <section class="seo-run-step-empty">
                {{ __('seo-content-ai::filament.article_ai_history.empty') }}
            </section>
        @endforelse
        @endif

        {{-- Drawer 2 cột: Alpine mở ngay; Prompt | Kết quả như layout cũ --}}
        <div
            x-show="drawerOpen"
            x-cloak
            class="fixed inset-0 z-50 flex justify-end"
            style="display: none;"
        >
            <div class="absolute inset-0 bg-black/40" x-on:click="closeDrawer()"></div>
            <aside class="relative z-10 flex h-full w-full max-w-5xl flex-col bg-white shadow-xl dark:bg-gray-900">
                <header class="flex items-center justify-between gap-3 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                    <div class="min-w-0">
                        <h3 class="truncate text-sm font-semibold" x-text="drawerTitle"></h3>
                        <p class="truncate text-xs text-gray-500" x-show="drawerMeta" x-text="drawerMeta"></p>
                    </div>
                    <button type="button" class="shrink-0 text-sm px-2" x-on:click="closeDrawer()">✕</button>
                </header>
                <div class="seo-run-history-columns grid flex-1 grid-cols-1 gap-3 overflow-hidden p-4 md:grid-cols-2">
                    <div class="flex min-h-0 flex-col overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2 text-xs font-semibold dark:border-gray-700">
                            <span>Prompt</span>
                            <button type="button" class="underline" x-on:click="copyText(drawerPrompt)">Copy</button>
                        </div>
                        <pre class="min-h-0 flex-1 overflow-auto whitespace-pre-wrap p-3 text-xs" x-text="drawerPrompt || 'Không còn dữ liệu prompt.'"></pre>
                    </div>
                    <div class="flex min-h-0 flex-col overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2 text-xs font-semibold dark:border-gray-700">
                            <span>Kết quả</span>
                            <button type="button" class="underline" x-on:click="copyText(drawerResult)">Copy</button>
                        </div>
                        <pre class="min-h-0 flex-1 overflow-auto whitespace-pre-wrap p-3 text-xs" x-text="drawerResult || 'Không có kết quả được lưu.'"></pre>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</x-filament-panels::page>

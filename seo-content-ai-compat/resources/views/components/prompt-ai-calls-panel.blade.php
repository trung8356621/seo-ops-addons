@props([
    'groups' => [],
    'contextEyebrow' => '',
    'contextTitle' => '',
    'contextDescription' => '',
    'emptyMessage' => null,
    'filterType' => 'all',
    'filterStatus' => 'all',
    'typeOptions' => null,
    'statusOptions' => null,
    'allowApply' => false,
    'allowDelete' => false,
    'allowSelect' => false,
    'selectedRefs' => [],
    'hasPendingConfirm' => false,
    'page' => 1,
    'hasMore' => false,
    'total' => null,
    'loadDetailMethod' => 'loadRawAiCallDetail',
    'showContextCard' => true,
])

@php
    $groups = is_array($groups) ? $groups : [];
    $selectedRefs = is_array($selectedRefs) ? $selectedRefs : [];
    $emptyMessage = $emptyMessage ?? __('seo-content-ai::filament.article_ai_history.empty');
    $typeOptions = is_array($typeOptions) ? $typeOptions : [
        'all' => __('seo-content-ai::filament.article_ai_history.type_all'),
    ];
    $statusOptions = is_array($statusOptions) ? $statusOptions : [
        'all' => __('seo-content-ai::filament.article_ai_history.status_all'),
        'success' => __('seo-content-ai::filament.article_ai_history.status_success'),
        'error' => __('seo-content-ai::filament.article_ai_history.status_error'),
    ];
@endphp

<div
    class="seo-run-history-page"
    data-prompt-ai-calls-panel="1"
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
            if (!ref) return;
            const payload = await $wire.{{ $loadDetailMethod }}(ref);
            if (payload?.success) {
                this.openTwoCol(payload.title, payload.prompt, payload.output, payload.meta ?? '');
            } else {
                this.openTwoCol('AI Call', payload?.message ?? 'Không tìm thấy AI call.', '', '');
            }
            this.drawerOpen = true;
        },
        closeDrawer() { this.drawerOpen = false; },
        async copyText(text) {
            if (!text) return;
            try { await navigator.clipboard.writeText(text); }
            catch (_e) {
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
    x-on:keydown.escape.window="if (drawerOpen) closeDrawer()"
>
    @if ($showContextCard)
        <section class="seo-run-history-summary">
            <div>
                @if ($contextEyebrow !== '')
                    <p class="seo-run-history-summary__eyebrow">{{ $contextEyebrow }}</p>
                @endif
                <h2 class="seo-run-history-summary__title">{{ $contextTitle !== '' ? $contextTitle : 'AI Calls' }}</h2>
                @if ($contextDescription !== '')
                    <p class="seo-run-history-summary__description">{{ $contextDescription }}</p>
                @endif
            </div>
        </section>
    @endif

    <section class="seo-run-history-filters mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <div class="min-w-[10rem]">
            <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.article_ai_history.filter_type') }}</label>
            <x-select wire:model.live="{{ $attributes->get('filter-type-wire', 'filterType') }}" class="w-full">
                @foreach ($typeOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select>
        </div>
        <div class="min-w-[10rem]">
            <label class="mb-1 block text-xs font-medium text-gray-500">{{ __('seo-content-ai::filament.article_ai_history.filter_status') }}</label>
            <x-select wire:model.live="{{ $attributes->get('filter-status-wire', 'filterStatus') }}" class="w-full">
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-select>
        </div>
        <button
            type="button"
            class="fi-btn fi-btn-color-gray fi-btn-size-sm rounded-lg px-3 py-2 text-sm"
            wire:click="{{ $attributes->get('clear-filters-wire', 'clearFilters') }}"
            wire:loading.attr="disabled"
        >
            {{ __('seo-content-ai::filament.article_ai_history.clear_filters') }}
        </button>
        @if ($total !== null)
            <span class="ml-auto text-xs text-gray-500">{{ (int) $total }} AI Calls</span>
        @endif
    </section>

    @forelse ($groups as $group)
        @php
            $runId = $group['run_id'] ?? null;
            $projectName = trim((string) ($group['project_name'] ?? ''));
            $ranAt = $group['ran_at'] ?? null;
            $prompts = is_array($group['prompts'] ?? null) ? $group['prompts'] : [];
            $runStatus = strtoupper(trim((string) ($group['status'] ?? '')));
        @endphp

        <section class="seo-run-history-group" x-data="{ groupOpen: true }">
            <header class="seo-run-history-group__header cursor-pointer" x-on:click="groupOpen = ! groupOpen">
                <div>
                    <p class="seo-run-history-group__eyebrow">
                        @if ($runId)
                            Run #{{ $runId }}
                        @elseif ($projectName !== '')
                            {{ $projectName }}
                        @else
                            {{ __('seo-content-ai::filament.article_ai_history.orphan_group') }}
                        @endif
                    </p>
                    <h2 class="seo-run-history-group__title">
                        {{ count($prompts) }} {{ __('seo-content-ai::filament.article_ai_history.step_count_label') }}
                    </h2>
                </div>
                <div class="seo-run-history-group__meta">
                    @if ($ranAt)
                        <span>{{ $ranAt instanceof \DateTimeInterface ? $ranAt->format('d/m/Y H:i') : $ranAt }}</span>
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
                        $statusLabel = trim((string) ($promptItem['status_label'] ?? $status));
                        $hookKey = trim((string) ($promptItem['hook_key'] ?? ''));
                        $promptName = trim((string) ($promptItem['prompt_name'] ?? ''));
                        $promptText = trim((string) ($promptItem['prompt'] ?? ''));
                        $resultText = trim((string) ($promptItem['result'] ?? ''));
                        $model = trim((string) ($promptItem['model'] ?? ''));
                        $provider = trim((string) ($promptItem['provider'] ?? ''));
                        $profile = trim((string) ($promptItem['execution_profile'] ?? $promptItem['execution_type_label'] ?? ''));
                        $ranAtItem = $promptItem['ran_at'] ?? null;
                        $plannerRunId = (int) ($promptItem['planner_run_id'] ?? 0);
                        $message = trim((string) ($promptItem['message'] ?? ''));
                        $drawerTitle = $promptName !== '' ? $promptName : $promptType;
                    @endphp

                    <div class="seo-run-history-item">
                        <div class="flex items-start gap-2 px-3 pt-3 pb-1">
                            <div class="seo-run-history-item__toggle flex-1 pointer-events-none">
                                <span class="seo-run-history-item__identity">
                                    <span class="seo-run-history-item__index">{{ $index + 1 }}</span>
                                    <span>
                                        <span class="seo-run-history-item__type">{{ $promptType }}</span>
                                        @if ($statusLabel !== '')
                                            <span class="seo-run-history-item__model">{{ $statusLabel }}</span>
                                        @endif
                                        @if ($promptName !== '' && $promptName !== $promptType)
                                            <span class="seo-run-history-item__model">{{ $promptName }}</span>
                                        @endif
                                        @if ($profile !== '')
                                            <span class="seo-run-history-item__model">{{ $profile }}</span>
                                        @endif
                                        @if ($provider !== '' || $model !== '')
                                            <span class="seo-run-history-item__model">{{ trim($provider.($provider !== '' && $model !== '' ? ' / ' : '').$model) }}</span>
                                        @endif
                                        @if ($plannerRunId > 0)
                                            <span class="seo-run-history-item__model">Run #{{ $plannerRunId }}</span>
                                        @endif
                                        @if ($ranAtItem)
                                            <span class="seo-run-history-item__model">{{ $ranAtItem instanceof \DateTimeInterface ? $ranAtItem->format('d/m/Y H:i') : $ranAtItem }}</span>
                                        @endif
                                    </span>
                                </span>
                                <span class="seo-run-history-item__actions">
                                    @if ($status !== '')
                                        <span class="seo-run-history-status {{ in_array($status, ['failed', 'error', 'failure'], true) ? 'is-failed' : '' }}">
                                            {{ strtoupper($status) }}
                                        </span>
                                    @endif
                                </span>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 px-3 pb-3">
                            <button
                                type="button"
                                class="fi-btn fi-btn-color-gray fi-btn-size-sm rounded-lg px-2 py-1 text-xs"
                                x-on:click="openRawAiCall({{ \Illuminate\Support\Js::from($artifactRef) }})"
                            >
                                {{ __('seo-content-ai::filament.article_ai_history.view_prompt') }} / {{ __('seo-content-ai::filament.article_ai_history.view_output') }}
                            </button>
                            @if ($hookKey !== '')
                                <span class="text-xs text-gray-500 self-center" title="Hook">{{ $hookKey }}</span>
                            @endif
                            @if ($message !== '')
                                <span class="text-xs text-amber-700 dark:text-amber-300 self-center">{{ $message }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @empty
        <section class="seo-run-step-empty" data-prompt-ai-calls-empty="1">
            {{ $emptyMessage }}
        </section>
    @endforelse

    @if ($hasMore)
        <div class="mt-3 flex justify-center">
            <button
                type="button"
                class="fi-btn fi-btn-color-gray fi-btn-size-sm rounded-lg px-3 py-2 text-sm"
                wire:click="{{ $attributes->get('load-more-wire', 'loadMoreDraftAiCalls') }}"
                wire:loading.attr="disabled"
            >
                {{ __('seo-content-ai::filament.projects.draft_ai_calls_load_more') }}
            </button>
        </div>
    @endif

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

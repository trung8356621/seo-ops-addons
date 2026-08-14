@php
    use Omnichannel\Addons\ContentProjects\Support\AssignToContentProject\AssignToContentProjectContract;
@endphp

<div
    wire:ignore.self
    class="assign-to-content-project-drawer"
    x-data="{
        openEvent: @js(AssignToContentProjectContract::OPEN_EVENT),
        successEvent: @js(AssignToContentProjectContract::SUCCESS_EVENT),
        closeEvent: @js(AssignToContentProjectContract::CLOSE_EVENT),
        shellOpenEvent: @js(AssignToContentProjectContract::SHELL_OPEN_EVENT),
        shellCloseEvent: @js(AssignToContentProjectContract::SHELL_CLOSE_EVENT),
        shellOpen: false,
        clientPreparing: false,
        requestId: 0,
        selectedHint: 0,
        countSelected(detail) {
            const d = detail || {};
            if (Array.isArray(d.items) && d.items.length > 0) {
                return d.items.length;
            }
            if (Array.isArray(d.article_ids) && d.article_ids.length > 0) {
                return d.article_ids.length;
            }
            if (Array.isArray(d.keyword_ids) && d.keyword_ids.length > 0) {
                return d.keyword_ids.length;
            }

            return 1;
        },
        openShell(detail) {
            const payload = detail && typeof detail === 'object' ? detail : {};
            this.requestId += 1;
            const rid = this.requestId;
            this.selectedHint = this.countSelected(payload);
            this.shellOpen = true;
            this.clientPreparing = true;
            document.body.classList.add('assign-drawer-open');
            window.dispatchEvent(new CustomEvent(this.shellOpenEvent, {
                detail: Object.assign({}, payload, { request_id: rid }),
            }));

            try {
                $wire.prepare(Object.assign({}, payload, { _request_id: rid }))
                    .then(() => {
                        if (rid !== this.requestId) {
                            return;
                        }
                        this.clientPreparing = false;
                    })
                    .catch(() => {
                        if (rid !== this.requestId) {
                            return;
                        }
                        this.clientPreparing = false;
                    });
            } catch (e) {
                this.clientPreparing = false;
            }
        },
        hideShellLocal() {
            if (! this.shellOpen) {
                return;
            }
            this.shellOpen = false;
            this.clientPreparing = false;
            document.body.classList.remove('assign-drawer-open');
            window.dispatchEvent(new CustomEvent(this.shellCloseEvent));
        },
        closeShell() {
            try {
                if ($wire.submitting || $wire.quickCreateSubmitting) {
                    return;
                }
            } catch (e) {
                // Livewire mid-morph after success/reset — still close the shell.
            }
            this.requestId += 1;
            this.hideShellLocal();
            try {
                $wire.close();
            } catch (e) {
                // ignore detached component
            }
        },
        init() {
            window.addEventListener(this.openEvent, (event) => this.openShell(event.detail || {}));
            window.addEventListener(this.successEvent, () => this.hideShellLocal());
            window.addEventListener(this.closeEvent, () => this.hideShellLocal());
        },
    }"
>
    <div
        x-show="shellOpen"
        x-cloak
        x-transition.opacity.duration.150ms
        class="fixed inset-0 z-[10050]"
        x-bind:aria-hidden="! shellOpen"
    >
        <button
            type="button"
            class="absolute inset-0 bg-gray-950/20 transition-opacity dark:bg-black/40"
            x-on:click="closeShell()"
            tabindex="-1"
            aria-hidden="true"
        ></button>

        <aside
            class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col border-l border-gray-200 bg-white shadow-xl transition-transform duration-300 dark:border-white/10 dark:bg-gray-900 sm:w-[30%]"
            role="dialog"
            aria-modal="true"
            aria-labelledby="assign-to-content-project-drawer-title"
            x-bind:class="shellOpen ? 'translate-x-0' : 'translate-x-full'"
        >
            <div class="mt-16 flex min-h-0 flex-1 flex-col">
                <div class="flex items-start justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <div class="min-w-0">
                        <h3
                            id="assign-to-content-project-drawer-title"
                            class="text-sm font-semibold text-gray-900 dark:text-gray-100"
                        >
                            {{ AssignToContentProjectContract::label() }}
                        </h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{-- Use Alpine clientPreparing only — $wire.preparing throws after Livewire morph/reset. --}}
                            <span x-show="clientPreparing" x-text="selectedHint"></span>
                            <span x-show="! clientPreparing">{{ $this->uiState['selected_count'] }}</span>
                            {{ __('seo-content-ai::filament.articles_optimal.bulk_selected_suffix') }}
                        </p>
                    </div>
                    <x-filament::icon-button
                        type="button"
                        icon="heroicon-o-x-mark"
                        color="gray"
                        x-on:click="closeShell()"
                        tooltip="{{ __('seo-content-ai::filament.articles_optimal.sidebar_collapse') }}"
                    />
                </div>

                <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
                    <div x-show="clientPreparing" x-cloak class="space-y-3">
                        <div class="space-y-3 animate-pulse">
                            <div class="h-10 rounded-lg bg-gray-200 dark:bg-gray-800"></div>
                            <div class="h-10 rounded-lg bg-gray-200 dark:bg-gray-800"></div>
                            <div class="h-10 rounded-lg bg-gray-200 dark:bg-gray-800"></div>
                            <div class="h-24 rounded-lg bg-gray-200 dark:bg-gray-800"></div>
                        </div>
                        <p class="inline-flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            {{ __('seo-content-ai::filament.rank_group.loading') }}
                        </p>
                    </div>

                    <div
                        x-show="! clientPreparing"
                        x-cloak
                        class="flex min-h-0 flex-1 flex-col gap-4"
                    >
                        @if (filled($errorMessage) && ! $needsFocusKeyword)
                            <div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
                                {{ $errorMessage }}
                            </div>
                        @endif

                        @if ($mode === AssignToContentProjectContract::MODE_PENDING_LINK && filled($anchorPhrase))
                            <div>
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                    {{ __('seo-content-ai::filament.keyword.phrase') }}
                                </label>
                                <p class="mt-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-white/10 dark:bg-gray-950 dark:text-gray-200">
                                    {{ $anchorPhrase }}
                                </p>
                            </div>
                        @endif

                        @if ($mode === AssignToContentProjectContract::MODE_VOCABULARY_ITEMS && count($items) > 0)
                            <div>
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                    Vocabulary items
                                </label>
                                <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2 text-sm dark:border-white/10">
                                    @foreach ($itemStatuses as $statusRow)
                                        <li class="flex items-center justify-between gap-2">
                                            <span class="truncate text-gray-800 dark:text-gray-100">{{ $statusRow['keyword'] }}</span>
                                            <span @class([
                                                'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide',
                                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => ($statusRow['status'] ?? '') === 'new',
                                                'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => ($statusRow['status'] ?? '') === 'already_in_project',
                                                'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' => ($statusRow['status'] ?? '') === 'existing_article',
                                            ])>
                                                {{ $statusRow['label'] ?? 'New' }}
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($mode === AssignToContentProjectContract::MODE_KEYWORD)
                            <div>
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                    {{ __('seo-content-ai::filament.keyword.domain') }}
                                </label>
                                <x-select
                                    multiple
                                    wire:model.live="siteIds"
                                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950"
                                >
                                    @foreach ($siteOptions as $siteId => $label)
                                        <option value="{{ $siteId }}">{{ $label }}</option>
                                    @endforeach
                                </x-select>
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ __('seo-content-ai::filament.keyword.assign_to_content_project_sites_hint') }}
                                </p>
                            </div>

                            @foreach ($siteIds as $siteId)
                                @php $siteId = (int) $siteId; @endphp
                                <div wire:key="assign-kw-project-{{ $siteId }}">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                        {{ __('seo-content-ai::filament.article_list.content_project') }}
                                        — {{ $siteOptions[$siteId] ?? ('#'.$siteId) }}
                                    </label>
                                    <x-select
                                        wire:model.live="projectIdBySite.{{ $siteId }}"
                                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950"
                                    >
                                        <option value="">{{ __('seo-content-ai::filament.articles_optimal.sidebar_select_project') }}</option>
                                        @foreach (($projectOptionsBySite[$siteId] ?? []) as $projectOptionId => $projectLabel)
                                            <option value="{{ $projectOptionId }}">{{ $projectLabel }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                            @endforeach
                        @else
                            <div class="flex items-end gap-2">
                                <div class="min-w-0 flex-1">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                        {{ __('seo-content-ai::filament.articles_optimal.sidebar_project_label') }}
                                    </label>
                                    <x-select
                                        wire:model.live="projectId"
                                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950"
                                    >
                                        <option value="">{{ __('seo-content-ai::filament.articles_optimal.sidebar_select_project') }}</option>
                                        @foreach ($projectOptions as $projectOptionId => $projectLabel)
                                            <option value="{{ $projectOptionId }}">{{ $projectLabel }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                                @if ($showQuickCreate)
                                    <x-filament::icon-button
                                        type="button"
                                        icon="heroicon-o-plus"
                                        color="gray"
                                        wire:click="$set('quickCreateOpen', true)"
                                        tooltip="{{ __('seo-content-ai::filament.article_list.quick_create_content_project') }}"
                                    />
                                @endif
                            </div>
                        @endif

                        @if ($showArticleFields)
                            <div>
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                    {{ __('seo-content-ai::filament.projects.article_type') }}
                                </label>
                                <x-select
                                    wire:model.live="type"
                                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950"
                                >
                                    @foreach ($typeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </x-select>
                            </div>

                            @if ($type === \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::TYPE_IMPROVE)
                                <div>
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                        {{ __('seo-content-ai::filament.projects.improve_instruction') }}
                                    </label>
                                    <textarea
                                        wire:model="rewriteNotes"
                                        rows="3"
                                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950"
                                        placeholder="{{ __('seo-content-ai::filament.projects.improve_instruction_placeholder') }}"
                                    ></textarea>
                                </div>
                            @endif

                            @if ($showKeywordOverride && in_array($type, [
                                \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::TYPE_CREATE,
                                \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::TYPE_REWRITE,
                            ], true))
                                <div>
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                        {{ __('seo-content-ai::filament.projects.keyword') }}
                                    </label>
                                    <input
                                        type="text"
                                        wire:model="keywordOverride"
                                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950"
                                        placeholder="{{ __('seo-content-ai::filament.projects.keyword_placeholder') }}"
                                    />
                                </div>
                            @endif

                            @if ($showTitleOverride && in_array($type, [
                                \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::TYPE_CREATE,
                                \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::TYPE_REWRITE,
                            ], true))
                                <div>
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                        {{ __('seo-content-ai::filament.projects.title_field') }}
                                    </label>
                                    <input
                                        type="text"
                                        wire:model="titleOverride"
                                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950"
                                        placeholder="{{ __('seo-content-ai::filament.projects.title_field_placeholder') }}"
                                    />
                                </div>
                            @endif
                        @endif

                        @if ($showFocusKeyword || $needsFocusKeyword)
                            <div>
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                    {{ __('seo-content-ai::filament.articles_optimal.assign_focus_keyword') }}
                                    <span class="text-rose-600">*</span>
                                </label>
                                <input
                                    type="text"
                                    wire:model.live="focusKeyword"
                                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-gray-950"
                                    placeholder="{{ __('seo-content-ai::filament.articles_optimal.assign_focus_keyword_placeholder') }}"
                                />
                                <p class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                    {{ __('seo-content-ai::filament.articles_optimal.assign_focus_keyword_help') }}
                                </p>
                            </div>
                        @endif

                        @if ($quickCreateOpen)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                    {{ __('seo-content-ai::filament.article_list.quick_create_content_project') }}
                                </h4>
                                <div class="mt-3">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                        {{ __('seo-content-ai::filament.projects.assign_writer') }}
                                    </label>
                                    <x-select
                                        wire:model="quickWriterId"
                                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950"
                                    >
                                        <option value="">{{ __('seo-content-ai::filament.projects.assign_writer') }}</option>
                                        @foreach ($this->uiState['writer_options'] as $writerId => $writerLabel)
                                            <option value="{{ $writerId }}">{{ $writerLabel }}</option>
                                        @endforeach
                                    </x-select>
                                </div>
                                <div class="mt-3 flex gap-2">
                                    <x-filament::button type="button" color="gray" wire:click="$set('quickCreateOpen', false)">
                                        {{ __('filament-actions::modal.actions.cancel.label') }}
                                    </x-filament::button>
                                    <x-filament::button
                                        type="button"
                                        color="warning"
                                        wire:click="quickCreate"
                                        wire:loading.attr="disabled"
                                        wire:target="quickCreate"
                                    >
                                        {{ __('seo-content-ai::filament.article_list.quick_create_content_project') }}
                                    </x-filament::button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <div
                    class="border-t border-gray-200 px-4 py-3 dark:border-white/10"
                    x-show="! clientPreparing"
                    x-cloak
                >
                    <x-filament::button
                        type="button"
                        color="info"
                        class="w-full"
                        wire:click="submit"
                        wire:loading.attr="disabled"
                        wire:target="submit"
                        :disabled="! $this->uiState['can_submit']"
                    >
                        <span wire:loading.remove wire:target="submit">
                            {{ __('seo-content-ai::filament.article_list.assign') }}
                        </span>
                        <span wire:loading wire:target="submit" class="inline-flex items-center gap-2">
                            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                            {{ __('seo-content-ai::filament.article_list.assign') }}
                        </span>
                    </x-filament::button>
                </div>
            </div>
        </aside>
    </div>
</div>

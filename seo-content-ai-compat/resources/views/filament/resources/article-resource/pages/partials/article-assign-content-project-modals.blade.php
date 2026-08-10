@php
    use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
    use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
    use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
    use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
    use Omnichannel\Addons\Seo\Support\SeoAccessControl;

    $articleSiteId = ArticleResource::resolveArticleSiteId($record);
    $articleDirectProjectId = ArticleResource::resolveDirectAssignContentProjectId($articleSiteId);
    $articleProjectOptions = ArticleResource::contentProjectOptions($articleSiteId);
    $articleAssignTypeOptions = SeoProjectTask::typeOptions();
    $writerOptions = SeoProjectResource::userSelectOptions();

    $keywordSiteId = (int) ($record->site_id ?? 0);
    $keywordDirectAssign = KeywordResource::resolveKeywordDirectAssignData($keywordSiteId > 0 ? $keywordSiteId : null);
    $keywordProjectOptions = $keywordSiteId > 0 ? ArticleResource::contentProjectOptions($keywordSiteId) : [];
    $keywordProjectField = $keywordSiteId > 0 ? 'project_id_'.$keywordSiteId : 'project_id';
@endphp

<div
    class="seo-content-project-assign-modal article-assign-content-project-modal"
    x-data="{
        open: false,
        quickCreateOpen: false,
        projectId: @js($articleDirectProjectId ? (string) $articleDirectProjectId : ''),
        assignType: @js(SeoProjectTask::TYPE_REWRITE),
        rewriteNotes: '',
        quickWriterId: @js(SeoAccessControl::isContentManager() ? (string) auth()->id() : ''),
        quickCreateSubmitting: false,
        assignSubmitting: false,
        projectOptions: @js($articleProjectOptions),
        needsProjectSelect: @js($articleDirectProjectId === null),
        directProjectId: @js($articleDirectProjectId),
        openModal() {
            this.projectId = this.directProjectId ? String(this.directProjectId) : '';
            this.assignType = @js(SeoProjectTask::TYPE_REWRITE);
            this.rewriteNotes = '';
            this.open = true;
        },
        closeModal() {
            if (this.assignSubmitting || this.quickCreateSubmitting) {
                return;
            }

            this.open = false;
            this.quickCreateOpen = false;
        },
        submitAssign() {
            this.assignSubmitting = true;
            this.$wire.assignCurrentArticleToContentProject({
                project_id: this.directProjectId || this.projectId,
                type: this.assignType,
                rewrite_mode: @js(SeoProjectTask::REWRITE_MODE_CONTENT),
                rewrite_notes: this.rewriteNotes,
            }).then(() => {
                this.open = false;
            }).finally(() => {
                this.assignSubmitting = false;
            });
        },
        submitQuickCreate() {
            this.quickCreateSubmitting = true;
            this.$wire.quickCreateArticleContentProject(this.quickWriterId ? Number(this.quickWriterId) : null)
                .then((result) => {
                    if (result?.options) {
                        this.projectOptions = result.options;
                    }
                    if (result?.project_id) {
                        this.projectId = String(result.project_id);
                    }
                    this.quickCreateOpen = false;
                })
                .finally(() => {
                    this.quickCreateSubmitting = false;
                });
        },
    }"
    x-on:open-article-assign-content-project-modal.window="openModal()"
    x-on:close-article-assign-content-project-modal.window="closeModal()"
    x-show="open"
    x-cloak
    role="dialog"
    aria-modal="true"
    aria-labelledby="article-assign-content-project-modal-title"
>
    <button type="button" class="seo-content-project-assign-modal__backdrop" x-on:click="closeModal()" tabindex="-1" aria-hidden="true"></button>

    <form x-on:submit.prevent="submitAssign()" class="seo-content-project-assign-modal__panel">
        <div class="seo-content-project-assign-modal__header">
            <div>
                <h2 id="article-assign-content-project-modal-title" class="seo-content-project-assign-modal__title">
                    {{ __('seo-content-ai::filament.article_list.assign_to_content_project') }}
                </h2>
                <p class="seo-content-project-assign-modal__description">
                    {{ __('seo-content-ai::filament.article_list.assign_to_content_project_description') }}
                </p>
            </div>
            <button type="button" class="seo-content-project-assign-modal__close" x-on:click="closeModal()" aria-label="{{ __('filament-actions::modal.actions.cancel.label') }}">
                <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
            </button>
        </div>

        <div class="seo-content-project-assign-modal__body space-y-4">
            <div x-show="needsProjectSelect">
                <label class="seo-content-project-assign-modal__label">{{ __('seo-content-ai::filament.article_list.content_project') }}</label>
                <div class="mt-1 flex items-end gap-2">
                    <x-select x-model="projectId" required class="block min-w-0 flex-1 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950">
                        <option value="">{{ __('seo-content-ai::filament.articles_optimal.sidebar_select_project') }}</option>
                        <template x-for="[id, label] in Object.entries(projectOptions)" :key="id">
                            <option :value="id" x-text="label"></option>
                        </template>
                    </x-select>
                    <x-filament::icon-button
                        type="button"
                        icon="heroicon-o-plus"
                        color="success"
                        x-on:click="quickCreateOpen = true"
                        tooltip="{{ __('seo-content-ai::filament.article_list.quick_create_content_project') }}"
                    />
                </div>
            </div>

            <div>
                <label class="seo-content-project-assign-modal__label">{{ __('seo-content-ai::filament.projects.article_type') }}</label>
                <x-select x-model="assignType" class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950">
                    @foreach ($articleAssignTypeOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-select>
            </div>

            <div x-show="assignType === @js(SeoProjectTask::TYPE_IMPROVE)">
                <label class="seo-content-project-assign-modal__label">{{ __('seo-content-ai::filament.projects.improve_instruction') }}</label>
                <textarea
                    x-model="rewriteNotes"
                    rows="3"
                    class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950"
                    placeholder="{{ __('seo-content-ai::filament.projects.improve_instruction_placeholder') }}"
                ></textarea>
            </div>
        </div>

        <div class="seo-content-project-assign-modal__actions">
            <x-filament::button type="button" color="gray" x-on:click="closeModal()" x-bind:disabled="assignSubmitting || quickCreateSubmitting">
                {{ __('filament-actions::modal.actions.cancel.label') }}
            </x-filament::button>
            <x-filament::button type="submit" color="warning" x-bind:disabled="assignSubmitting || quickCreateSubmitting || (needsProjectSelect && !projectId)">
                <span x-show="! assignSubmitting">{{ __('seo-content-ai::filament.article_list.assign') }}</span>
                <span x-show="assignSubmitting" class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    {{ __('seo-content-ai::filament.articles_optimal.processing') }}
                </span>
            </x-filament::button>
        </div>
    </form>

    <div
        x-show="quickCreateOpen"
        x-cloak
        class="seo-content-project-assign-modal__nested"
        role="dialog"
        aria-modal="true"
    >
        <button type="button" class="seo-content-project-assign-modal__backdrop" x-on:click="quickCreateOpen = false" tabindex="-1" aria-hidden="true"></button>
        <form x-on:submit.prevent="submitQuickCreate()" class="seo-content-project-assign-modal__panel seo-content-project-assign-modal__panel--nested">
            <h3 class="seo-content-project-assign-modal__title">{{ __('seo-content-ai::filament.article_list.quick_create_content_project') }}</h3>
            <div class="mt-4 space-y-4">
                <div>
                    <label class="seo-content-project-assign-modal__label">{{ __('seo-content-ai::filament.projects.assign_writer') }}</label>
                    <x-select
                        x-model="quickWriterId"
                        required
                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950"
                        :disabled="SeoAccessControl::isContentManager()"
                    >
                        <option value="">--</option>
                        @foreach ($writerOptions as $writerId => $writerLabel)
                            <option value="{{ $writerId }}">{{ $writerLabel }}</option>
                        @endforeach
                    </x-select>
                </div>
            </div>
            <div class="seo-content-project-assign-modal__actions">
                <x-filament::button type="button" color="gray" x-on:click="quickCreateOpen = false" x-bind:disabled="quickCreateSubmitting">
                    {{ __('filament-actions::modal.actions.cancel.label') }}
                </x-filament::button>
                <x-filament::button type="submit" color="success" x-bind:disabled="quickCreateSubmitting">
                    <span x-show="! quickCreateSubmitting">{{ __('seo-content-ai::filament.article_list.quick_create_content_project') }}</span>
                    <span x-show="quickCreateSubmitting" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                    </span>
                </x-filament::button>
            </div>
        </form>
    </div>
</div>

<div
    class="seo-content-project-assign-modal keyword-assign-content-project-modal"
    x-data="{
        open: false,
        anchorPhrase: '',
        projectId: '',
        assignSubmitting: false,
        projectOptions: @js($keywordProjectOptions),
        siteId: @js($keywordSiteId),
        projectField: @js($keywordProjectField),
        directAssign: @js($keywordDirectAssign),
        openModal(detail) {
            this.anchorPhrase = String(detail?.anchorPhrase ?? '').trim();
            if (this.anchorPhrase === '') {
                return;
            }

            if (this.directAssign) {
                this.submitDirectAssign();
                return;
            }

            this.projectId = '';
            this.open = true;
        },
        closeModal() {
            if (this.assignSubmitting) {
                return;
            }

            this.open = false;
            this.anchorPhrase = '';
        },
        buildAssignData() {
            const data = {};
            if (this.siteId > 0) {
                data.site_ids = [this.siteId];
                data[this.projectField] = this.projectId;
            } else {
                data.project_id = this.projectId;
            }

            return data;
        },
        submitAssign() {
            this.assignSubmitting = true;
            this.$wire.assignKeywordAnchorToContentProjectFromEditor(this.anchorPhrase, this.buildAssignData())
                .then(() => {
                    this.open = false;
                    this.anchorPhrase = '';
                })
                .finally(() => {
                    this.assignSubmitting = false;
                });
        },
        submitDirectAssign() {
            this.assignSubmitting = true;
            this.$wire.assignKeywordAnchorToContentProjectFromEditor(this.anchorPhrase, this.directAssign ?? {})
                .finally(() => {
                    this.assignSubmitting = false;
                    this.anchorPhrase = '';
                });
        },
    }"
    x-on:open-keyword-assign-content-project-modal.window="openModal($event.detail)"
    x-on:close-keyword-assign-content-project-modal.window="closeModal()"
    x-show="open"
    x-cloak
    role="dialog"
    aria-modal="true"
    aria-labelledby="keyword-assign-content-project-modal-title"
>
    <button type="button" class="seo-content-project-assign-modal__backdrop" x-on:click="closeModal()" tabindex="-1" aria-hidden="true"></button>

    <form x-on:submit.prevent="submitAssign()" class="seo-content-project-assign-modal__panel">
        <div class="seo-content-project-assign-modal__header">
            <div>
                <h2 id="keyword-assign-content-project-modal-title" class="seo-content-project-assign-modal__title">
                    {{ __('seo-content-ai::filament.article_list.assign_to_content_project') }}
                </h2>
                <p class="seo-content-project-assign-modal__description">
                    {{ __('seo-content-ai::filament.keyword.assign_to_content_project_description') }}
                </p>
            </div>
            <button type="button" class="seo-content-project-assign-modal__close" x-on:click="closeModal()" aria-label="{{ __('filament-actions::modal.actions.cancel.label') }}">
                <x-filament::icon icon="heroicon-o-x-mark" class="h-5 w-5" />
            </button>
        </div>

        <div class="seo-content-project-assign-modal__body space-y-4">
            <div>
                <label class="seo-content-project-assign-modal__label">{{ __('seo-content-ai::filament.keyword.phrase') }}</label>
                <p class="mt-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-white/10 dark:bg-gray-950 dark:text-gray-200" x-text="anchorPhrase"></p>
            </div>

            <div>
                <label class="seo-content-project-assign-modal__label">{{ __('seo-content-ai::filament.article_list.content_project') }}</label>
                <x-select x-model="projectId" required class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-gray-950">
                    <option value="">{{ __('seo-content-ai::filament.articles_optimal.sidebar_select_project') }}</option>
                    <template x-for="[id, label] in Object.entries(projectOptions)" :key="id">
                        <option :value="id" x-text="label"></option>
                    </template>
                </x-select>
            </div>
        </div>

        <div class="seo-content-project-assign-modal__actions">
            <x-filament::button type="button" color="gray" x-on:click="closeModal()" x-bind:disabled="assignSubmitting">
                {{ __('filament-actions::modal.actions.cancel.label') }}
            </x-filament::button>
            <x-filament::button type="submit" color="warning" x-bind:disabled="assignSubmitting || !projectId">
                <span x-show="! assignSubmitting">{{ __('seo-content-ai::filament.article_list.assign') }}</span>
                <span x-show="assignSubmitting" class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    {{ __('seo-content-ai::filament.articles_optimal.processing') }}
                </span>
            </x-filament::button>
        </div>
    </form>
</div>

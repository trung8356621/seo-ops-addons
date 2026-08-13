@props([
    'row' => [],
])

@php
    $tid = (int) ($row['task_id'] ?? 0);
    $articleUrl = $row['article_edit_url'] ?? null;
    $title = (string) ($row['primary_label'] ?? $row['title'] ?? '#'.$tid);
    $lifecycle = strtolower((string) ($row['lifecycle'] ?? ''));
    $lifecycleBucket = $lifecycle === 'waiting_publish' ? 'scheduled' : $lifecycle;
    $a = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemActionsPresenter::forRow($row);
    $itemClass = 'cp-ops-menu__item';
    $dangerClass = 'cp-ops-menu__item cp-ops-menu__item--danger';
@endphp

<div
    {{ $attributes->class(['relative inline-flex items-center gap-1']) }}
    x-data="{
        open: false,
        place: 'bottom-end',
        style: '',
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => this.reposition());
            } else {
                this.style = '';
            }
        },
        reposition() {
            const panel = this.$refs.menu;
            const btn = this.$refs.trigger;
            if (!panel || !btn) return;
            const br = btn.getBoundingClientRect();
            const pw = Math.min(280, Math.max(240, panel.offsetWidth || 240));
            const ph = panel.offsetHeight || 240;
            const spaceBelow = window.innerHeight - br.bottom;
            const flipUp = spaceBelow < ph + 12 && br.top > ph + 12;
            let top = flipUp ? (br.top - ph - 4) : (br.bottom + 4);
            let left = br.right - pw;
            if (left < 12) left = 12;
            if (left + pw > window.innerWidth - 12) left = Math.max(12, window.innerWidth - pw - 12);
            if (top < 12) top = 12;
            this.place = (flipUp ? 'top' : 'bottom') + '-end';
            this.style = 'position:fixed;top:' + top + 'px;left:' + left + 'px;right:auto;bottom:auto;';
        },
    }"
    @keydown.escape.window="open = false"
>
    @if ($a['open_article'] && $articleUrl)
        <a
            href="{{ $articleUrl }}"
            target="_blank"
            rel="noopener noreferrer"
            @click="typeof claimNeedsReviewArticle === 'function' && claimNeedsReviewArticle({{ $tid }}, {{ ! empty($row['is_recently_completed']) ? 'true' : 'false' }})"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-primary-600 ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:ring-gray-700 dark:hover:bg-gray-800"
            aria-label="{{ __('seo-content-ai::filament.projects.item_action_open_article') }}"
            title="{{ __('seo-content-ai::filament.projects.item_action_open_article') }}"
        >
            <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-4 w-4" />
        </a>
    @elseif ($a['prefer_acknowledge_error'])
        <button
            type="button"
            wire:click="acknowledgeGenerationError({{ $tid }})"
            wire:target="acknowledgeGenerationError({{ $tid }})"
            wire:loading.attr="disabled"
            wire:confirm="{{ __('seo-content-ai::filament.projects.item_action_acknowledge_error_confirm') }}"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-success-600 ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-success-400 dark:ring-gray-700 dark:hover:bg-gray-800"
            aria-label="{{ __('seo-content-ai::filament.projects.item_action_acknowledge_error') }}"
            title="{{ __('seo-content-ai::filament.projects.item_action_acknowledge_error') }}"
        >
            <x-filament::icon wire:loading.remove wire:target="acknowledgeGenerationError({{ $tid }})" icon="heroicon-o-check" class="h-4 w-4" />
            <svg wire:loading wire:target="acknowledgeGenerationError({{ $tid }})" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
        </button>
    @elseif (! empty($a['resume_generation']))
        <button
            type="button"
            wire:click="resumeFromFailedStep({{ $tid }})"
            wire:target="resumeFromFailedStep({{ $tid }})"
            wire:loading.attr="disabled"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-primary-600 ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-primary-400 dark:ring-gray-700 dark:hover:bg-gray-800"
            aria-label="{{ __('seo-content-ai::filament.projects.item_action_resume_failed_step') }}"
            title="{{ __('seo-content-ai::filament.projects.item_action_resume_failed_step') }}"
        >
            <x-filament::icon wire:loading.remove wire:target="resumeFromFailedStep({{ $tid }})" icon="heroicon-o-arrow-uturn-left" class="h-4 w-4" />
            <svg wire:loading wire:target="resumeFromFailedStep({{ $tid }})" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
        </button>
    @elseif (! empty($a['select_existing_article']))
        <button
            type="button"
            @click="$dispatch('open-select-existing-article', { taskId: {{ $tid }} })"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-warning-600 ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-warning-400 dark:ring-gray-700 dark:hover:bg-gray-800"
            aria-label="{{ __('seo-content-ai::filament.projects.item_action_select_existing_article') }}"
            title="{{ __('seo-content-ai::filament.projects.item_action_select_existing_article') }}"
        >
            <x-filament::icon icon="heroicon-o-link" class="h-4 w-4" />
        </button>
    @elseif (! empty($a['create_or_rerun']))
        @php
            $createOrRerunLabel = (($a['create_or_rerun_label'] ?? 'create') === 'rerun')
                ? __('seo-content-ai::filament.projects.item_action_smart_rerun')
                : __('seo-content-ai::filament.projects.item_action_smart_create');
            $confirmMissing = ! empty($a['confirm_recreate_missing_article']);
        @endphp
        <button
            type="button"
            @if ($confirmMissing)
                @click="$dispatch('open-missing-article-confirm', { taskId: {{ $tid }}, title: @js($title), previousId: {{ (int) ($row['article_id'] ?? 0) }} })"
            @else
                wire:click="createOrRerunOne({{ $tid }})"
                wire:target="createOrRerunOne({{ $tid }})"
                wire:loading.attr="disabled"
            @endif
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-success-600 ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-success-400 dark:ring-gray-700 dark:hover:bg-gray-800"
            aria-label="{{ $createOrRerunLabel }}"
            title="{{ $createOrRerunLabel }}"
        >
            @if ($confirmMissing)
                <x-filament::icon icon="heroicon-o-play" class="h-4 w-4" />
            @else
                <x-filament::icon wire:loading.remove wire:target="createOrRerunOne({{ $tid }})" icon="heroicon-o-play" class="h-4 w-4" />
                <svg wire:loading wire:target="createOrRerunOne({{ $tid }})" class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
            @endif
        </button>
    @endif

    <div class="relative">
        <button
            type="button"
            x-ref="trigger"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg ring-1 ring-gray-200 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:ring-gray-700 dark:hover:bg-gray-800"
            @click="toggle()"
            :aria-expanded="open.toString()"
            aria-haspopup="menu"
            aria-label="Item actions"
        >
            <x-filament::icon icon="heroicon-o-ellipsis-vertical" class="h-4 w-4" />
        </button>

        <div
            x-ref="menu"
            x-show="open"
            x-cloak
            x-transition
            @click.outside="open = false"
            role="menu"
            class="cp-ops-menu"
            :style="style"
            :class="{
                'cp-ops-menu--top': place.startsWith('top'),
                'cp-ops-menu--bottom': place.startsWith('bottom'),
                'cp-ops-menu--start': place.endsWith('start'),
                'cp-ops-menu--end': place.endsWith('end'),
            }"
        >
            @if ($a['has_content'])
                <p class="cp-ops-menu__heading">Content</p>
                @if ($a['open_article'] && $articleUrl)
                    <a
                        role="menuitem"
                        href="{{ $articleUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        @click="open = false; typeof claimNeedsReviewArticle === 'function' && claimNeedsReviewArticle({{ $tid }}, {{ ! empty($row['is_recently_completed']) ? 'true' : 'false' }})"
                        class="{{ $itemClass }}"
                        title="{{ __('seo-content-ai::filament.projects.item_action_open_article') }}"
                    >
                        <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.item_action_open_article') }}</span>
                    </a>
                @endif
                @if ($a['skip_generation'])
                    <button
                        role="menuitem"
                        type="button"
                        wire:click="skipGenerationOne({{ $tid }})"
                        wire:confirm="{{ __('seo-content-ai::filament.projects.item_action_skip_generation_confirm') }}"
                        @click="open = false"
                        class="{{ $itemClass }}"
                        title="{{ __('seo-content-ai::filament.projects.item_action_skip_generation') }}"
                    >
                        <x-filament::icon icon="heroicon-o-no-symbol" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.item_action_skip_generation') }}</span>
                    </button>
                @endif
                @if ($a['allow_generation'])
                    <button
                        role="menuitem"
                        type="button"
                        wire:click="allowGenerationOne({{ $tid }})"
                        @click="open = false"
                        class="{{ $itemClass }}"
                        title="{{ __('seo-content-ai::filament.projects.item_action_allow_generation') }}"
                    >
                        <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.item_action_allow_generation') }}</span>
                    </button>
                @endif
                @if ($a['acknowledge_error'])
                    <button role="menuitem" type="button" wire:click="acknowledgeGenerationError({{ $tid }})" wire:confirm="{{ __('seo-content-ai::filament.projects.item_action_acknowledge_error_confirm') }}" @click="open = false" class="{{ $itemClass }}" title="{{ __('seo-content-ai::filament.projects.item_action_acknowledge_error') }}">
                        <x-filament::icon icon="heroicon-o-check" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.item_action_acknowledge_error') }}</span>
                    </button>
                @endif
                @if (! empty($a['create_or_rerun']))
                    @php
                        $menuCreateOrRerunLabel = (($a['create_or_rerun_label'] ?? 'create') === 'rerun')
                            ? __('seo-content-ai::filament.projects.item_action_smart_rerun')
                            : __('seo-content-ai::filament.projects.item_action_smart_create');
                        $menuConfirmMissing = ! empty($a['confirm_recreate_missing_article']);
                    @endphp
                    <button
                        role="menuitem"
                        type="button"
                        @if ($menuConfirmMissing)
                            @click="open = false; $dispatch('open-missing-article-confirm', { taskId: {{ $tid }}, title: @js($title), previousId: {{ (int) ($row['article_id'] ?? 0) }} })"
                        @else
                            wire:click="createOrRerunOne({{ $tid }})"
                            @click="open = false"
                        @endif
                        class="{{ $itemClass }}"
                        title="{{ $menuCreateOrRerunLabel }}"
                    >
                        <x-filament::icon icon="heroicon-o-play" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ $menuCreateOrRerunLabel }}</span>
                    </button>
                @endif
                @if ($a['resume_generation'])
                    <button role="menuitem" type="button" wire:click="resumeFromFailedStep({{ $tid }})" @click="open = false" class="{{ $itemClass }}" title="{{ __('seo-content-ai::filament.projects.item_action_resume_failed_step') }}">
                        <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.item_action_resume_failed_step') }}</span>
                    </button>
                @endif
                @if (! empty($a['select_existing_article']))
                    <button
                        role="menuitem"
                        type="button"
                        @click="open = false; $dispatch('open-select-existing-article', { taskId: {{ $tid }} })"
                        class="{{ $itemClass }}"
                        title="{{ __('seo-content-ai::filament.projects.item_action_select_existing_article') }}"
                    >
                        <x-filament::icon icon="heroicon-o-link" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.item_action_select_existing_article') }}</span>
                    </button>
                @endif
                @if ($a['regen_outline'])
                    <button role="menuitem" type="button" wire:click="regenOutline({{ $tid }})" wire:confirm="{{ __('seo-content-ai::filament.projects.item_action_regen_outline_confirm') }}" @click="open = false" class="{{ $itemClass }}" title="{{ __('seo-content-ai::filament.projects.item_action_regen_outline') }}">
                        <x-filament::icon icon="heroicon-o-document-text" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.item_action_regen_outline') }}</span>
                    </button>
                @endif
                @if ($a['regen_article'])
                    <button role="menuitem" type="button" wire:click="regenArticle({{ $tid }})" wire:confirm="{{ __('seo-content-ai::filament.projects.item_action_regen_article_confirm') }}" @click="open = false" class="{{ $itemClass }}" title="{{ __('seo-content-ai::filament.projects.item_action_regen_article') }}">
                        <x-filament::icon icon="heroicon-o-pencil-square" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.item_action_regen_article') }}</span>
                    </button>
                @endif
                @if ($a['regen_image'] && $articleUrl)
                    <a role="menuitem" href="{{ $articleUrl }}" class="{{ $itemClass }}" title="{{ __('seo-content-ai::filament.projects.item_action_regen_image') }}">
                        <x-filament::icon icon="heroicon-o-photo" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.item_action_regen_image') }}</span>
                    </a>
                @endif
                @if ($a['improve_note'])
                    <span class="cp-ops-menu__note">{{ __('seo-content-ai::filament.projects.item_action_improve_manual') }}</span>
                @endif
            @endif

            @if ($a['has_review'])
                <div class="cp-ops-menu__divider"></div>
                <p class="cp-ops-menu__heading">Review</p>
                @if ($a['start_review'])
                    <button role="menuitem" type="button" wire:click="startReviewOne({{ $tid }})" @click="open = false" class="{{ $itemClass }}" title="Start review">
                        <x-filament::icon icon="heroicon-o-eye" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">Start review</span>
                    </button>
                @endif
                @if ($a['approve'])
                    <button role="menuitem" type="button" wire:click="approveOne({{ $tid }})" @click="open = false" class="{{ $itemClass }}" title="Approve">
                        <x-filament::icon icon="heroicon-o-check-badge" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">Approve</span>
                    </button>
                @endif
            @endif

            @if (! empty($a['send_to_publishing_queue']) || $a['has_lifecycle'] || ! empty($a['has_debug']))
                <div class="cp-ops-menu__divider"></div>
                <p class="cp-ops-menu__heading">Publishing Queue</p>
                @if (! empty($a['send_to_publishing_queue']))
                    <button
                        role="menuitem"
                        type="button"
                        wire:click="sendToPublishingQueueOne({{ $tid }})"
                        @if (! empty($a['send_to_publishing_queue_warn_cm']))
                            wire:confirm="{{ __('seo-content-ai::filament.projects.send_to_publishing_queue_confirm_needs_review') }}"
                        @endif
                        @click="open = false"
                        class="{{ $itemClass }}"
                        title="{{ __('seo-content-ai::filament.projects.send_to_publishing_queue') }}"
                    >
                        <x-filament::icon icon="heroicon-o-queue-list" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.send_to_publishing_queue') }}</span>
                    </button>
                @endif
                @if ($a['has_lifecycle'])
                    <p class="cp-ops-menu__heading">Lifecycle</p>
                @endif
                {{-- schedule/publish actions live on Publishing Queue page --}}
                @if ($a['cancel'])
                    <button role="menuitem" type="button" wire:click="cancelPublishOne({{ $tid }})" wire:confirm="Cancel publishing?" @click="open = false" class="{{ $dangerClass }}" title="Cancel">
                        <x-filament::icon icon="heroicon-o-x-mark" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">Cancel</span>
                    </button>
                @endif
                @if ($a['archive_item'])
                    <button role="menuitem" type="button" wire:click="archiveOne({{ $tid }})" wire:confirm="{{ __('seo-content-ai::filament.projects.archive_item_confirm') }}" @click="open = false" class="{{ $dangerClass }}" title="{{ __('seo-content-ai::filament.projects.archive_item') }}">
                        <x-filament::icon icon="heroicon-o-archive-box" class="cp-ops-menu__icon" />
                        <span class="cp-ops-menu__label">{{ __('seo-content-ai::filament.projects.archive_item') }}</span>
                    </button>
                @endif
                @if (! empty($a['has_debug']))
                    <p class="cp-ops-menu__heading cp-ops-menu__heading--nested">Debug lifecycle</p>
                    @if (! empty($a['debug_to_approved']))
                        <button
                            role="menuitem"
                            type="button"
                            @click="open = false; $dispatch('cp-ops-debug-lifecycle', { taskId: {{ $tid }}, to: 'approved', from: @js($lifecycleBucket), title: @js($title), needsAt: false })"
                            class="{{ $itemClass }}"
                            title="Move to Approved"
                        >
                            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Move to Approved</span>
                        </button>
                    @endif
                    @if (! empty($a['debug_to_scheduled']))
                        <button
                            role="menuitem"
                            type="button"
                            @click="open = false; $dispatch('cp-ops-debug-lifecycle', { taskId: {{ $tid }}, to: 'scheduled', from: @js($lifecycleBucket), title: @js($title), needsAt: true, scheduledRaw: @js($row['scheduled_raw'] ?? null) })"
                            class="{{ $itemClass }}"
                            title="Move to Scheduled"
                        >
                            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Move to Scheduled</span>
                        </button>
                    @endif
                    @if (! empty($a['debug_to_published']))
                        <button
                            role="menuitem"
                            type="button"
                            @click="open = false; $dispatch('cp-ops-debug-lifecycle', { taskId: {{ $tid }}, to: 'published', from: @js($lifecycleBucket), title: @js($title), needsAt: false })"
                            class="{{ $itemClass }}"
                            title="Move to Published"
                        >
                            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="cp-ops-menu__icon" />
                            <span class="cp-ops-menu__label">Move to Published</span>
                        </button>
                    @endif
                @endif
            @endif

            @if ($a['view_details'])
                <div class="cp-ops-menu__divider"></div>
                <p class="cp-ops-menu__heading">Other</p>
                <button role="menuitem" type="button" wire:click="openExecutionDetails({{ $tid }})" @click="open = false" class="{{ $itemClass }}" title="View details">
                    <x-filament::icon icon="heroicon-o-information-circle" class="cp-ops-menu__icon" />
                    <span class="cp-ops-menu__label">View details</span>
                </button>
            @endif
        </div>
    </div>
</div>

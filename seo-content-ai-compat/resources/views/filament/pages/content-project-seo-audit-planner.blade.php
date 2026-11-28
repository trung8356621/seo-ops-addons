@php
    /** @var \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner $this */
    $hasDraft = $this->project instanceof \Omnichannel\Addons\ContentProjects\Models\SeoProject
        && $this->project->isDraftPlanning();
    $siteOptions = $this->siteFilterOptions ?? [];
    $draftItems = $hasDraft ? ($this->draftPlanningItems ?? []) : [];
    $draftCounts = $hasDraft ? ($this->draftPlanningCounts ?? ['all' => 0, 'unreviewed' => 0, 'reviewed' => 0]) : ['all' => 0, 'unreviewed' => 0, 'reviewed' => 0];
    $draftAllCount = (int) ($draftCounts['all'] ?? 0);
    $quarantine = $hasDraft ? ($this->draftQuarantineState ?? ['site_id' => null, 'domain' => '', 'count' => 0, 'can_clear' => false]) : ['site_id' => null, 'domain' => '', 'count' => 0, 'can_clear' => false];
    $quarantineSiteId = $quarantine['site_id'] ?? null;
    $quarantineDomain = (string) ($quarantine['domain'] ?? '');
    $quarantineCount = (int) ($quarantine['count'] ?? 0);
    $canClearQuarantine = ! empty($quarantine['can_clear']);
    $quarantineHint = $quarantineSiteId === null
        ? 'Chọn một tên miền cụ thể để gỡ cách ly'
        : ($quarantineCount === 0
            ? 'Không có bài cách ly cho tên miền này'
            : "Gỡ cách ly {$quarantineCount} bài SEO Audit cho {$quarantineDomain}");
    $quarantineConfirmMsg = "Gỡ cách ly {$quarantineCount} quyết định bỏ qua SEO Audit cho tên miền {$quarantineDomain}? Các bài viết sẽ xuất hiện lại trong danh sách đề xuất SEO Audit (không khôi phục task đã xóa).";
@endphp

<x-filament-panels::page>
    <x-seo-content-ai::content-project-ops-styles />

    <div
        class="cp-plan cp-plan-scroll-workspace"
        wire:key="cp-content-planning"
        x-data="cpPlanScrollWorkspace()"
        x-init="init()"
        @destroy="destroy()"
    >
        {{-- Sticky primary Draft toolbar at top of SEO Audit content --}}
        <div
            class="cp-plan-draft-sticky-bar cp-plan-draft-header"
            data-draft-sticky-header="1"
        >
            <div class="cp-plan-draft-header__title">
                <h3 class="cp-plan-draft__title">
                    {{ __('seo-content-ai::filament.projects.planner_draft_items') }}<span class="cp-plan-draft__title-count"> &#183; {{ $draftAllCount }}</span>
                </h3>
            </div>

            @if ($hasDraft)
                <div class="cp-plan-draft-header__actions">
                    <div class="cp-plan-draft-header__domain" data-draft-domain-filter="1">
                        <span class="cp-plan-draft-header__domain-label">{{ __('seo-content-ai::filament.projects.planning_domain_filter') }}</span>
                        <select
                            class="cp-plan-inline-select cp-plan-inline-select--domain-filter"
                            wire:change="setDraftDomainFilter($event.target.value)"
                            wire:loading.attr="disabled"
                            wire:target="setDraftDomainFilter,clearDraftQuarantine"
                            aria-label="{{ __('seo-content-ai::filament.projects.planning_domain_filter') }}"
                        >
                            <option value="all" @selected((string) $this->draftDomainFilter === 'all')>{{ __('seo-content-ai::filament.projects.planning_domain_filter_all') }}</option>
                            <option value="0" @selected((string) $this->draftDomainFilter === '0')>{{ __('seo-content-ai::filament.projects.planning_domain_blank') }}</option>
                            @foreach ($siteOptions as $siteIdOpt => $siteLabelOpt)
                                <option
                                    value="{{ (string) $siteIdOpt }}"
                                    @selected((string) $this->draftDomainFilter === (string) $siteIdOpt)
                                >{{ $siteLabelOpt }}</option>
                            @endforeach
                        </select>
                    </div>

                    @php
                        $autoLimitInfo = $this->autoMonthlyLimitInfo;
                        $limitValid = ! empty($autoLimitInfo['valid']) || ! empty($autoLimitInfo['is_custom']);
                        $stickyTargetMonth = \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::parseOrNull(
                            filled($this->draftSplitTargetMonth ?? null) ? (string) $this->draftSplitTargetMonth : null,
                        ) ?? '';
                        $stickyTargetMonthLabel = $stickyTargetMonth !== ''
                            ? \Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext::display($stickyTargetMonth)
                            : 'Chọn tháng';
                        $totalMissing = $this->totalMissingForPublishMonth;
                        $canPublishFromBar = $stickyTargetMonth !== ''
                            && $limitValid
                            && (int) ($autoLimitInfo['effective_limit'] ?? 0) > 0;
                    @endphp
                    <div class="cp-plan-draft-header__domain" data-draft-monthly-limit="1">
                        <label class="cp-plan-draft-header__domain-label" for="cp-publish-monthly-limit">Giới hạn/tháng</label>
                        <input
                            id="cp-publish-monthly-limit"
                            type="number"
                            min="1"
                            step="1"
                            wire:model.live.debounce.300ms="publishMonthlyLimit"
                            wire:loading.attr="disabled"
                            wire:target="openPublishFromPlanner,confirmDraftSplit"
                            class="cp-plan-inline-select cp-plan-inline-select--limit"
                            style="width: 4.5rem;"
                            aria-label="Giới hạn/tháng"
                        />
                    </div>

                    <div class="cp-plan-draft-header__domain" data-draft-target-month="1">
                        <label class="cp-plan-draft-header__domain-label" for="cp-sticky-publish-month">Tháng Publish</label>
                        <div
                            class="cp-month-picker"
                            data-sticky-field="target_month"
                            data-sticky-target-month="{{ $stickyTargetMonth }}"
                            wire:key="cp-sticky-month-picker-{{ $stickyTargetMonth }}"
                            x-data="Object.assign(cpMonthPicker(), { selected: @entangle('draftSplitTargetMonth').live })"
                            @keydown.escape.window="open = false"
                        >
                            <button
                                type="button"
                                id="cp-sticky-publish-month"
                                class="cp-month-picker__trigger"
                                @click="open = !open"
                                :aria-expanded="open ? 'true' : 'false'"
                                aria-haspopup="dialog"
                            >
                                <span class="cp-month-picker__value" x-text="displayLabel() || 'Chọn tháng'">{{ $stickyTargetMonthLabel }}</span>
                                <svg class="cp-month-picker__icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M6 2a1 1 0 0 0-1 1v1H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1V3a1 1 0 1 0-2 0v1H7V3a1 1 0 0 0-1-1Zm-2 6a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <div
                                x-show="open"
                                x-cloak
                                x-transition.opacity.duration.120ms
                                class="cp-month-picker__popover"
                                role="dialog"
                                aria-label="Tháng Publish"
                                @click.outside="open = false"
                            >
                                <div class="cp-month-picker__year-nav">
                                    <button type="button" class="cp-month-picker__year-btn" @click="prevYear()" aria-label="Previous year">‹</button>
                                    <span class="cp-month-picker__year" x-text="year"></span>
                                    <button type="button" class="cp-month-picker__year-btn" @click="nextYear()" aria-label="Next year">›</button>
                                </div>
                                <div class="cp-month-picker__grid">
                                    <template x-for="m in 12" :key="m">
                                        <button
                                            type="button"
                                            class="cp-month-picker__month"
                                            :class="{ 'is-selected': isSelected(m) }"
                                            @click="pick(m)"
                                            x-text="String(m).padStart(2, '0')"
                                        ></button>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="cp-plan-btn cp-plan-btn--quarantine"
                        data-content-planning-action="clear-quarantine"
                        title="{{ $quarantineHint }}"
                        @disabled(! $canClearQuarantine)
                        wire:loading.attr="disabled"
                        wire:target="clearDraftQuarantine,setDraftDomainFilter"
                        @if ($canClearQuarantine)
                            x-on:click="if (window.confirm(@js($quarantineConfirmMsg))) { $wire.clearDraftQuarantine(); }"
                        @endif
                    >
                        <span>{{ $quarantineCount > 0 ? "Gỡ cách ly ({$quarantineCount})" : 'Gỡ cách ly' }}</span>
                    </button>

                    <button
                        type="button"
                        wire:click="openPublishFromPlanner"
                        wire:loading.attr="disabled"
                        wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit"
                        @disabled(! $canPublishFromBar)
                        @class([
                            'cp-plan-btn cp-plan-btn--publish cp-plan-draft-header__publish',
                            'opacity-50 pointer-events-none' => ! $canPublishFromBar,
                        ])
                        data-content-planning-action="publish"
                        title="{{ $canPublishFromBar ? '' : 'Vui lòng chọn Tháng Publish trước khi Publish' }}"
                    >
                        <svg wire:loading.remove wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 00-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 012-3.95A12.88 12.88 0 0122 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 01-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>
                        <span wire:loading.remove wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit">
                            {{ __('seo-content-ai::filament.projects.content_planning_publish') }}
                        </span>
                        <span wire:loading wire:target="openPublishFromPlanner,openDraftSplitModal,confirmDraftSplit" class="inline-flex items-center gap-1">
                            <svg class="h-4 w-4 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        </span>
                    </button>
                </div>
            @endif
        @if ($showStickyDraftPublish)
            <div class="cp-plan-draft-subrow" data-draft-sticky-subrow="1">
                <span class="{{ $limitValid ? '' : 'text-amber-600 dark:text-amber-400' }}" data-draft-monthly-limit-formula="1">
                    @if (! empty($autoLimitInfo['is_custom']))
                        Hạn mức tùy chỉnh (gốc: {{ $autoLimitInfo['formula_text'] ?? '' }})
                    @else
                        {{ $autoLimitInfo['formula_text'] ?? '' }}
                    @endif
                </span>
                @if ($totalMissing !== null)
                    <span class="font-semibold text-gray-700 dark:text-gray-200 whitespace-nowrap" data-draft-total-missing="1">
                        Còn thiếu: <span class="tabular-nums text-primary-600 dark:text-primary-400">{{ $totalMissing }}</span> bài
                    </span>
                @endif
            </div>
        @endif
        </div>
        {{-- Section 1: Planning workspace (Create / Plan) — Working Site = Global Domain bar --}}
        <section
            id="planner-create"
            class="cp-plan-slide cp-plan-slide--create"
            data-content-planning-section="create"
            aria-label="{{ __('seo-content-ai::filament.projects.content_planning_section_create') }}"
        >
            <x-seo-content-ai::content-project-draft-planner :show-project-actions="false" />

            <button
                type="button"
                class="cp-plan-section-jump"
                data-section-jump="draft"
                @click="jumpToDraft()"
            >
                <span aria-hidden="true">↓</span>
                {{ __('seo-content-ai::filament.projects.content_planning_jump_to_draft', ['count' => $draftAllCount]) }}
            </button>
        </section>

        {{-- Section 2: Planning Draft (Review / Publish) --}}
        <section
            id="planner-draft"
            class="cp-plan-slide cp-plan-slide--draft"
            data-content-planning-section="draft"
            aria-label="{{ __('seo-content-ai::filament.projects.content_planning_section_draft') }}"
        >
            <button
                type="button"
                class="cp-plan-section-jump cp-plan-section-jump--up"
                data-section-jump="create"
                @click="jumpToCreate()"
            >
                <span aria-hidden="true">↑</span>
                {{ __('seo-content-ai::filament.projects.content_planning_jump_to_create') }}
            </button>

            <x-seo-content-ai::content-project-draft-items
                :items="$draftItems"
                :has-draft="$hasDraft"
                :counts="$draftCounts"
                :review-filter="$this->draftReviewFilter"
                :type-filter="$this->draftTypeFilter"
                :draft-domain-filter="$this->draftDomainFilter"
                :selected-ids="$this->selectedTaskIds"
                :refresh-nonce="$this->draftPlanningRefreshNonce"
                :supports-product="$hasDraft ? (bool) ($this->draftSupportsProduct ?? false) : false"
                :site-options="$siteOptions"
                :show-publish-in-header="false"
                :hide-section-title="true"
            />
        </section>
    </div>
</x-filament-panels::page>

@once
    <script>
        window.cpPlanScrollWorkspace = window.cpPlanScrollWorkspace || function () {
            return {
                snapOwner: null,
                prefersReducedMotion() {
                    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                },
                findScrollOwner(startEl) {
                    let node = startEl;
                    while (node && node !== document.documentElement) {
                        const style = window.getComputedStyle(node);
                        const overflowY = style.overflowY;
                        if (
                            (overflowY === 'auto' || overflowY === 'scroll' || overflowY === 'overlay')
                            && node.scrollHeight > node.clientHeight + 1
                        ) {
                            return node;
                        }
                        node = node.parentElement;
                    }

                    return document.scrollingElement || document.documentElement;
                },
                attachSnapOwner() {
                    this.detachSnapOwner();
                    const root = this.$el;
                    if (! root) {
                        return;
                    }
                    const owner = this.findScrollOwner(root);
                    owner.classList.add('cp-plan-snap-owner');
                    this.snapOwner = owner;
                },
                detachSnapOwner() {
                    if (this.snapOwner) {
                        this.snapOwner.classList.remove('cp-plan-snap-owner');
                        this.snapOwner = null;
                    }
                },
                init() {
                    this.attachSnapOwner();
                    this.$nextTick(() => this.attachSnapOwner());
                },
                destroy() {
                    this.detachSnapOwner();
                },
                scrollToSection(id) {
                    const el = document.getElementById(id);
                    if (! el) {
                        return;
                    }
                    el.scrollIntoView({
                        behavior: this.prefersReducedMotion() ? 'auto' : 'smooth',
                        block: 'start',
                    });
                },
                jumpToDraft() {
                    this.scrollToSection('planner-draft');
                },
                jumpToCreate() {
                    this.scrollToSection('planner-create');
                },
            };
        };
    </script>
@endonce

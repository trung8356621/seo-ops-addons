@php
    /** @var \Omnichannel\Addons\ContentProjects\Filament\Pages\ContentProjectSeoAuditPlanner $this */
    $hasDraft = $this->project instanceof \Omnichannel\Addons\ContentProjects\Models\SeoProject
        && $this->project->isDraftPlanning();
    $siteOptions = $this->siteFilterOptions ?? [];
    $draftItems = $hasDraft ? ($this->draftPlanningItems ?? []) : [];
    $draftCounts = $hasDraft ? ($this->draftPlanningCounts ?? ['all' => 0, 'unreviewed' => 0, 'reviewed' => 0]) : ['all' => 0, 'unreviewed' => 0, 'reviewed' => 0];
    $draftAllCount = (int) ($draftCounts['all'] ?? 0);
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
        {{-- Section 1: Planning workspace (Create / Plan) — Working Site = Global Domain bar --}}
        <section
            id="planner-create"
            class="cp-plan-slide cp-plan-slide--create"
            data-content-planning-section="create"
            aria-label="{{ __('seo-content-ai::filament.projects.content_planning_section_create') }}"
        >
            <x-seo-content-ai::content-project-draft-planner :show-project-actions="false" />

            @if ($hasDraft)
                <button
                    type="button"
                    class="cp-plan-section-jump"
                    data-section-jump="draft"
                    @click="jumpToDraft()"
                >
                    <span aria-hidden="true">↓</span>
                    {{ __('seo-content-ai::filament.projects.content_planning_jump_to_draft', ['count' => $draftAllCount]) }}
                </button>
            @endif
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
                :show-publish-in-header="true"
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

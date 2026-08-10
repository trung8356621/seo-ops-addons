<x-filament-panels::page
    @class([
        'fi-resource-edit-record-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
        'fi-resource-record-' . $record->getKey(),
        'seo-site-mcp-edit-page',
        'seo-site-mcp-edit-page--draft-open' => $this->siteMcpDraftPanelOpen,
    ])
>
    @php
        $siteMcpPreview = $this->getSiteMcpDraftPreview();
    @endphp

    <style>
        /* Desktop: shrink form so fixed draft drawer sits beside it */
        @media (min-width: 1024px) {
            .seo-site-mcp-edit-page--draft-open .seo-site-mcp-edit-layout__form {
                max-width: calc(100% - min(42vw, 36rem) - 1rem);
            }
        }

        .seo-site-mcp-draft-drawer {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            z-index: 80;
            width: min(100vw, 28rem);
            display: flex;
            flex-direction: column;
            background: #fff;
            border-left: 1px solid #e5e7eb;
            box-shadow: -8px 0 24px rgb(15 23 42 / 12%);
        }
        .dark .seo-site-mcp-draft-drawer {
            background: #111827;
            border-left-color: #374151;
        }
        @media (min-width: 1024px) {
            .seo-site-mcp-draft-drawer {
                top: 4.25rem;
                width: min(42vw, 36rem);
            }
        }
        .seo-site-mcp-draft-drawer__card {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 0;
            overflow: hidden;
        }
        .seo-site-mcp-draft-drawer .seo-site-mcp-draft-panel__body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            -webkit-overflow-scrolling: touch;
        }
        .seo-site-mcp-draft-drawer-backdrop {
            position: fixed;
            inset: 0;
            z-index: 70;
            background: rgb(3 7 18 / 40%);
        }
        @media (min-width: 1024px) {
            .seo-site-mcp-draft-drawer-backdrop {
                display: none;
            }
        }
    </style>

    <div class="seo-site-mcp-edit-layout">
        <div class="seo-site-mcp-edit-layout__form min-w-0">
            @capture($form)
                <x-filament-panels::form
                    id="form"
                    :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
                    wire:submit="save"
                >
                    {{ $this->form }}

                    <x-filament-panels::form.actions
                        :actions="$this->getCachedFormActions()"
                        :full-width="$this->hasFullWidthFormActions()"
                    />
                </x-filament-panels::form>
            @endcapture

            @php
                $relationManagers = $this->getRelationManagers();
                $hasCombinedRelationManagerTabsWithContent = $this->hasCombinedRelationManagerTabsWithContent();
            @endphp

            @if ((! $hasCombinedRelationManagerTabsWithContent) || (! count($relationManagers)))
                {{ $form() }}
            @endif

            @if (count($relationManagers))
                <x-filament-panels::resources.relation-managers
                    :active-locale="isset($activeLocale) ? $activeLocale : null"
                    :active-manager="$this->activeRelationManager ?? ($hasCombinedRelationManagerTabsWithContent ? null : array_key_first($relationManagers))"
                    :content-tab-label="$this->getContentTabLabel()"
                    :content-tab-icon="$this->getContentTabIcon()"
                    :content-tab-position="$this->getContentTabPosition()"
                    :managers="$relationManagers"
                    :owner-record="$record"
                    :page-class="static::class"
                >
                    @if ($hasCombinedRelationManagerTabsWithContent)
                        <x-slot name="content">
                            {{ $form() }}
                        </x-slot>
                    @endif
                </x-filament-panels::resources.relation-managers>
            @endif
        </div>
    </div>

    @if ($this->siteMcpDraftPanelOpen)
        <div
            class="seo-site-mcp-draft-drawer-backdrop"
            wire:click="closeSiteMcpDraftPanel"
            wire:key="site-mcp-draft-backdrop"
        ></div>
        <aside
            class="seo-site-mcp-draft-drawer"
            wire:key="site-mcp-draft-drawer-{{ $this->siteMcpDraftPanelOpen ? 'open' : 'closed' }}"
            aria-label="{{ __('Site MCP Draft') }}"
        >
            <div class="seo-site-mcp-draft-drawer__card">
                @include('seo-content-ai::filament.resources.domain-resource.pages.partials.site-mcp-draft-panel', [
                    'preview' => $siteMcpPreview,
                ])
            </div>
        </aside>
    @endif

    <x-filament-panels::page.unsaved-data-changes-alert />
</x-filament-panels::page>

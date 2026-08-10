@php
    $domainOptions = $this->rankGroupDomainOptions;
    $isCustomDomain = $groupFormTargetDomainChoice === \Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoPerformanceHub::TARGET_DOMAIN_CUSTOM;
@endphp

<div
    x-data="{
        open: false,
        modalMode: 'create',
        localLoading: false,
        createTitle: @js(__('seo-content-ai::filament.rank_group.create_heading')),
        editTitle: @js(__('seo-content-ai::filament.rank_group.edit_heading')),
        createSubmit: @js(__('seo-content-ai::filament.rank_group.create_submit')),
        editSubmit: @js(__('seo-content-ai::filament.rank_group.save_changes')),
        closeModal() {
            this.open = false;
            $wire.closeGroupModal();
        },
        openModal(groupId) {
            const id = groupId !== null && groupId !== undefined && Number(groupId) > 0 ? Number(groupId) : null;
            this.modalMode = id ? 'edit' : 'create';
            this.localLoading = id !== null;
            this.open = true;
            $wire.openGroupModal(id);
            if (id !== null) {
                $wire.loadGroupModalData(id).finally(() => {
                    this.localLoading = false;
                });
            }
        },
        isLoading() {
            return this.localLoading || $wire.groupModalLoading;
        },
    }"
    x-on:open-rank-group-modal.window="openModal($event.detail.groupId ?? null)"
    x-on:close-rank-group-modal.window="open = false"
    x-show="open"
    x-cloak
    x-transition:enter="performance-hub-modal-enter"
    x-transition:enter-start="performance-hub-modal-enter-start"
    x-transition:enter-end="performance-hub-modal-enter-end"
    x-transition:leave="performance-hub-modal-leave"
    x-transition:leave-start="performance-hub-modal-leave-start"
    x-transition:leave-end="performance-hub-modal-leave-end"
    class="performance-hub-modal-backdrop"
    role="dialog"
    aria-modal="true"
    aria-labelledby="rank-group-modal-title"
>
    <div
        class="performance-hub-modal performance-hub-modal--group"
        @click.outside="if (!$wire.groupModalSubmitting && !$wire.groupModalLoading) closeModal()"
    >
        <div class="performance-hub-modal__head">
            <div>
                <h2 id="rank-group-modal-title" class="performance-hub-modal__title" x-text="modalMode === 'edit' ? editTitle : createTitle"></h2>
                <p class="performance-hub-modal__subtitle">{{ __('seo-content-ai::filament.rank_group.modal_subtitle') }}</p>
            </div>
            <button
                type="button"
                class="performance-hub-icon-btn"
                @click="closeModal()"
                :disabled="$wire.groupModalSubmitting"
                aria-label="{{ __('seo-content-ai::filament.rank_group.cancel') }}"
            >×</button>
        </div>

        <div class="performance-hub-modal__body performance-hub-modal__body--scroll">
            <div x-show="$wire.groupModalLoadError" x-cloak class="performance-hub-modal-error" role="alert">
                <p x-text="$wire.groupModalLoadError"></p>
                <div class="performance-hub-modal-error__actions">
                    <button
                        type="button"
                        class="performance-hub-action-btn performance-hub-action-btn--secondary"
                        wire:click="retryLoadGroupModal"
                        wire:loading.attr="disabled"
                        wire:target="retryLoadGroupModal,loadGroupModalData"
                    >
                        <span wire:loading.remove wire:target="retryLoadGroupModal,loadGroupModalData">{{ __('seo-content-ai::filament.rank_group.retry_load') }}</span>
                        <span wire:loading wire:target="retryLoadGroupModal,loadGroupModalData">{{ __('seo-content-ai::filament.rank_group.loading') }}</span>
                    </button>
                    <button type="button" class="performance-hub-action-btn performance-hub-action-btn--secondary" @click="closeModal()">{{ __('seo-content-ai::filament.rank_group.close') }}</button>
                </div>
            </div>

            <div x-show="!$wire.groupModalLoadError && isLoading()" x-cloak class="performance-hub-modal-skeleton" aria-busy="true" aria-live="polite">
                <div class="performance-hub-form-grid performance-hub-form-grid--2 performance-hub-form-grid--name-device">
                    <div class="performance-hub-skeleton-line"></div>
                    <div class="performance-hub-skeleton-line"></div>
                </div>
                <div class="performance-hub-skeleton-block performance-hub-skeleton-block--sm"></div>
                <div class="performance-hub-form-grid performance-hub-form-grid--3">
                    <div class="performance-hub-skeleton-line"></div>
                    <div class="performance-hub-skeleton-line"></div>
                    <div class="performance-hub-skeleton-line"></div>
                </div>
                <div class="performance-hub-form-grid performance-hub-form-grid--domain">
                    <div class="performance-hub-skeleton-line"></div>
                    <div class="performance-hub-skeleton-line"></div>
                </div>
                <div class="performance-hub-skeleton-block"></div>
            </div>

            <div x-show="!$wire.groupModalLoadError && !isLoading()" x-cloak class="performance-hub-form-stack">
                <div class="performance-hub-form-grid performance-hub-form-grid--2 performance-hub-form-grid--name-device">
                    <div class="performance-hub-form-field">
                        <label for="group-name">{{ __('seo-content-ai::filament.rank_group.name') }} <span class="performance-hub-required">*</span></label>
                        <input id="group-name" type="text" wire:model.defer="groupFormName" class="performance-hub-input" required />
                    </div>
                    <div class="performance-hub-form-field">
                        <label for="group-device">{{ __('seo-content-ai::filament.rank_group.device') }}</label>
                        <x-select id="group-device" wire:model.defer="groupFormDevice" class="performance-hub-select">
                            <option value="desktop">{{ __('seo-content-ai::filament.performance_hub.device_desktop') }}</option>
                            <option value="mobile">{{ __('seo-content-ai::filament.performance_hub.device_mobile') }}</option>
                        </x-select>
                    </div>
                </div>

                <div class="performance-hub-form-field">
                    <label for="group-description">{{ __('seo-content-ai::filament.rank_group.description') }}</label>
                    <textarea id="group-description" wire:model.defer="groupFormDescription" rows="3" class="performance-hub-textarea" placeholder="{{ __('seo-content-ai::filament.rank_group.description_placeholder') }}"></textarea>
                </div>

                <div class="performance-hub-form-grid performance-hub-form-grid--3">
                    <div class="performance-hub-form-field">
                        <label for="group-country">{{ __('seo-content-ai::filament.rank_group.country') }}</label>
                        <x-select id="group-country" wire:model.defer="groupFormCountry" class="performance-hub-select">
                            <option value="vn">{{ __('seo-content-ai::filament.performance_hub.country_vn') }}</option>
                            <option value="us">US</option>
                            <option value="gb">GB</option>
                            <option value="sg">SG</option>
                        </x-select>
                    </div>
                    <div class="performance-hub-form-field">
                        <label for="group-language">{{ __('seo-content-ai::filament.rank_group.language') }}</label>
                        <x-select id="group-language" wire:model.defer="groupFormLanguage" class="performance-hub-select">
                            <option value="vi">{{ __('seo-content-ai::filament.performance_hub.language_vi') }}</option>
                            <option value="en">English</option>
                        </x-select>
                    </div>
                    <div class="performance-hub-form-field">
                        <label for="group-location">{{ __('seo-content-ai::filament.rank_group.location') }}</label>
                        <input id="group-location" type="text" wire:model.defer="groupFormLocation" class="performance-hub-input" placeholder="{{ __('seo-content-ai::filament.performance_hub.filter_location_placeholder') }}" />
                    </div>
                </div>
                <p class="performance-hub-form-hint performance-hub-form-hint--tight">{{ __('seo-content-ai::filament.rank_group.location_hint') }}</p>

                <div class="performance-hub-form-grid performance-hub-form-grid--domain">
                    <div class="performance-hub-form-field">
                        <label for="group-target-domain">{{ __('seo-content-ai::filament.rank_group.target_domain') }}</label>
                        <x-select id="group-target-domain" wire:model.live="groupFormTargetDomainChoice" class="performance-hub-select">
                            <option value="">{{ __('seo-content-ai::filament.rank_group.target_domain_none') }}</option>
                            @foreach ($domainOptions as $domain)
                                <option value="{{ $domain }}">{{ $domain }}</option>
                            @endforeach
                            <option value="{{ \Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoPerformanceHub::TARGET_DOMAIN_CUSTOM }}">{{ __('seo-content-ai::filament.rank_group.target_domain_custom') }}</option>
                        </x-select>
                        <p class="performance-hub-form-hint">{{ __('seo-content-ai::filament.rank_group.target_domain_hint') }}</p>
                    </div>
                    @if ($isCustomDomain)
                        <div class="performance-hub-form-field">
                            <label for="group-target-domain-custom">{{ __('seo-content-ai::filament.rank_group.target_domain_custom_label') }}</label>
                            <input id="group-target-domain-custom" type="text" wire:model.defer="groupFormTargetDomainCustom" class="performance-hub-input" placeholder="example.com" />
                        </div>
                    @endif
                </div>

                <div class="performance-hub-form-field">
                    <div class="performance-hub-form-field__label-row">
                        <label for="group-keywords">{{ __('seo-content-ai::filament.rank_group.keywords') }}</label>
                        <span class="performance-hub-keyword-count">{{ __('seo-content-ai::filament.rank_group.keyword_count', ['count' => $this->groupFormKeywordCount]) }}</span>
                    </div>
                    <textarea id="group-keywords" wire:model.live="groupFormKeywordsText" rows="8" class="performance-hub-textarea" placeholder="{{ __('seo-content-ai::filament.rank_group.keywords_placeholder') }}"></textarea>
                    <p class="performance-hub-form-hint">{{ __('seo-content-ai::filament.rank_group.keywords_hint') }}</p>
                </div>
            </div>
        </div>

        <div class="performance-hub-modal__actions performance-hub-modal__actions--sticky">
            <button
                type="button"
                class="performance-hub-action-btn performance-hub-action-btn--secondary"
                @click="closeModal()"
                :disabled="$wire.groupModalSubmitting"
            >{{ __('seo-content-ai::filament.rank_group.cancel') }}</button>
            <button
                type="button"
                wire:click="saveGroupModal"
                wire:loading.attr="disabled"
                wire:target="saveGroupModal"
                class="performance-hub-action-btn"
                :disabled="isLoading() || $wire.groupModalSubmitting || $wire.groupModalLoadError"
            >
                <span wire:loading.remove wire:target="saveGroupModal" x-text="modalMode === 'edit' ? editSubmit : createSubmit"></span>
                <span wire:loading wire:target="saveGroupModal">{{ __('seo-content-ai::filament.rank_group.saving') }}</span>
            </button>
        </div>
    </div>
</div>

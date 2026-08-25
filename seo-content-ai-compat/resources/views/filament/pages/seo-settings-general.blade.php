<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'general'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('seo-content-ai::filament.settings_general.page_title') }}</h1>
                    <p>{{ __('seo-content-ai::filament.settings_general.page_description') }}</p>
                </header>

                <section class="seo-ai-models-panel mt-2 space-y-2">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('seo-content-ai::filament.settings_general.section_regional') }}
                    </h2>
                    <form wire:submit="save" class="max-w-3xl space-y-6">
                        {{ $this->form }}

                        <div class="flex justify-end">
                            <x-seo-content-ai::form-save-button
                                target="save"
                                :label="__('seo-content-ai::filament.settings_datetime.save')"
                            />
                        </div>
                    </form>
                </section>

                <section class="seo-rec-overview-teaser mt-8">
                    <div class="seo-rec-overview-teaser__content">
                        <h2 class="seo-rec-overview-teaser__title">
                            <x-filament::icon icon="heroicon-o-book-open" class="seo-rec-overview-teaser__icon" />
                            {{ __('seo-content-ai::filament.settings_recommendations.overview_teaser_title') }}
                        </h2>
                        <p class="seo-rec-overview-teaser__body">
                            {{ __('seo-content-ai::filament.settings_recommendations.overview_teaser_body') }}
                        </p>
                    </div>
                    <x-filament::button
                        tag="a"
                        :href="\Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsRecommendations::getUrl()"
                        color="gray"
                        outlined
                        icon="heroicon-o-arrow-right"
                        icon-position="after"
                    >
                        {{ __('seo-content-ai::filament.settings_recommendations.overview_teaser_link') }}
                    </x-filament::button>
                </section>

                <section class="seo-ai-models-panel mt-6 space-y-2">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('seo-content-ai::filament.settings_general.section_workspace') }}
                    </h2>
                    <form wire:submit="saveTeamChatSettings">
                        {{ $this->teamChatForm }}

                        <div class="mt-4">
                            <x-seo-content-ai::form-save-button
                                target="saveTeamChatSettings"
                                :label="__('seo-content-ai::filament.settings_overview.team_chat_save')"
                            />
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </x-filament-panels::page>
</div>

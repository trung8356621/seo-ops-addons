<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'overview'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('Overview') }}</h1>
                    <p>{{ __('General settings for this SEO workspace.') }}</p>
                </header>

                <section class="seo-rec-overview-teaser">
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

                <section class="seo-ai-models-panel mt-6">
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

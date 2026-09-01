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

                <section class="seo-ai-models-panel mt-6 space-y-2">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                        {{ __('seo-content-ai::filament.settings_general.section_social_reporting') }}
                    </h2>
                    <form wire:submit="saveSocialSettings">
                        {{ $this->socialSettingsForm }}

                        <div class="mt-4">
                            <x-seo-content-ai::form-save-button
                                target="saveSocialSettings"
                                :label="__('seo-content-ai::filament.settings_general.social_supports_save')"
                            />
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </x-filament-panels::page>
</div>

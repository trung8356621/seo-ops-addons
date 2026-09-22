<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'general'])

            <div class="seo-settings-main seo-settings-main--with-global-save">
                <header class="seo-settings-header">
                    <h1>{{ __('seo-content-ai::filament.settings_general.page_title') }}</h1>
                    <p>{{ __('seo-content-ai::filament.settings_general.page_description') }}</p>
                </header>

                <form wire:submit="save" class="seo-settings-general-form">
                    <section class="seo-ai-models-panel mt-2 space-y-2">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ __('seo-content-ai::filament.settings_general.section_regional') }}
                        </h2>
                        <div class="max-w-3xl space-y-6">
                            {{ $this->form }}
                        </div>
                    </section>

                    <section class="seo-ai-models-panel mt-6 space-y-2">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ __('seo-content-ai::filament.settings_general.section_workspace') }}
                        </h2>
                        <div>
                            {{ $this->teamChatForm }}
                        </div>
                    </section>

                    <section class="seo-ai-models-panel mt-6 space-y-2">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                            {{ __('seo-content-ai::filament.settings_general.section_social_reporting') }}
                        </h2>
                        <div>
                            {{ $this->socialSettingsForm }}
                        </div>
                    </section>

                    <div class="seo-settings-global-save" data-seo-settings-global-save>
                        <x-seo-content-ai::form-save-button
                            target="save"
                            :label="__('seo-content-ai::filament.settings_general.save')"
                        />
                    </div>
                </form>
            </div>
        </div>
    </x-filament-panels::page>
</div>

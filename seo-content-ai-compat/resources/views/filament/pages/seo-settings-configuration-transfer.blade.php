<div>
    <x-filament-panels::page>
        <div class="seo-settings-root">
            @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'import-export'])

            <div class="seo-settings-main">
                <header class="seo-settings-header">
                    <h1>{{ __('seo-content-ai::filament.settings_transfer.title') }}</h1>
                    <p>{{ __('seo-content-ai::filament.settings_transfer.intro') }}</p>
                </header>

                <p class="mb-4 text-sm text-amber-700">{{ __('seo-content-ai::filament.settings_transfer.not_backup') }}</p>

                <nav class="seo-ai-segment mb-6" aria-label="{{ __('seo-content-ai::filament.settings_transfer.title') }}">
                    <button type="button" wire:click="$set('intent', 'export')" @class(['seo-ai-segment__item', 'is-active' => $intent === 'export'])>
                        {{ __('seo-content-ai::filament.settings_transfer.export') }}
                    </button>
                    <button type="button" wire:click="$set('intent', 'import')" @class(['seo-ai-segment__item', 'is-active' => $intent === 'import'])>
                        {{ __('seo-content-ai::filament.settings_transfer.import') }}
                    </button>
                </nav>

                @if ($intent === 'export')
                    @if ($focus !== 'prompts')
                        <section class="mb-4">
                            <h2 class="mb-2 font-semibold">{{ __('seo-content-ai::filament.settings_transfer.sections') }}</h2>
                            <div class="seo-ai-export-grid">
                            @foreach ($exportSections as $key => $on)
                                <label class="text-sm">
                                    <input type="checkbox" wire:model="exportSections.{{ $key }}" />
                                    {{ $this->sectionLabels()[$key] ?? $key }}
                                </label>
                            @endforeach
                            <label class="text-sm text-gray-500">
                                <input type="checkbox" disabled />
                                {{ __('seo-content-ai::filament.settings_transfer.section_recommendations') }}
                            </label>
                            <label class="text-sm">
                                <input type="checkbox" wire:model="includePrompts" />
                                {{ __('seo-content-ai::filament.settings_transfer.include_prompts') }}
                            </label>
                            </div>
                        </section>
                    @else
                        <p class="mb-4 text-sm">{{ __('seo-content-ai::filament.settings_transfer.export_all_prompts') }}</p>
                    @endif
                    <p class="mb-4 text-sm text-gray-600">{{ __('seo-content-ai::filament.settings_transfer.secrets_never') }}</p>
                    <x-filament::button wire:click="downloadExport">{{ __('seo-content-ai::filament.settings_transfer.download') }}</x-filament::button>
                @endif

                @if ($intent === 'import')
                    @if ($focus !== 'prompts')
                        <section class="mb-4 space-y-1">
                            <p class="text-sm font-medium">{{ __('seo-content-ai::filament.settings_transfer.import_mode') }}</p>
                            <label class="block text-sm"><input type="radio" wire:model="mode" value="merge" /> {{ __('seo-content-ai::filament.settings_transfer.merge') }}</label>
                            <label class="block text-sm"><input type="radio" wire:model="mode" value="replace" /> {{ __('seo-content-ai::filament.settings_transfer.replace') }}</label>
                        </section>
                    @else
                        <section class="mb-4 space-y-1">
                            <p class="text-sm font-medium">{{ __('seo-content-ai::filament.settings_transfer.conflicts') }}</p>
                            <label class="block text-sm"><input type="radio" wire:model="bulkPolicy" value="update" /> Update</label>
                            <label class="block text-sm"><input type="radio" wire:model="bulkPolicy" value="copy" /> Copy</label>
                            <label class="block text-sm"><input type="radio" wire:model="bulkPolicy" value="skip" /> Skip</label>
                        </section>
                    @endif

                    <div
                        class="mb-4 rounded-xl border-2 border-dashed border-gray-300 p-8 text-center dark:border-gray-600"
                        x-data="{ dragging: false }"
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="
                            dragging = false;
                            const input = $refs.importFile;
                            input.files = $event.dataTransfer.files;
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                        "
                        x-bind:class="dragging ? 'border-primary-500 bg-primary-50/40' : ''"
                    >
                        <p class="mb-2 text-sm font-medium">{{ __('seo-content-ai::filament.settings_transfer.drop_json') }}</p>
                        <p class="mb-3 text-xs text-gray-500">{{ __('seo-content-ai::filament.settings_transfer.or') }}</p>
                        <label class="seo-ai-file-btn">
                            {{ __('seo-content-ai::filament.settings_transfer.choose_json') }}
                            <input
                                x-ref="importFile"
                                type="file"
                                accept=".json,application/json"
                                wire:model="importFile"
                                class="sr-only"
                            />
                        </label>
                        <p class="mt-2 text-xs text-gray-500">{{ __('seo-content-ai::filament.settings_transfer.accepted_json') }}</p>
                    </div>

                    @if (is_array($importMeta))
                        <dl class="mb-4 grid max-w-lg grid-cols-2 gap-2 text-sm">
                            <dt class="text-gray-500">{{ __('seo-content-ai::filament.settings_transfer.file') }}</dt>
                            <dd>{{ $importMeta['filename'] ?? '' }}</dd>
                            <dt class="text-gray-500">{{ __('seo-content-ai::filament.settings_transfer.package') }}</dt>
                            <dd>{{ $importMeta['package_type'] ?? '' }}</dd>
                            <dt class="text-gray-500">{{ __('seo-content-ai::filament.settings_transfer.schema') }}</dt>
                            <dd>{{ $importMeta['schema_version'] ?? '' }}</dd>
                            <dt class="text-gray-500">{{ __('seo-content-ai::filament.settings_transfer.size') }}</dt>
                            <dd>{{ number_format((int) ($importMeta['size'] ?? 0) / 1024, 1) }} KB</dd>
                            <dt class="text-gray-500">{{ __('seo-content-ai::filament.settings_transfer.sections') }}</dt>
                            <dd>{{ implode(', ', $importMeta['sections'] ?? []) ?: '—' }}</dd>
                        </dl>
                        <x-filament::button color="gray" wire:click="previewImport">{{ __('seo-content-ai::filament.settings_transfer.preview') }}</x-filament::button>
                    @endif

                    @if (is_array($preview))
                        <div class="mt-4 space-y-2 rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-700">
                            <p>{{ count($preview['sections'] ?? []) }} {{ __('seo-content-ai::filament.settings_transfer.sections_detected') }}</p>
                            <p>{{ count($preview['prompts'] ?? []) }} prompts</p>
                            @foreach ($preview['sections'] ?? [] as $section)
                                <div>
                                    @if (!empty($section['key']))
                                        <label class="inline-flex items-center gap-2">
                                            <input type="checkbox" wire:model="exportSections.{{ $section['key'] }}" />
                                            <strong>{{ $this->sectionLabels()[$section['key']] ?? $section['key'] }}</strong>
                                        </label>
                                    @endif
                                    — {{ $section['changed'] ?? 0 }} changed / {{ $section['unchanged'] ?? 0 }} unchanged
                                    @foreach ($section['lines'] ?? [] as $line)
                                        <div>{{ $line }}</div>
                                    @endforeach
                                </div>
                            @endforeach
                            @foreach ($preview['prompts'] ?? [] as $row)
                                <div>{{ $row['name'] ?? '' }} · {{ $row['action'] ?? '' }} @if(!empty($row['conflict'])) ({{ $row['conflict'] }}) @endif</div>
                            @endforeach
                            @foreach ($preview['warnings'] ?? [] as $warning)
                                <p class="text-amber-700">{{ $warning }}</p>
                            @endforeach
                            <x-filament::button class="mt-2" wire:click="applyImport">{{ __('seo-content-ai::filament.settings_transfer.apply') }}</x-filament::button>
                        </div>
                    @endif

                    <details class="mt-8 text-sm text-gray-500" @if($showAdvancedPaste) open @endif>
                        <summary class="cursor-pointer">{{ __('seo-content-ai::filament.settings_transfer.advanced_paste') }}</summary>
                        <textarea wire:model="importJson" rows="8" class="mt-2 w-full rounded-lg border-gray-300 font-mono text-xs dark:bg-gray-900"></textarea>
                        <x-filament::button class="mt-2" size="sm" color="gray" wire:click="previewImport">{{ __('seo-content-ai::filament.settings_transfer.preview') }}</x-filament::button>
                    </details>
                @endif
            </div>
        </div>
    </x-filament-panels::page>
</div>

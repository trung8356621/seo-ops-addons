<x-filament-panels::page>
    @php
        $workflows = $this->workflows();
        $widgets = $this->widgetOptions();
    @endphp

    <div class="space-y-6 max-w-5xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('social-automation::filament.workflows.intro') }}</p>

        @if ($this->flash)
            <div class="rounded border border-teal-200 bg-teal-50 p-3 text-sm">{{ $this->flash }}</div>
        @endif
        @if ($this->error)
            <div class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $this->error }}</div>
        @endif

        <div class="rounded-lg border border-gray-200 p-4 space-y-3 dark:border-gray-700">
            <h2 class="font-semibold text-sm">Workflow builder (chuỗi Widget tuần tự)</h2>
            <div class="grid gap-3 md:grid-cols-3">
                <label class="text-sm">Package key<input wire:model="packageKey" class="mt-1 w-full rounded border-gray-300 text-sm dark:bg-gray-900" /></label>
                <label class="text-sm">Name<input wire:model="name" class="mt-1 w-full rounded border-gray-300 text-sm dark:bg-gray-900" /></label>
                <label class="text-sm">Version<input wire:model="version" class="mt-1 w-full rounded border-gray-300 text-sm dark:bg-gray-900" /></label>
            </div>
            <label class="text-sm block">Steps JSON (widget_key + bindings)
                <textarea wire:model="stepsJson" rows="10" class="mt-1 w-full rounded border-gray-300 font-mono text-xs dark:bg-gray-900"></textarea>
            </label>
            <p class="text-xs text-gray-500">Widgets khả dụng: {{ collect($widgets)->map(fn ($w) => $w['package_key'].($w['is_enabled'] ? '' : ' [disabled]'))->implode(', ') ?: '—' }}</p>
            <div class="flex gap-2">
                <button type="button" wire:click="validateSteps" class="rounded bg-gray-800 px-3 py-1.5 text-sm text-white">Validate</button>
                <button type="button" wire:click="publish" class="rounded bg-teal-700 px-3 py-1.5 text-sm text-white">Publish Workflow</button>
            </div>
        </div>

        @if ($workflows === [])
            <div class="rounded-lg border border-dashed p-6 text-sm text-gray-500">{{ __('social-automation::filament.workflows.empty') }}</div>
        @else
            <div class="space-y-3">
                @foreach ($workflows as $row)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                        <div class="flex justify-between gap-3">
                            <div>
                                <div class="font-medium">{{ $row['name'] }}</div>
                                <div class="font-mono text-xs text-gray-500">{{ $row['package_key'] }} @ {{ $row['version'] ?? '—' }}</div>
                            </div>
                            <div class="text-sm">
                                @if ($row['dispatchable'])
                                    <span class="text-teal-700">dispatchable</span>
                                @else
                                    <span class="text-amber-600">blocked</span>
                                @endif
                            </div>
                        </div>
                        <ol class="mt-2 list-decimal pl-5 text-sm space-y-1">
                            @foreach ($row['steps'] as $step)
                                <li>
                                    {{ $step['widget_key'] ?? $step['package_key'] ?? 'step' }}
                                    @if (!empty($step['output_bindings']))
                                        <span class="text-xs text-gray-500">bindings {{ json_encode($step['output_bindings']) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                        @if (($row['disabled_steps'] ?? []) !== [])
                            <div class="mt-2 text-xs text-amber-700">
                                Disabled widgets:
                                @foreach ($row['disabled_steps'] as $d)
                                    {{ $d['widget_key'] }} ({{ $d['disabled_reason'] }})
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>

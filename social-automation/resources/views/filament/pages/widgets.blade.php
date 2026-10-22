<x-filament-panels::page>
    @php
        $widgets = $this->widgets();
        $scripts = $this->scriptOptions();
    @endphp

    <div class="space-y-6 max-w-5xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('social-automation::filament.widgets.intro') }}</p>

        @if ($this->flash)
            <div class="rounded border border-teal-200 bg-teal-50 p-3 text-sm text-teal-900 dark:border-teal-800 dark:bg-teal-950 dark:text-teal-100">{{ $this->flash }}</div>
        @endif
        @if ($this->error)
            <div class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $this->error }}</div>
        @endif

        <div class="rounded-lg border border-gray-200 p-4 space-y-3 dark:border-gray-700">
            <h2 class="font-semibold text-sm">Tạo / cập nhật Widget (publish release)</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="text-sm">Package key
                    <input wire:model="packageKey" class="mt-1 w-full rounded border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900" />
                </label>
                <label class="text-sm">Name
                    <input wire:model="name" class="mt-1 w-full rounded border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900" />
                </label>
                <label class="text-sm">Version
                    <input wire:model="version" class="mt-1 w-full rounded border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900" />
                </label>
                <label class="text-sm">Script
                    <select wire:model.live="scriptKey" class="mt-1 w-full rounded border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900">
                        <option value="">— chọn Script —</option>
                        @foreach ($scripts as $script)
                            <option value="{{ $script['package_key'] }}">{{ $script['name'] }} ({{ $script['version'] }})</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <label class="text-sm block">Form fields JSON
                <textarea wire:model="formFieldsJson" rows="8" class="mt-1 w-full rounded border-gray-300 font-mono text-xs dark:border-gray-600 dark:bg-gray-900"></textarea>
            </label>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="previewCompose" wire:loading.attr="disabled" class="rounded bg-gray-800 px-3 py-1.5 text-sm text-white dark:bg-gray-200 dark:text-gray-900">Preview form / compose</button>
                <button type="button" wire:click="publish" wire:loading.attr="disabled" class="rounded bg-teal-700 px-3 py-1.5 text-sm text-white">Publish Widget</button>
            </div>
            @if ($this->previewJson)
                <pre class="overflow-auto rounded bg-gray-50 p-2 text-xs dark:bg-gray-900">{{ $this->previewJson }}</pre>
            @endif
        </div>

        @if ($widgets === [])
            <div class="rounded-lg border border-dashed p-6 text-sm text-gray-500">{{ __('social-automation::filament.widgets.empty') }}</div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left">Widget</th>
                            <th class="px-3 py-2 text-left">Script pin</th>
                            <th class="px-3 py-2 text-left">Enabled</th>
                            <th class="px-3 py-2 text-left">Reason</th>
                            <th class="px-3 py-2 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($widgets as $row)
                            <tr>
                                <td class="px-3 py-2">
                                    <div>{{ $row['name'] }}</div>
                                    <div class="font-mono text-xs text-gray-500">{{ $row['package_key'] }} @ {{ $row['version'] ?? '—' }}</div>
                                </td>
                                <td class="px-3 py-2 text-xs font-mono">
                                    {{ $row['script_key'] ?? '—' }}@{{ $row['script_version'] ?? '' }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($row['is_enabled'])
                                        <span class="text-teal-700">enabled</span>
                                    @else
                                        <span class="text-amber-600">disabled</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs">
                                    {{ $row['disabled_reason'] ?? '—' }}
                                    @if (!empty($row['disabled_meta']))
                                        <details><summary>meta</summary><pre>{{ json_encode($row['disabled_meta'], JSON_PRETTY_PRINT) }}</pre></details>
                                    @endif
                                </td>
                                <td class="px-3 py-2 space-x-2">
                                    @if ($row['is_enabled'])
                                        <button type="button" wire:click="disableWidget(@js($row['package_key']))" class="text-sm text-amber-700">Disable</button>
                                    @else
                                        <button type="button" wire:click="enableWidget(@js($row['package_key']))" class="text-sm text-teal-700">Re-enable</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>

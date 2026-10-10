<x-filament-panels::page>
    <div class="seo-settings-root">
        @include('seo-content-ai::filament.pages.partials.seo-settings-sidebar', ['active' => 'semantic-routing'])
        <div class="seo-settings-main space-y-4">
            <header class="seo-settings-header">
                <h1>Semantic Routing</h1>
                <p>Trạng thái tích hợp Agent do service sở hữu. Cấu hình định tuyến chỉ đọc và được nạp từ các file đã đăng ký.</p>
            </header>
        @if($persistedCompatibility['present'])
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                Legacy persisted semantic routing revision {{ $persistedCompatibility['revision'] }} is preserved for compatibility but is not authoritative. Service-owned JSON is active.
            </div>
        @endif
        <div class="flex flex-wrap gap-3">
            <select wire:model.live="level" class="rounded-md border-gray-300 text-sm">
                <option value="global">Global JEV</option>
                <option value="module">Internal Module JEV</option>
            </select>
            @if($level === 'module')
                <select wire:model.live="module" class="rounded-md border-gray-300 text-sm">
                    @foreach($this->moduleOptions() as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-200">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-left">
                    <tr>
                        <th class="px-3 py-2">Intent group</th>
                        <th class="px-3 py-2">Module / Operation</th>
                        <th class="px-3 py-2 w-24">Weight</th>
                        <th class="px-3 py-2 w-24">Enabled</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $index => $group)
                        <tr class="border-t border-gray-200 align-top">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ $group['name'] }}</div>
                                <div class="mt-2 whitespace-pre-line text-xs text-gray-600">{{ $exampleText[$group['id']] ?? '' }}</div>
                            </td>
                            <td class="px-3 py-2" colspan="2">
                                @foreach($group['targets'] as $targetIndex => $target)
                                    <div class="mb-2 flex items-center gap-2">
                                        <span class="min-w-48">{{ $target['ref'] }}</span>
                                        <span class="w-20">{{ $target['weight'] }}</span>
                                    </div>
                                @endforeach
                            </td>
                            <td class="px-3 py-2">
                                {{ ($group['enabled'] ?? true) ? 'Enabled' : 'Disabled' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($level === 'global')
            <div class="rounded-lg border border-gray-200 p-3">
                <label class="font-medium">Lexical Hints</label>
                <p class="mb-2 text-xs text-gray-500">JSON fields: id, module, phrases, weight (0-0.10), enabled. Hints only influence module candidate ranking.</p>
                <pre class="max-h-80 overflow-auto whitespace-pre-wrap text-xs">{{ $lexicalText }}</pre>
            </div>
        @endif

        </div>
    </div>
</x-filament-panels::page>

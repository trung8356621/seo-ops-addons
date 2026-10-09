<x-filament-panels::page>
    <div class="space-y-4">
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
                                <textarea wire:model="exampleText.{{ $group['id'] }}" rows="3" class="mt-2 w-full rounded-md border-gray-300 text-xs"></textarea>
                            </td>
                            <td class="px-3 py-2" colspan="2">
                                @foreach($group['targets'] as $targetIndex => $target)
                                    <div class="mb-2 flex items-center gap-2">
                                        <span class="min-w-48">{{ $target['ref'] }}</span>
                                        <input type="number" min="1" max="100" wire:model="rows.{{ $index }}.targets.{{ $targetIndex }}.weight" class="w-20 rounded-md border-gray-300 text-sm">
                                    </div>
                                @endforeach
                            </td>
                            <td class="px-3 py-2">
                                <input type="checkbox" wire:model="rows.{{ $index }}.enabled">
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <button type="button" wire:click="save" wire:loading.attr="disabled" class="rounded-md bg-gray-900 px-3 py-2 text-sm text-white">
            <span wire:loading.remove wire:target="save">Lưu</span>
            <span wire:loading wire:target="save">Đang lưu…</span>
        </button>
    </div>
</x-filament-panels::page>

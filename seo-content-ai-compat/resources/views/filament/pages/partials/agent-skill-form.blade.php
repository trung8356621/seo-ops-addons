@php
    $title = $skillMeta['name'] ?? ($activeSkillKey ?? 'Skill');
    $slash = $skillMeta['slash_command'] ?? '';
    $description = $skillMeta['description'] ?? '';
    $policy = $skillMeta['confirmation_policy'] ?? 'none';
    $capability = $skillMeta['capability'] ?? '';
    $usable = (bool) ($skillAvailability['usable'] ?? true);
    $availabilityStatus = (string) ($skillAvailability['status'] ?? '');
    $availabilityReason = (string) ($skillAvailability['reason'] ?? '');
@endphp

<div class="rounded-xl border border-primary-200 bg-primary-50/40 p-4 dark:border-primary-900 dark:bg-primary-950/20">
    <div class="mb-3 flex items-start justify-between gap-2">
        <div>
            <div class="text-sm font-semibold text-gray-950 dark:text-white">{{ $title }}</div>
            @if ($slash !== '')
                <div class="font-mono text-xs text-primary-700 dark:text-primary-300">{{ $slash }}</div>
            @endif
            @if ($description !== '')
                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">{{ $description }}</p>
            @endif
        </div>
        <button
            type="button"
            class="text-xs text-gray-500 hover:text-gray-800 dark:hover:text-gray-200"
            wire:click="clearSkillSelection"
            wire:loading.attr="disabled"
            wire:target="clearSkillSelection"
        >
            {{ __('seo-content-ai::filament.agent_workspace.cancel_skill') }}
        </button>
    </div>

    <div class="mb-3 flex flex-wrap gap-2">
        <span class="seo-agent-workspace__badge {{ $usable ? 'is-ok' : 'is-warn' }}">
            {{ $availabilityStatus !== '' ? $availabilityStatus : ($usable ? 'available' : 'unavailable') }}
        </span>
        @if ($policy === 'none')
            <span class="seo-agent-workspace__badge is-ok">Read</span>
        @else
            <span class="seo-agent-workspace__badge is-warn">Write · {{ $policy }}</span>
        @endif
        @if ($capability !== '')
            <span class="seo-agent-workspace__badge">{{ $capability }}</span>
        @endif
    </div>

    @if ($availabilityReason !== '')
        <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
            {{ $availabilityReason }}
        </div>
    @endif

    <div class="space-y-3">
        @foreach ($skillFormSchema as $field)
            @php
                $key = $field['key'] ?? null;
                $type = $field['type'] ?? 'text';
                $label = $field['label'] ?? $key;
            @endphp
            @continue(! is_string($key) || $key === '')

            <label class="block text-sm">
                <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">{{ $label }}</span>

                @if ($type === 'textarea')
                    <textarea
                        wire:model.defer="skillForm.{{ $key }}"
                        rows="4"
                        class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"
                    ></textarea>
                @elseif ($type === 'select')
                    <x-select wire:model.defer="skillForm.{{ $key }}" class="w-full">
                        @foreach (($field['options'] ?? []) as $opt)
                            <option value="{{ $opt['value'] ?? '' }}">{{ $opt['label'] ?? ($opt['value'] ?? '') }}</option>
                        @endforeach
                    </x-select>
                @elseif ($type === 'boolean')
                    <x-select wire:model.defer="skillForm.{{ $key }}" class="w-full">
                        <option value="1">Có</option>
                        <option value="0">Không</option>
                    </x-select>
                @else
                    <input
                        type="{{ $type === 'month' ? 'month' : ($type === 'datetime' ? 'datetime-local' : 'text') }}"
                        wire:model.defer="skillForm.{{ $key }}"
                        class="w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-950"
                    />
                @endif

                @if (! empty($field['help']))
                    <span class="mt-1 block text-xs text-gray-500">{{ $field['help'] }}</span>
                @endif
            </label>
        @endforeach
    </div>

    @if ($previewPayload)
        <div class="mt-4 rounded-lg border border-gray-200 bg-white p-3 text-sm dark:border-gray-700 dark:bg-gray-950">
            <div class="font-semibold">{{ ($previewPayload['ok'] ?? false) ? 'Xem trước' : 'Không sẵn sàng' }}</div>
            <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $previewPayload['message'] ?? '' }}</div>
            @foreach (($previewPayload['warnings'] ?? []) as $warning)
                <div class="mt-1 text-amber-700">⚠ {{ $warning }}</div>
            @endforeach
            @if (! empty($previewPayload['input_summary']))
                <dl class="mt-2 space-y-1 text-xs">
                    @foreach ($previewPayload['input_summary'] as $k => $v)
                        <div><dt class="inline text-gray-500">{{ $k }}:</dt> <dd class="inline">{{ is_scalar($v) ? $v : json_encode($v) }}</dd></div>
                    @endforeach
                </dl>
            @endif
        </div>
    @endif

    <div class="mt-4 flex flex-wrap gap-2">
        @if ($usable)
            @if ($policy === 'preview' || $policy === 'confirm')
                <x-filament::button color="gray" wire:click="previewSkill" wire:loading.attr="disabled" wire:target="previewSkill">
                    <span wire:loading.remove wire:target="previewSkill">Xem trước</span>
                    <span wire:loading wire:target="previewSkill"><x-filament::loading-indicator class="h-4 w-4" /></span>
                </x-filament::button>
                <x-filament::button wire:click="confirmSkill" wire:loading.attr="disabled" wire:target="confirmSkill">
                    <span wire:loading.remove wire:target="confirmSkill">Xác nhận</span>
                    <span wire:loading wire:target="confirmSkill"><x-filament::loading-indicator class="h-4 w-4" /></span>
                </x-filament::button>
            @else
                <x-filament::button wire:click="confirmSkill" wire:loading.attr="disabled" wire:target="confirmSkill">
                    Chạy
                </x-filament::button>
            @endif
        @endif
        <x-filament::button color="gray" wire:click="clearSkillSelection" wire:loading.attr="disabled" wire:target="clearSkillSelection">
            {{ __('seo-content-ai::filament.agent_workspace.cancel_skill') }}
        </x-filament::button>
    </div>
</div>

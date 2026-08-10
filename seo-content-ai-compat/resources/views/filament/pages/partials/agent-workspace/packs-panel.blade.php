<?php

declare(strict_types=1);
?>
@php
    $items = is_array($packItems ?? null) ? $packItems : [];
    $detail = is_array($packDetail ?? null) ? $packDetail : null;
    $tab = (string) ($packStudioTab ?? 'overview');
@endphp

<div class="seo-agent-workspace__packs space-y-4">
    <div class="flex items-center justify-between gap-2">
        <div>
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                {{ __('seo-content-ai::filament.agent_workspace.packs_title') }}
            </h2>
            <p class="text-sm text-gray-500">
                {{ __('seo-content-ai::filament.agent_workspace.packs_hint') }}
            </p>
        </div>
        <x-filament::button size="sm" color="gray" wire:click="refreshPacksList" wire:loading.attr="disabled" wire:target="refreshPacksList">
            Refresh
        </x-filament::button>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700">
                <tr>
                    <th class="px-3 py-2">Name / Key</th>
                    <th class="px-3 py-2">Version</th>
                    <th class="px-3 py-2">Type / Trust</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Health</th>
                    <th class="px-3 py-2">Skills</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="px-3 py-2">
                            <div class="font-medium">{{ $row['name'] ?? '' }}</div>
                            <div class="font-mono text-xs text-gray-500">{{ $row['key'] ?? '' }}</div>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $row['version'] ?? '' }}</td>
                        <td class="px-3 py-2 text-xs">{{ ($row['type'] ?? '').' / '.($row['trust'] ?? '') }}</td>
                        <td class="px-3 py-2">{{ $row['status'] ?? '' }}</td>
                        <td class="px-3 py-2">{{ $row['health'] ?? '' }} / {{ $row['compatibility'] ?? '' }}</td>
                        <td class="px-3 py-2">{{ $row['skill_count'] ?? 0 }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1">
                                <x-seo-content-ai::agent-workspace.action-button
                                    action="viewPack"
                                    :value="$row['hash_id'] ?? ''"
                                    class="rounded border border-gray-300 px-2 py-0.5 text-xs dark:border-gray-600"
                                >Detail</x-seo-content-ai::agent-workspace.action-button>
                                @if (($row['status'] ?? '') !== 'enabled')
                                    <button
                                        type="button"
                                        value="{{ $row['hash_id'] ?? '' }}"
                                        class="rounded border border-emerald-300 px-2 py-0.5 text-xs text-emerald-700"
                                        x-on:click="window.confirm('Enable pack after validation/gates?') && $wire.enablePack($el.value)"
                                    >Enable</button>
                                @else
                                    <button
                                        type="button"
                                        value="{{ $row['hash_id'] ?? '' }}"
                                        class="rounded border border-amber-300 px-2 py-0.5 text-xs text-amber-700"
                                        x-on:click="window.confirm('Disable pack? History preserved.') && $wire.disablePack($el.value)"
                                    >Disable</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-6 text-center text-gray-500">No packs yet. Use Skill Studio / import.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($detail)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <div class="mb-3 flex flex-wrap gap-2">
                @foreach (['overview','skills','templates','compatibility','evaluations','revisions','diagnostics'] as $t)
                    <x-seo-content-ai::agent-workspace.action-button
                        action="setPackStudioTab"
                        :value="$t"
                        class="rounded border px-2 py-0.5 text-xs {{ $tab === $t ? 'border-primary-500 bg-primary-50 text-primary-800' : 'border-gray-300 dark:border-gray-600' }}"
                    >
                        {{ ucfirst($t) }}
                    </x-seo-content-ai::agent-workspace.action-button>
                @endforeach
            </div>
            <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 text-xs dark:bg-gray-900">{{ json_encode($detail, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
            <p class="mt-2 text-xs text-gray-500">Skill Studio preview never executes capabilities. Enable/disable goes through AgentPackOrchestrator.</p>
        </div>
    @endif
</div>

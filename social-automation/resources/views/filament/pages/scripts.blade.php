<x-filament-panels::page>
    @php
        $scripts = $this->scripts();
        $detail = $this->selectedDetail();
    @endphp

    <div class="space-y-6 max-w-6xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('social-automation::filament.scripts.intro') }}
        </p>

        @if ($scripts === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.scripts.empty') }}
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left">Name</th>
                            <th class="px-3 py-2 text-left">Version</th>
                            <th class="px-3 py-2 text-left">Source</th>
                            <th class="px-3 py-2 text-left">Updated by</th>
                            <th class="px-3 py-2 text-left">Digest</th>
                            <th class="px-3 py-2 text-left">Last sync</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Widgets</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($scripts as $row)
                            <tr wire:click="selectScript(@js($row['package_key']))" class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/60">
                                <td class="px-3 py-2">{{ $row['name'] }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['version'] }}</td>
                                <td class="px-3 py-2">{{ $row['source'] }}</td>
                                <td class="px-3 py-2">{{ $row['updated_by_user_id'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs" title="{{ $row['content_digest'] }}">{{ substr($row['content_digest'], 0, 12) }}…</td>
                                <td class="px-3 py-2 text-xs">{{ $row['last_sync'] ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    {{ $row['status'] }}
                                    @unless ($row['assets_complete'])
                                        <span class="text-amber-600">incomplete</span>
                                    @endunless
                                </td>
                                <td class="px-3 py-2 text-xs">{{ count($row['widget_dependencies'] ?? []) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @if ($detail)
            <div class="rounded-lg border border-gray-200 p-4 space-y-3 dark:border-gray-700">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-base font-semibold">{{ $detail['name'] }} <span class="font-mono text-xs text-gray-500">{{ $detail['package_key'] }}</span></h2>
                    <button type="button" wire:click="clearSelection" class="text-sm text-gray-500">Đóng</button>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ $detail['description'] ?: '—' }}</p>

                <div>
                    <h3 class="font-medium text-sm mb-1">Widget dependencies</h3>
                    @forelse ($detail['widget_dependencies'] as $w)
                        <div class="text-sm">
                            {{ $w['name'] }} ({{ $w['package_key'] }})
                            @unless ($w['is_enabled'])
                                <span class="text-amber-600">disabled: {{ $w['disabled_reason'] }}</span>
                            @endunless
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Chưa có Widget phụ thuộc.</p>
                    @endforelse
                </div>

                @foreach ($detail['releases'] as $i => $release)
                    <div class="rounded border border-gray-100 p-3 dark:border-gray-800 space-y-2">
                        <div class="text-sm font-medium">Release {{ $release['version'] }} · {{ $release['published_at'] ?? '—' }}</div>
                        <div class="text-xs font-mono">digest {{ $release['content_digest'] }}</div>
                        @if ($release['video_demo'])
                            <div class="text-sm">Video demo: {{ $release['video_demo']['artifact_ref'] ?? $release['video_demo']['path'] ?? 'ref' }}</div>
                        @endif
                        <details>
                            <summary class="text-sm cursor-pointer">Variables / Steps / Assets</summary>
                            <pre class="mt-2 overflow-auto text-xs bg-gray-50 dark:bg-gray-900 p-2 rounded">{{ json_encode([
                                'variables' => $release['variables'],
                                'steps' => $release['steps'],
                                'assets' => $release['assets'],
                                'runtime_requirements' => $release['runtime_requirements'],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>

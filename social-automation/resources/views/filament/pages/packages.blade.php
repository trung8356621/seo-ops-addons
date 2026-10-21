<x-filament-panels::page>
    @php
        $packages = $this->packages();
    @endphp

    <div class="space-y-4 max-w-4xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">
            {{ __('social-automation::filament.packages.intro') }}
        </p>

        @if ($packages === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.packages.empty') }}
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">Key</th>
                            <th class="px-3 py-2 text-left font-semibold">Kind</th>
                            <th class="px-3 py-2 text-left font-semibold">Name</th>
                            <th class="px-3 py-2 text-left font-semibold">Latest</th>
                            <th class="px-3 py-2 text-left font-semibold">Digest</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($packages as $row)
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['package_key'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['kind'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['name'] ?? '' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['latest_version'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs truncate max-w-[12rem]" title="{{ $row['latest_digest'] ?? '' }}">
                                    {{ isset($row['latest_digest']) ? substr((string) $row['latest_digest'], 0, 12).'…' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>

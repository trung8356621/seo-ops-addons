<x-filament-panels::page>
    @php $rows = $this->rows(); @endphp
    <div class="space-y-4 max-w-5xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('social-automation::filament.sessions.intro') }}</p>
        @if ($rows === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.sessions.empty') }}
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">Session</th>
                            <th class="px-3 py-2 text-left font-semibold">Agent</th>
                            <th class="px-3 py-2 text-left font-semibold">Domain</th>
                            <th class="px-3 py-2 text-left font-semibold">Ready</th>
                            <th class="px-3 py-2 text-left font-semibold">Busy</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['session_uuid'] ?? '' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['agent_uuid'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['domain'] ?? ($row['platform'] ?? '') }}</td>
                                <td class="px-3 py-2">{{ $row['ready_status'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ !empty($row['busy']) ? 'busy' : 'available' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>

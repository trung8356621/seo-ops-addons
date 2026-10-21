<x-filament-panels::page>
    @php $rows = $this->rows(); @endphp
    <div class="space-y-4 max-w-5xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('social-automation::filament.schedules.intro') }}</p>
        @if ($rows === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.schedules.empty') }}
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">Task</th>
                            <th class="px-3 py-2 text-left font-semibold">Cron</th>
                            <th class="px-3 py-2 text-left font-semibold">TZ</th>
                            <th class="px-3 py-2 text-left font-semibold">Enabled</th>
                            <th class="px-3 py-2 text-left font-semibold">Last fired</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-2">{{ $row['task_name'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['cron_expression'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['timezone'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ !empty($row['enabled']) ? 'yes' : 'no' }}</td>
                                <td class="px-3 py-2 text-xs">{{ $row['last_fired_at'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>

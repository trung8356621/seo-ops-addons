<x-filament-panels::page>
    @php $rows = $this->rows(); @endphp
    <div class="space-y-4 max-w-5xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('social-automation::filament.jobs.intro') }}</p>
        @if ($rows === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.jobs.empty') }}
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">Job</th>
                            <th class="px-3 py-2 text-left font-semibold">Package</th>
                            <th class="px-3 py-2 text-left font-semibold">Status</th>
                            <th class="px-3 py-2 text-left font-semibold">Source</th>
                            <th class="px-3 py-2 text-left font-semibold">Lease</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['job_uuid'] ?? '' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ ($row['package_key'] ?? '').'@'.($row['package_version'] ?? '') }}</td>
                                <td class="px-3 py-2">{{ $row['status'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['dispatch_source'] ?? '' }}</td>
                                <td class="px-3 py-2 text-xs">{{ $row['lease_expires_at'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>

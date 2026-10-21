<x-filament-panels::page>
    @php $rows = $this->rows(); @endphp
    <div class="space-y-4 max-w-5xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('social-automation::filament.tasks.intro') }}</p>
        @if ($rows === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.tasks.empty') }}
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">Name</th>
                            <th class="px-3 py-2 text-left font-semibold">Package</th>
                            <th class="px-3 py-2 text-left font-semibold">Kind</th>
                            <th class="px-3 py-2 text-left font-semibold">Target</th>
                            <th class="px-3 py-2 text-left font-semibold">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-2">{{ $row['name'] ?? '' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ ($row['package_key'] ?? '').'@'.($row['package_version'] ?? '') }}</td>
                                <td class="px-3 py-2">{{ $row['package_kind'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['target_account_count'] ?? 0 }}</td>
                                <td class="px-3 py-2">{{ $row['status'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>

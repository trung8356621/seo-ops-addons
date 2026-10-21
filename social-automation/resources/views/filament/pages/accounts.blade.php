<x-filament-panels::page>
    @php $rows = $this->rows(); @endphp
    <div class="space-y-4 max-w-5xl">
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('social-automation::filament.accounts.intro') }}</p>
        @if ($rows === [])
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-500 dark:border-gray-600">
                {{ __('social-automation::filament.accounts.empty') }}
            </div>
        @else
            <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold">Platform</th>
                            <th class="px-3 py-2 text-left font-semibold">Label</th>
                            <th class="px-3 py-2 text-left font-semibold">Platform ID</th>
                            <th class="px-3 py-2 text-left font-semibold">Verified</th>
                            <th class="px-3 py-2 text-left font-semibold">Source</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="px-3 py-2">{{ $row['platform'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['label'] ?? ($row['declared_username'] ?? '—') }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['platform_account_id'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ !empty($row['verified']) ? 'verified' : 'unverified' }}</td>
                                <td class="px-3 py-2">{{ $row['identity_source'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>

<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $this->project->name }}</x-slot>
        <x-slot name="description">
            @if ($this->isProjectFullyCompleted())
                {{ __('seo-content-ai::filament.projects.run_history_completed_hint') }}
            @else
                Run và Test run chỉ xử lý các hạng mục đang chờ; các hạng mục đã OK được bỏ qua.
            @endif
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <tr>
                        <th class="px-3 py-2">Run</th>
                        <th class="px-3 py-2">Mode</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Total</th>
                        <th class="px-3 py-2">OK</th>
                        <th class="px-3 py-2">Failed</th>
                        <th class="px-3 py-2">Started</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @forelse ($this->getRuns() as $run)
                        <tr>
                            <td class="px-3 py-3 font-medium">#{{ $run->id }}</td>
                            <td class="px-3 py-3">{{ $run->isTestMode() ? 'Test' : 'Full' }}</td>
                            <td class="px-3 py-3">{{ $run->status }}</td>
                            <td class="px-3 py-3">{{ $run->total }}</td>
                            <td class="px-3 py-3 text-success-600">{{ $run->succeeded }}</td>
                            <td class="px-3 py-3 text-danger-600">{{ $run->failed }}</td>
                            <td class="px-3 py-3">{{ $run->started_at?->format('d/m/Y H:i:s') }}</td>
                            <td class="px-3 py-3 text-right">
                                <x-filament::button
                                    size="xs"
                                    tag="a"
                                    href="{{ \Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource::getUrl('view-run', ['run' => $run]) }}"
                                >
                                    View
                                </x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-3 py-10 text-center text-gray-500">
                                Chưa có lần run. Sử dụng nút Run hoặc Test run phía trên.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

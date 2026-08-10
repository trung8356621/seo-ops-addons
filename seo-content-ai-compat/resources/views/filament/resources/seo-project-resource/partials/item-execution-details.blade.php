<div class="space-y-3 text-sm">
    <p class="text-gray-600 dark:text-gray-300">
        {{ __('seo-content-ai::filament.projects.item_execution_details_intro', ['id' => $task->id]) }}
    </p>
    @if ($items->isEmpty())
        <p class="text-gray-500">{{ __('seo-content-ai::filament.projects.item_execution_empty') }}</p>
    @else
        <ul class="divide-y divide-gray-200 dark:divide-gray-700 rounded-lg border border-gray-200 dark:border-gray-700">
            @foreach ($items as $item)
                <li class="p-3 space-y-1">
                    <div class="font-medium">
                        Run #{{ $item->run_id }} · item #{{ $item->id }} · {{ $item->status }}
                    </div>
                    <div class="text-gray-500">
                        {{ $item->action ?? '—' }}
                        @if ($item->started_at)
                            · {{ $item->started_at->format('d/m/Y H:i') }}
                        @endif
                        @if ($item->finished_at)
                            → {{ $item->finished_at->format('d/m/Y H:i') }}
                        @endif
                    </div>
                    @if (! empty($item->error_message))
                        <div class="text-danger-600">{{ $item->error_message }}</div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>

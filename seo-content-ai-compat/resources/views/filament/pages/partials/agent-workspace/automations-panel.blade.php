@php
    $items = is_array($automationItems ?? null) ? $automationItems : [];
    $history = is_array($automationHistory ?? null) ? $automationHistory : [];
    $detail = is_array($automationDetail ?? null) ? $automationDetail : null;
    $diag = is_array($automationDiagnostics ?? null) ? $automationDiagnostics : null;
@endphp

<div class="seo-agent-workspace__automations space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold">Automations</h3>
        <div class="flex gap-2">
            <button type="button" class="seo-agent-workspace__ghost-btn" wire:click="refreshAutomationsList" wire:loading.attr="disabled">
                Refresh
            </button>
            <x-seo-content-ai::agent-workspace.action-button
                action="selectSkill"
                value="automation.create"
                class="seo-agent-workspace__ghost-btn"
            >
                Create
            </x-seo-content-ai::agent-workspace.action-button>
            <button type="button" class="seo-agent-workspace__ghost-btn" wire:click="openChatPanel">
                Back to chat
            </button>
        </div>
    </div>

    <p class="text-xs opacity-70">AI không tự tạo/kích hoạt automation. Preview → save tường minh. Write mặc định chờ approval.</p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b text-xs uppercase opacity-70">
                <tr>
                    <th class="px-2 py-2">Name</th>
                    <th class="px-2 py-2">Type</th>
                    <th class="px-2 py-2">Status</th>
                    <th class="px-2 py-2">Scope</th>
                    <th class="px-2 py-2">Next run</th>
                    <th class="px-2 py-2">Last</th>
                    <th class="px-2 py-2">Owner</th>
                    <th class="px-2 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-800" wire:key="auto-{{ $row['hash_id'] ?? '' }}">
                        <td class="px-2 py-2">{{ $row['name'] ?? '—' }}</td>
                        <td class="px-2 py-2 text-xs">{{ $row['type'] ?? '—' }}</td>
                        <td class="px-2 py-2 text-xs">{{ $row['status'] ?? '—' }}</td>
                        <td class="px-2 py-2 text-xs">{{ ($row['scope_type'] ?? '').':'.($row['scope_ref'] ?? '') }}</td>
                        <td class="px-2 py-2 text-xs">{{ $row['next_run_at'] ?? '—' }}</td>
                        <td class="px-2 py-2 text-xs">{{ $row['last_run_status'] ?? '—' }}</td>
                        <td class="px-2 py-2 text-xs">{{ $row['owner_user_id'] ?? '—' }}</td>
                        <td class="px-2 py-2">
                            <div class="flex flex-wrap gap-1 text-xs">
                                <x-seo-content-ai::agent-workspace.action-button action="viewAutomation" :value="$row['hash_id'] ?? ''" class="underline">History</x-seo-content-ai::agent-workspace.action-button>
                                <x-seo-content-ai::agent-workspace.action-button action="runAutomationNow" :value="$row['hash_id'] ?? ''" class="underline">Run now</x-seo-content-ai::agent-workspace.action-button>
                                @if (($row['status'] ?? '') === 'paused')
                                    <x-seo-content-ai::agent-workspace.action-button action="resumeAutomation" :value="$row['hash_id'] ?? ''" class="underline">Resume</x-seo-content-ai::agent-workspace.action-button>
                                @else
                                    <x-seo-content-ai::agent-workspace.action-button action="pauseAutomation" :value="$row['hash_id'] ?? ''" class="underline">Pause</x-seo-content-ai::agent-workspace.action-button>
                                @endif
                                <button
                                    type="button"
                                    value="{{ $row['hash_id'] ?? '' }}"
                                    class="underline text-red-600"
                                    x-on:click="window.confirm('Soft-delete automation? History được giữ.') && $wire.deleteAutomation($el.value)"
                                >Delete</button>
                                @if (\Omnichannel\Addons\Seo\Support\SeoAccessControl::canAccessManagerFeatures())
                                    <x-seo-content-ai::agent-workspace.action-button action="loadAutomationDiagnostics" :value="$row['hash_id'] ?? ''" class="underline">Diag</x-seo-content-ai::agent-workspace.action-button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-2 py-6 text-center text-sm opacity-60">Chưa có automation. Dùng /create-automation.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($detail)
        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
            <h4 class="mb-2 font-semibold">Detail</h4>
            <pre class="overflow-x-auto text-xs">{{ json_encode($detail['automation'] ?? $detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif

    @if ($history !== [])
        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
            <h4 class="mb-2 font-semibold">Run history</h4>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-xs">
                    <thead class="border-b opacity-70">
                        <tr>
                            <th class="px-2 py-1">Occurrence</th>
                            <th class="px-2 py-1">Status</th>
                            <th class="px-2 py-1">Attempt</th>
                            <th class="px-2 py-1">Duration</th>
                            <th class="px-2 py-1">Notify</th>
                            <th class="px-2 py-1">Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $run)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-2 py-1 font-mono">{{ $run['occurrence_key'] ?? $run['hash_id'] ?? '—' }}</td>
                                <td class="px-2 py-1">{{ $run['status'] ?? '—' }}{{ isset($run['skip_reason']) && $run['skip_reason'] ? ' ('.$run['skip_reason'].')' : '' }}</td>
                                <td class="px-2 py-1">{{ $run['attempt'] ?? 1 }}</td>
                                <td class="px-2 py-1">{{ $run['duration_ms'] ?? '—' }}</td>
                                <td class="px-2 py-1">{{ $run['notification_status'] ?? '—' }}</td>
                                <td class="px-2 py-1">{{ $run['error_category'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($diag)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs dark:border-amber-800 dark:bg-amber-950/30">
            <h4 class="mb-2 font-semibold">Diagnostics (manager)</h4>
            <pre class="overflow-x-auto">{{ json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif
</div>

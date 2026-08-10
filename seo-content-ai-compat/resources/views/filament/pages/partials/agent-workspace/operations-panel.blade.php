@php
    $overview = is_array($operationsOverview ?? null) ? $operationsOverview : [];
    $ok = ($overview['ok'] ?? false) === true;
    $readiness = is_array($v1Readiness ?? null) ? $v1Readiness : null;
    $version = is_array($workspaceVersion ?? null) ? $workspaceVersion : [];
    $groups = is_array($skillGroups ?? null) ? $skillGroups : [];
@endphp

<div class="seo-agent-workspace__operations space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold">Operations</h3>
        <div class="flex flex-wrap gap-2">
            <button type="button" class="seo-agent-workspace__ghost-btn" wire:click="runV1ReadinessCheck" wire:loading.attr="disabled" wire:target="runV1ReadinessCheck">
                <span wire:loading.remove wire:target="runV1ReadinessCheck">Run v1 readiness check</span>
                <span wire:loading wire:target="runV1ReadinessCheck">Checking…</span>
            </button>
            <button type="button" class="seo-agent-workspace__ghost-btn" wire:click="openOperationsPanel" wire:loading.attr="disabled">Refresh</button>
            <button type="button" class="seo-agent-workspace__ghost-btn" wire:click="openChatPanel">Back to chat</button>
        </div>
    </div>

    @if ($version !== [])
        <p class="text-xs opacity-70">Agent Workspace v{{ $version['version'] ?? '?' }} · freeze {{ $version['freeze'] ?? 'v1.0' }}</p>
    @endif

    <p class="text-xs opacity-70">Overview · Traces · Metrics · Reviews · Evaluations · Quality Gates · Policy · Cost — sanitized, site-scoped. No secrets / CoT. Doctor does not execute business actions.</p>

    @if ($readiness)
        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
            <h4 class="mb-2 font-semibold">v1 readiness: {{ $readiness['overall'] ?? '—' }}</h4>
            <ul class="max-h-48 space-y-1 overflow-y-auto text-xs">
                @foreach (($readiness['checks'] ?? []) as $check)
                    <li>
                        <span class="font-mono">[{{ $check['status'] ?? '' }}]</span>
                        {{ $check['id'] ?? '' }} — {{ $check['message'] ?? '' }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($groups !== [])
        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
            <h4 class="mb-2 font-semibold">Skill groups</h4>
            <div class="grid gap-2 md:grid-cols-2">
                @foreach ($groups as $group)
                    <div class="rounded-lg border border-gray-100 p-2 text-xs dark:border-gray-800">
                        <div class="font-medium">{{ $group['label_vi'] ?? $group['label'] ?? '' }} ({{ $group['count'] ?? 0 }})</div>
                        <div class="opacity-70">{{ $group['description'] ?? '' }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @unless ($ok)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm">{{ $overview['code'] ?? 'unavailable' }}</div>
    @else
        <div class="grid gap-3 md:grid-cols-3">
            <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
                <div class="text-xs uppercase opacity-60">Policy violations (7d)</div>
                <div class="text-2xl font-semibold">{{ $overview['policy_violations_7d'] ?? 0 }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
                <div class="text-xs uppercase opacity-60">Open reviews</div>
                <div class="text-2xl font-semibold">{{ count($overview['reviews_open'] ?? []) }}</div>
            </div>
            <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
                <div class="text-xs uppercase opacity-60">Automation health</div>
                <div class="text-2xl font-semibold">{{ $overview['automation_health']['score'] ?? '—' }}</div>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
            <h4 class="mb-2 font-semibold">Reviews</h4>
            <ul class="space-y-1 text-xs">
                @forelse (($overview['reviews_open'] ?? []) as $row)
                    <li>{{ $row['severity'] ?? '' }} · {{ $row['reason'] ?? '' }} · {{ $row['hash_id'] ?? '' }}</li>
                @empty
                    <li class="opacity-60">Empty</li>
                @endforelse
            </ul>
        </div>

        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
            <h4 class="mb-2 font-semibold">Evaluations</h4>
            <ul class="space-y-1 text-xs">
                @forelse (($overview['evaluations'] ?? []) as $row)
                    <li>{{ $row['gate_status'] ?? '—' }} · {{ $row['hash_id'] ?? '' }}</li>
                @empty
                    <li class="opacity-60">No runs</li>
                @endforelse
            </ul>
        </div>

        <div class="rounded-xl border border-gray-200 p-3 text-sm dark:border-gray-700">
            <h4 class="mb-2 font-semibold">Metrics (7d aggregates)</h4>
            <div class="max-h-64 overflow-auto">
                <table class="min-w-full text-left text-xs">
                    <thead class="border-b opacity-70">
                        <tr>
                            <th class="px-2 py-1">Key</th>
                            <th class="px-2 py-1">Date</th>
                            <th class="px-2 py-1">Sum</th>
                            <th class="px-2 py-1">Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($overview['metrics'] ?? []) as $m)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-2 py-1 font-mono">{{ $m['metric_key'] ?? '' }}</td>
                                <td class="px-2 py-1">{{ $m['bucket_date'] ?? '' }}</td>
                                <td class="px-2 py-1">{{ $m['value_sum'] ?? 0 }}</td>
                                <td class="px-2 py-1">{{ $m['value_count'] ?? 0 }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-2 py-4 text-center opacity-60">No aggregates yet</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endunless
</div>

<x-filament-panels::page>
    <div
        class="space-y-6"
        wire:poll.30s="refreshTab"
        x-data="{
            tab: @entangle('activeTab').live,
            switchTab(name) {
                this.tab = name;
                $wire.switchTab(name);
            }
        }"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('seo-content-ai::filament.ops.subtitle') }}
            </p>
            <x-filament::button
                type="button"
                color="gray"
                icon="heroicon-o-arrow-path"
                wire:click="refreshAll"
                wire:loading.attr="disabled"
                wire:target="refreshAll,refreshTab,replayOperation"
            >
                <span wire:loading.remove wire:target="refreshAll">{{ __('seo-content-ai::filament.ops.refresh') }}</span>
                <span wire:loading wire:target="refreshAll" class="inline-flex items-center gap-2">
                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                    …
                </span>
            </x-filament::button>
        </div>

        <div class="flex flex-wrap gap-2 border-b border-gray-200 pb-2 dark:border-gray-700">
            @foreach ([
                'dashboard' => __('seo-content-ai::filament.ops.tab_dashboard'),
                'site_sync' => 'Site Sync',
                'health' => __('seo-content-ai::filament.ops.tab_health'),
                'runtime' => __('seo-content-ai::filament.ops.tab_runtime'),
                'timeline' => __('seo-content-ai::filament.ops.tab_timeline'),
                'audit' => __('seo-content-ai::filament.ops.tab_audit'),
                'commands' => __('seo-content-ai::filament.ops.tab_commands'),
                'analytics' => __('seo-content-ai::filament.ops.tab_analytics'),
                'report' => __('seo-content-ai::filament.ops.tab_report'),
                'plans' => __('seo-content-ai::filament.ops.tab_plans'),
                'approvals' => __('seo-content-ai::filament.ops.tab_approvals'),
            ] as $name => $label)
                <button
                    type="button"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium"
                    :class="tab === '{{ $name }}'
                        ? 'bg-primary-600 text-white'
                        : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200'"
                    x-on:click="switchTab('{{ $name }}')"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Dashboard --}}
        <div x-show="tab === 'dashboard'" x-cloak class="space-y-4">
            @php
                $ai = $dashboard['ai'] ?? [];
                $pub = $dashboard['publishing'] ?? [];
                $arch = $dashboard['archive'] ?? [];
                $worker = $dashboard['worker'] ?? [];
                $metrics = $dashboard['metrics_today'] ?? [];
            @endphp
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <x-filament::section>
                    <x-slot name="heading">AI</x-slot>
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div><dt class="text-gray-500">Waiting</dt><dd class="text-lg font-semibold">{{ $ai['waiting'] ?? 0 }}</dd></div>
                        <div><dt class="text-gray-500">Running</dt><dd class="text-lg font-semibold">{{ $ai['running'] ?? 0 }}</dd></div>
                        <div><dt class="text-gray-500">Failed</dt><dd class="text-lg font-semibold text-danger-600">{{ $ai['failed'] ?? 0 }}</dd></div>
                        <div><dt class="text-gray-500">Retry</dt><dd class="text-lg font-semibold">{{ $ai['retry'] ?? 0 }}</dd></div>
                    </dl>
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">Publishing</x-slot>
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div><dt class="text-gray-500">Waiting</dt><dd class="text-lg font-semibold">{{ $pub['waiting'] ?? 0 }}</dd></div>
                        <div><dt class="text-gray-500">Publishing</dt><dd class="text-lg font-semibold">{{ $pub['processing'] ?? 0 }}</dd></div>
                        <div><dt class="text-gray-500">Retry</dt><dd class="text-lg font-semibold">{{ $pub['retrying'] ?? 0 }}</dd></div>
                        <div><dt class="text-gray-500">Failed</dt><dd class="text-lg font-semibold text-danger-600">{{ $pub['failed'] ?? 0 }}</dd></div>
                    </dl>
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">Archive</x-slot>
                    <dl class="grid grid-cols-2 gap-2 text-sm">
                        <div><dt class="text-gray-500">Pending</dt><dd class="text-lg font-semibold">{{ $arch['pending'] ?? 0 }}</dd></div>
                        <div><dt class="text-gray-500">Success</dt><dd class="text-lg font-semibold">{{ $arch['success'] ?? 0 }}</dd></div>
                        <div><dt class="text-gray-500">Failed</dt><dd class="text-lg font-semibold text-danger-600">{{ $arch['failed'] ?? 0 }}</dd></div>
                    </dl>
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">Queue Worker</x-slot>
                    <dl class="space-y-2 text-sm">
                        @php
                            $opsStatus = new \Omnichannel\Addons\ContentProjects\Support\ContentProject\OperationalStatusFormatter();
                            $workerBeat = $opsStatus->formatWorker($worker['last_worker_run'] ?? null);
                            $workerSuccess = $opsStatus->formatSuccess($worker['last_success'] ?? null);
                            $workerFailure = $opsStatus->formatFailure($worker['last_failure'] ?? null);
                        @endphp
                        <div class="flex justify-between"><span class="text-gray-500">{{ __('seo-content-ai::filament.ops.alive') }}</span><span class="font-semibold {{ !empty($worker['alive']) ? 'text-success-600' : 'text-danger-600' }}">{{ !empty($worker['alive']) ? __('seo-content-ai::filament.ops.alive_yes') : __('seo-content-ai::filament.ops.alive_no') }}</span></div>
                        <div class="flex justify-between gap-2"><span class="text-gray-500">{{ __('seo-content-ai::filament.ops.heartbeat') }}</span><span class="truncate text-xs" title="{{ $workerBeat['tooltip'] ?? '' }}">{{ $workerBeat['text'] }}</span></div>
                        <div class="flex justify-between gap-2"><span class="text-gray-500">{{ __('seo-content-ai::filament.ops.last_success') }}</span><span class="truncate text-xs text-success-700 dark:text-success-400" title="{{ $workerSuccess['tooltip'] ?? '' }}">{{ $workerSuccess['text'] }}</span></div>
                        <div class="flex justify-between gap-2"><span class="text-gray-500">{{ __('seo-content-ai::filament.ops.last_failure') }}</span><span class="truncate text-xs text-danger-700 dark:text-danger-400" title="{{ $workerFailure['tooltip'] ?? '' }}">{{ $workerFailure['text'] }}</span></div>
                        @if (!empty($worker['last_worker_run']) || !empty($worker['last_success']) || !empty($worker['last_failure']))
                            <details class="pt-1 text-xs text-gray-400">
                                <summary class="cursor-pointer">{{ __('seo-content-ai::filament.ops.technical_details') }}</summary>
                                <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-all font-mono">{{ trim(implode("\n", array_filter([
                                    isset($worker['last_worker_run']) ? 'last_worker_run='.$worker['last_worker_run'] : null,
                                    isset($worker['last_success']) ? 'last_success='.$worker['last_success'] : null,
                                    isset($worker['last_failure']) ? 'last_failure='.$worker['last_failure'] : null,
                                ]))) }}</pre>
                            </details>
                        @endif
                    </dl>
                </x-filament::section>
            </div>
            @if ($metrics !== [])
                <x-filament::section>
                    <x-slot name="heading">{{ __('seo-content-ai::filament.ops.metrics_today') }}</x-slot>
                    <div class="flex flex-wrap gap-3 text-sm">
                        @foreach ($metrics as $key => $value)
                            <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                <div class="text-xs text-gray-500">{{ $key }}</div>
                                <div class="font-semibold">{{ $value }}</div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        </div>

        <div x-show="tab === 'site_sync'" x-cloak class="space-y-4">
            <x-filament::section>
                <x-slot name="heading">Site Sync</x-slot>
                <x-slot name="description">Recent runs, inbound events, diagnostics.</x-slot>

                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button
                        type="button"
                        color="gray"
                        wire:click="refreshSiteSync"
                        wire:loading.attr="disabled"
                        wire:target="refreshSiteSync,resumeSiteSyncRun,cancelSiteSyncRun,reconcileSiteSyncSite,runSiteSyncDiagnostic,generateSiteSyncReport,requeueSiteSyncEvent"
                    >
                        Refresh
                    </x-filament::button>
                </div>

                @if ($siteSyncCutover)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="font-medium">Cutover: {{ $siteSyncCutover['status'] ?? $siteSyncCutoverMode }}</div>
                        <div class="mt-1 text-sm">Current mode: <strong>{{ $siteSyncCutoverMode }}</strong></div>
                    </div>
                @endif

                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                    <div class="border-b border-gray-200 px-4 py-3 font-medium dark:border-gray-700">Recent runs</div>
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-3 py-2 text-left">Run</th>
                                <th class="px-3 py-2 text-left">Site</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-left">Step</th>
                                <th class="px-3 py-2 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($siteSyncRuns as $run)
                                <tr>
                                    <td class="px-3 py-2 font-mono text-xs">#{{ $run['id'] }}</td>
                                    <td class="px-3 py-2">site {{ $run['site_id'] }}</td>
                                    <td class="px-3 py-2">{{ $run['status'] }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-500">{{ $run['current_step'] ?: '—' }}</td>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap gap-2">
                                            @if ($run['show_report'])
                                                <x-filament::button size="xs" color="info" wire:click="generateSiteSyncReport({{ (int) $run['site_id'] }})">View report</x-filament::button>
                                            @endif
                                            @if ($run['show_resume'])
                                                <x-filament::button size="xs" color="info" wire:click="resumeSiteSyncRun({{ (int) $run['id'] }})">Resume</x-filament::button>
                                            @endif
                                            @if ($run['show_cancel'])
                                                <x-filament::button size="xs" color="danger" wire:click="cancelSiteSyncRun({{ (int) $run['id'] }})">Cancel</x-filament::button>
                                            @endif
                                            @if ($run['show_reconcile'])
                                                <x-filament::button size="xs" color="gray" wire:click="reconcileSiteSyncSite({{ (int) $run['site_id'] }})">Reconcile</x-filament::button>
                                            @endif
                                            @if ($run['show_restart'])
                                                <x-filament::button size="xs" color="info" wire:click="resumeSiteSyncRun({{ (int) $run['id'] }})">Restart</x-filament::button>
                                            @endif
                                            <x-filament::button size="xs" color="warning" wire:click="runSiteSyncDiagnostic({{ (int) $run['site_id'] }})">Diagnostic</x-filament::button>
                                        </div>
                                        @if ($run['error'] !== '')
                                            <div class="mt-2 text-xs text-amber-600">{{ $run['error'] }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-6 text-center text-gray-500">No runs yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($siteSyncDiagnostic)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="font-medium">Diagnostics</div>
                        <pre class="mt-2 max-h-96 overflow-auto whitespace-pre-wrap text-xs">{{ json_encode($siteSyncDiagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                @endif

                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                    <div class="border-b border-gray-200 px-4 py-3 font-medium dark:border-gray-700">Inbound events</div>
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-3 py-2 text-left">Event</th>
                                <th class="px-3 py-2 text-left">Site</th>
                                <th class="px-3 py-2 text-left">Type</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-left">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($siteSyncEvents as $event)
                                <tr>
                                    <td class="px-3 py-2 font-mono text-xs">#{{ $event['id'] }}</td>
                                    <td class="px-3 py-2">site {{ $event['site_id'] }}</td>
                                    <td class="px-3 py-2">{{ $event['event_type'] }}</td>
                                    <td class="px-3 py-2">{{ $event['status'] }}</td>
                                    <td class="px-3 py-2">
                                        <x-filament::button size="xs" wire:click="requeueSiteSyncEvent({{ (int) $event['id'] }})">Requeue</x-filament::button>
                                        @if ($event['error'] !== '')
                                            <div class="mt-2 text-xs text-amber-600">{{ $event['error'] }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-3 py-6 text-center text-gray-500">No inbound events.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        {{-- Commands --}}
        <div x-show="tab === 'commands'" x-cloak class="space-y-4">
            <div class="grid gap-3 md:grid-cols-5">
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="filterProjectRef" placeholder="project ref" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="filterCommand" placeholder="command" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="filterActor" placeholder="actor" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="filterResultCode" placeholder="result code" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="filterTenant" placeholder="tenant (site:1)" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button type="button" wire:click="applyCommandFilters" wire:loading.attr="disabled" wire:target="applyCommandFilters">
                {{ __('seo-content-ai::filament.ops.apply_filters') }}
            </x-filament::button>

            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left">Operation</th>
                            <th class="px-3 py-2 text-left">Command</th>
                            <th class="px-3 py-2 text-left">Actor</th>
                            <th class="px-3 py-2 text-left">Duration</th>
                            <th class="px-3 py-2 text-left">Status</th>
                            <th class="px-3 py-2 text-left">Result</th>
                            <th class="px-3 py-2 text-left">Request</th>
                            <th class="px-3 py-2 text-left"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($operations as $op)
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">{{ $op['operation_id'] }}</td>
                                <td class="px-3 py-2">{{ $op['command'] }}</td>
                                <td class="px-3 py-2">{{ $op['actor_type'] }}#{{ $op['actor_id'] }}</td>
                                <td class="px-3 py-2">{{ $op['duration_ms'] ?? '—' }} ms</td>
                                <td class="px-3 py-2">
                                    <span class="{{ !empty($op['success']) ? 'text-success-600' : 'text-danger-600' }}">
                                        {{ $op['status'] }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $op['result_code'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $op['request_id'] ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    @if (!empty($op['can_replay']))
                                        <x-filament::button
                                            size="sm"
                                            color="warning"
                                            type="button"
                                            wire:click="replayOperation('{{ $op['operation_id'] }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="replayOperation('{{ $op['operation_id'] }}')"
                                        >
                                            Replay
                                        </x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-6 text-center text-gray-500">{{ __('seo-content-ai::filament.ops.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Analytics --}}
        <div x-show="tab === 'analytics'" x-cloak class="space-y-4">
            @php
                $costTotals = $aiCost['totals'] ?? [];
                $pubOverall = $publishAnalytics['overall'] ?? [];
                $failures = $publishAnalytics['failure_breakdown'] ?? [];
            @endphp
            <div class="grid gap-4 lg:grid-cols-3">
                <x-filament::section>
                    <x-slot name="heading">{{ __('seo-content-ai::filament.ops.ai_cost_today') }}</x-slot>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><span>Prompt</span><strong>{{ $costTotals['prompt_tokens'] ?? 0 }}</strong></div>
                        <div class="flex justify-between"><span>Completion</span><strong>{{ $costTotals['completion_tokens'] ?? 0 }}</strong></div>
                        <div class="flex justify-between"><span>Cost</span><strong>{{ $costTotals['estimated_cost'] ?? 0 }}</strong></div>
                    </dl>
                    @if (!empty($aiCost['by_model']))
                        <div class="mt-3 space-y-1 text-xs">
                            @foreach ($aiCost['by_model'] as $row)
                                <div class="flex justify-between gap-2 border-t border-gray-100 py-1 dark:border-gray-800">
                                    <span class="truncate">{{ $row['model'] }}</span>
                                    <span>{{ $row['estimated_cost'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">{{ __('seo-content-ai::filament.ops.publish_analytics') }}</x-slot>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><span>Success %</span><strong>{{ $pubOverall['success_pct'] ?? 0 }}%</strong></div>
                        <div class="flex justify-between"><span>Retry %</span><strong>{{ $pubOverall['retry_pct'] ?? 0 }}%</strong></div>
                        <div class="flex justify-between"><span>Avg publish</span><strong>{{ $pubOverall['avg_publish_ms'] ?? '—' }} ms</strong></div>
                        <div class="flex justify-between"><span>Timeout</span><strong>{{ $failures['timeout'] ?? 0 }}</strong></div>
                        <div class="flex justify-between"><span>Connection</span><strong>{{ $failures['connection'] ?? 0 }}</strong></div>
                        <div class="flex justify-between"><span>API</span><strong>{{ $failures['api'] ?? 0 }}</strong></div>
                    </dl>
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">{{ __('seo-content-ai::filament.ops.wp_metrics') }}</x-slot>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><span>Avg latency</span><strong>{{ $wpMetrics['avg_latency_ms'] ?? '—' }} ms</strong></div>
                        <div class="flex justify-between"><span>Slowest</span><strong>{{ $wpMetrics['slowest_ms'] ?? '—' }} ms</strong></div>
                        <div class="flex justify-between"><span>Failure %</span><strong>{{ $wpMetrics['failure_pct'] ?? 0 }}%</strong></div>
                        <div class="flex justify-between"><span>Retry %</span><strong>{{ $wpMetrics['retry_pct'] ?? 0 }}%</strong></div>
                    </dl>
                </x-filament::section>
            </div>

            <x-filament::section>
                <x-slot name="heading">{{ __('seo-content-ai::filament.ops.error_center') }}</x-slot>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead><tr class="text-left text-gray-500"><th class="py-1">Result code</th><th>Count</th><th>Last seen</th><th>Project</th></tr></thead>
                        <tbody>
                            @forelse ($errors as $err)
                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="py-1 font-mono text-xs">{{ $err['result_code'] }}</td>
                                    <td>{{ $err['count'] }}</td>
                                    <td class="text-xs">{{ $err['last_seen'] ? (\Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($err['last_seen']) ?? $err['last_seen']) : '—' }}</td>
                                    <td class="font-mono text-xs">{{ $err['sample_project_ref'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-4 text-gray-500">{{ __('seo-content-ai::filament.ops.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        {{-- Health --}}
        <div x-show="tab === 'health'" x-cloak class="space-y-4">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($healthChecks as $check)
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div class="font-medium">{{ $check['key'] }}</div>
                            <span class="text-sm {{ !empty($check['ok']) ? 'text-success-600' : 'text-danger-600' }}">
                                {{ !empty($check['ok']) ? 'OK' : 'FAIL' }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">{{ $check['message'] ?? '' }}</p>
                        @if (!empty($check['detail']))
                            <details class="mt-1 text-xs text-gray-400">
                                <summary class="cursor-pointer">{{ __('seo-content-ai::filament.ops.technical_details') }}</summary>
                                <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-all font-mono">{{ $check['detail'] }}</pre>
                            </details>
                        @endif
                        @if (isset($check['latency_ms']))
                            <p class="mt-1 text-xs text-gray-400">{{ $check['latency_ms'] }} ms</p>
                        @endif
                    </div>
                @endforeach
            </div>
            <x-filament::section>
                <x-slot name="heading">{{ __('seo-content-ai::filament.ops.site_health') }}</x-slot>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-1">Site</th>
                                <th>Waiting</th>
                                <th>Publishing</th>
                                <th>Failed</th>
                                <th>WP</th>
                                <th>Token</th>
                                <th>Last publish</th>
                                <th>Last sync</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($siteHealth as $site)
                                <tr class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="py-1">{{ $site['domain'] ?? $site['name'] ?? ('#'.$site['site_id']) }}</td>
                                    <td>{{ $site['waiting_articles'] }}</td>
                                    <td>{{ $site['publishing'] }}</td>
                                    <td>{{ $site['publish_failed'] }}</td>
                                    <td>{{ $site['wp_reachable'] }}</td>
                                    <td>{{ $site['token_ok'] }}</td>
                                    <td class="text-xs" title="{{ $site['last_publish'] ?? '' }}">{{ $site['last_publish'] ? (\Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($site['last_publish']) ?? $site['last_publish']) : '—' }}</td>
                                    <td class="text-xs" title="{{ $site['last_sync'] ?? '' }}">{{ $site['last_sync'] ? (\Omnichannel\Addons\Content\Support\SystemDateTime::formatDateTime($site['last_sync']) ?? $site['last_sync']) : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="py-4 text-gray-500">{{ __('seo-content-ai::filament.ops.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        {{-- Runtime Info + Developer MCP Reference --}}
        <div x-show="tab === 'runtime'" x-cloak class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('seo-content-ai::filament.extensions.subtitle') }}
                </p>
                <x-filament::button
                    type="button"
                    color="gray"
                    icon="heroicon-o-arrow-path"
                    wire:click="refreshRuntimeHealth"
                    wire:loading.attr="disabled"
                    wire:target="refreshRuntimeHealth"
                >
                    <span wire:loading.remove wire:target="refreshRuntimeHealth">{{ __('seo-content-ai::filament.extensions.refresh_health') }}</span>
                    <span wire:loading wire:target="refreshRuntimeHealth" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        …
                    </span>
                </x-filament::button>
            </div>
            @include('seo-content-ai::filament.pages.partials.runtime-info-grid', ['runtimeRows' => $runtimeRows])

            <x-filament::section>
                @php
                    $mcpDoc = is_array($this->mcpCapabilityDoc ?? null) ? $this->mcpCapabilityDoc : [];
                    $mcpMarkdown = (string) ($mcpDoc['markdown'] ?? '');
                    $mcpCount = (int) ($mcpDoc['count'] ?? 0);
                @endphp
                <details class="group">
                    <summary class="cursor-pointer select-none list-none text-sm font-semibold text-gray-950 dark:text-white">
                        MCP Reference
                        @if ($mcpCount > 0)
                            <span class="ml-2 font-normal text-gray-500 dark:text-gray-400">({{ $mcpCount }})</span>
                        @endif
                    </summary>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Global system_action catalog from CanonicalCapabilityRegistry. Not site_feature flags. Not bound to a domain page.
                    </p>
                    <pre class="mt-3 max-h-[28rem] overflow-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs leading-relaxed text-gray-800 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-200">{{ $mcpMarkdown !== '' ? $mcpMarkdown : 'No MCP capabilities available.' }}</pre>
                </details>
            </x-filament::section>
        </div>

        {{-- Timeline --}}
        <div x-show="tab === 'timeline'" x-cloak class="space-y-4">
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-48">
                    <label class="mb-1 block text-xs text-gray-500">Project ID</label>
                    <x-filament::input.wrapper>
                        <x-filament::input type="text" wire:model="timelineProjectId" />
                    </x-filament::input.wrapper>
                </div>
                <x-filament::button type="button" wire:click="loadTimelineForProject">
                    {{ __('seo-content-ai::filament.ops.load_timeline') }}
                </x-filament::button>
            </div>
            <ol class="space-y-3 border-l-2 border-gray-200 pl-4 dark:border-gray-700">
                @forelse ($timeline as $step)
                    <li class="relative">
                        <span class="absolute -left-[1.4rem] mt-1 h-3 w-3 rounded-full {{ !empty($step['done']) ? 'bg-primary-600' : 'bg-gray-300' }}"></span>
                        <div class="text-sm font-medium">{{ $step['label'] ?? $step['key'] }}</div>
                        <div class="text-xs text-gray-500">{{ $step['at'] ?? '—' }}</div>
                    </li>
                @empty
                    <li class="text-sm text-gray-500">{{ __('seo-content-ai::filament.ops.timeline_hint') }}</li>
                @endforelse
            </ol>
        </div>

        {{-- Audit --}}
        <div x-show="tab === 'audit'" x-cloak class="space-y-4">
            <div class="grid gap-3 md:grid-cols-3">
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="auditProjectRef" placeholder="project ref" />
                </x-filament::input.wrapper>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="auditAction" placeholder="action" />
                </x-filament::input.wrapper>
                <x-filament::button type="button" wire:click="applyAuditFilters">
                    {{ __('seo-content-ai::filament.ops.apply_filters') }}
                </x-filament::button>
            </div>
            <p class="text-xs text-gray-500">{{ __('seo-content-ai::filament.ops.audit_privacy') }}</p>
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-3 py-2 text-left">When</th>
                            <th class="px-3 py-2 text-left">Action</th>
                            <th class="px-3 py-2 text-left">Actor</th>
                            <th class="px-3 py-2 text-left">Project</th>
                            <th class="px-3 py-2 text-left">Item</th>
                            <th class="px-3 py-2 text-left">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($audits as $row)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-2 text-xs">{{ $row['occurred_at'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['action'] ?? '—' }}</td>
                                <td class="px-3 py-2">{{ $row['actor_type'] ?? '—' }}#{{ $row['actor_id'] ?? '' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['project_ref'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['item_ref'] ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['result_code'] ?? $row['result'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">{{ __('seo-content-ai::filament.ops.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Daily report --}}
        <div x-show="tab === 'report'" x-cloak>
            <x-filament::section>
                <x-slot name="heading">{{ __('seo-content-ai::filament.ops.daily_report') }} — {{ $dailyReport['date'] ?? '—' }}</x-slot>
                <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                    <div><dt class="text-gray-500">Generated</dt><dd class="text-lg font-semibold">{{ $dailyReport['generated'] ?? 0 }}</dd></div>
                    <div><dt class="text-gray-500">Approved</dt><dd class="text-lg font-semibold">{{ $dailyReport['approved'] ?? 0 }}</dd></div>
                    <div><dt class="text-gray-500">Published</dt><dd class="text-lg font-semibold">{{ $dailyReport['published'] ?? 0 }}</dd></div>
                    <div><dt class="text-gray-500">Failed</dt><dd class="text-lg font-semibold text-danger-600">{{ $dailyReport['failed'] ?? 0 }}</dd></div>
                    <div><dt class="text-gray-500">Cost</dt><dd class="text-lg font-semibold">{{ $dailyReport['cost']['estimated_cost'] ?? 0 }}</dd></div>
                    <div><dt class="text-gray-500">Avg queue</dt><dd class="text-lg font-semibold">{{ $dailyReport['avg_queue_wait_ms'] ?? '—' }} ms</dd></div>
                    <div><dt class="text-gray-500">Avg publish</dt><dd class="text-lg font-semibold">{{ $dailyReport['avg_publish_ms'] ?? '—' }} ms</dd></div>
                </dl>
            </x-filament::section>
        </div>

        {{-- Agent plans --}}
        <div x-show="tab === 'plans'" x-cloak class="space-y-4">
            <x-filament::section>
                <x-slot name="heading">{{ __('seo-content-ai::filament.ops.agent_plans.title') }}</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                <th class="py-2 pr-3">{{ __('seo-content-ai::filament.ops.agent_plans.ref') }}</th>
                                <th class="py-2 pr-3">{{ __('seo-content-ai::filament.ops.agent_plans.objective') }}</th>
                                <th class="py-2 pr-3">{{ __('seo-content-ai::filament.ops.agent_plans.status') }}</th>
                                <th class="py-2 pr-3">{{ __('seo-content-ai::filament.ops.agent_plans.progress') }}</th>
                                <th class="py-2">{{ __('seo-content-ai::filament.ops.agent_plans.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($agentPlans as $plan)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-3 font-mono text-xs">{{ $plan['plan_ref'] }}</td>
                                    <td class="py-2 pr-3 max-w-xs truncate">{{ $plan['objective'] }}</td>
                                    <td class="py-2 pr-3">{{ $plan['status'] }}</td>
                                    <td class="py-2 pr-3">{{ $plan['current_step_index'] }}/{{ $plan['total_steps'] }}</td>
                                    <td class="py-2 flex flex-wrap gap-1">
                                        @if ($plan['status'] === 'running')
                                            <x-filament::button size="xs" color="gray" wire:click="pauseAgentPlan('{{ $plan['plan_ref'] }}', {{ (int) $plan['site_id'] }})" wire:loading.attr="disabled">{{ __('seo-content-ai::filament.ops.agent_plans.pause') }}</x-filament::button>
                                        @endif
                                        @if ($plan['status'] === 'paused')
                                            <x-filament::button size="xs" color="success" wire:click="resumeAgentPlan('{{ $plan['plan_ref'] }}', {{ (int) $plan['site_id'] }})" wire:loading.attr="disabled">{{ __('seo-content-ai::filament.ops.agent_plans.resume') }}</x-filament::button>
                                        @endif
                                        @if (! in_array($plan['status'], ['completed', 'cancelled', 'failed'], true))
                                            <x-filament::button size="xs" color="danger" wire:click="cancelAgentPlan('{{ $plan['plan_ref'] }}', {{ (int) $plan['site_id'] }})" wire:loading.attr="disabled">{{ __('seo-content-ai::filament.ops.agent_plans.cancel') }}</x-filament::button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-4 text-gray-500">{{ __('seo-content-ai::filament.ops.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>

        {{-- Agent approvals --}}
        <div x-show="tab === 'approvals'" x-cloak class="space-y-4">
            <x-filament::section>
                <x-slot name="heading">{{ __('seo-content-ai::filament.ops.agent_approvals.title') }}</x-slot>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700 text-left">
                                <th class="py-2 pr-3">{{ __('seo-content-ai::filament.ops.agent_approvals.action') }}</th>
                                <th class="py-2 pr-3">{{ __('seo-content-ai::filament.ops.agent_approvals.summary') }}</th>
                                <th class="py-2 pr-3">{{ __('seo-content-ai::filament.ops.agent_approvals.risk') }}</th>
                                <th class="py-2">{{ __('seo-content-ai::filament.ops.agent_approvals.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($agentApprovals as $approval)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-3 font-mono text-xs">{{ $approval['action'] }}</td>
                                    <td class="py-2 pr-3">
                                        {{ $approval['summary'] }}
                                        @if ($approval['destroy_workspace'] ?? false)
                                            <p class="mt-1 text-xs text-danger-600">{{ __('seo-content-ai::filament.ops.agent_approvals.destroy_workspace') }}</p>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3">{{ $approval['risk_level'] }}</td>
                                    <td class="py-2 flex flex-wrap gap-1">
                                        <x-filament::button size="xs" color="success"
                                            wire:click="approveAgentAction('{{ $approval['approval_ref'] }}', '{{ $approval['state_fingerprint'] }}', {{ (int) $approval['site_id'] }})"
                                            wire:loading.attr="disabled">{{ __('seo-content-ai::filament.ops.agent_approvals.approve') }}</x-filament::button>
                                        <x-filament::button size="xs" color="danger"
                                            wire:click="rejectAgentAction('{{ $approval['approval_ref'] }}', {{ (int) $approval['site_id'] }})"
                                            wire:loading.attr="disabled">{{ __('seo-content-ai::filament.ops.agent_approvals.reject') }}</x-filament::button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-4 text-gray-500">{{ __('seo-content-ai::filament.ops.empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>

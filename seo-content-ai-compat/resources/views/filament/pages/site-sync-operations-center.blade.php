<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-3">
        <x-filament::button wire:click="refreshData" color="gray" size="sm">Refresh</x-filament::button>
        <div class="text-sm text-gray-500">Monitor sync runs, inbound events, dead letters. Actions go through CommandBus.</div>
    </div>

    @if ($cutover)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="font-medium">Cutover: {{ $cutover['status'] ?? $cutoverMode }} (score {{ $cutover['score'] ?? 'n/a' }})</div>
            <div class="mt-1 text-sm">Current mode: <strong>{{ $cutoverMode }}</strong></div>
            <div class="mt-2">
                <label class="text-xs">Confirmation token (activate/rollback)</label>
                <input type="text" wire:model="confirmationToken" class="mt-1 w-full rounded border px-2 py-1 text-sm dark:bg-gray-900" />
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-filament::button size="xs" wire:click="previewCutover({{ (int) ($filterSiteId ?? ($runs[0]['site_id'] ?? 0)) }})">Preview cutover</x-filament::button>
                <x-filament::button size="xs" color="info" wire:click="generateComparison({{ (int) ($filterSiteId ?? ($runs[0]['site_id'] ?? 0)) }})">Compare</x-filament::button>
                <x-filament::button size="xs" color="warning" wire:click="enterShadow({{ (int) ($filterSiteId ?? ($runs[0]['site_id'] ?? 0)) }})">Enter shadow</x-filament::button>
                <x-filament::button size="xs" color="success" wire:click="activateV2({{ (int) ($filterSiteId ?? ($runs[0]['site_id'] ?? 0)) }})">Activate V2</x-filament::button>
                <x-filament::button size="xs" color="gray" wire:click="exitShadow({{ (int) ($filterSiteId ?? ($runs[0]['site_id'] ?? 0)) }})">Exit shadow</x-filament::button>
                <x-filament::button size="xs" color="danger" wire:click="rollbackLegacy({{ (int) ($filterSiteId ?? ($runs[0]['site_id'] ?? 0)) }})">Rollback legacy</x-filament::button>
            </div>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach (($cutover['checks'] ?? []) as $check)
                    <li class="{{ ($check['ok'] ?? false) ? 'text-green-600' : 'text-amber-600' }}">
                        {{ $check['key'] }} — {{ $check['detail'] }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($cutoverPreview)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="font-medium">Cutover preview</div>
            <pre class="mt-2 max-h-64 overflow-auto text-xs">{{ json_encode($cutoverPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">
        <div class="px-4 py-3 font-medium border-b border-gray-200 dark:border-white/10">Recent sync runs</div>
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @forelse ($runs as $run)
                <div class="px-4 py-3 flex flex-wrap items-center gap-3 text-sm">
                    <span class="font-mono">#{{ $run['id'] }}</span>
                    <span>site {{ $run['site_id'] }}</span>
                    <span>{{ $run['status'] }}</span>
                    <span class="opacity-70">{{ $run['current_step'] }}</span>
                    <div class="ml-auto flex gap-2">
                        <x-filament::button size="xs" color="info" wire:click="resumeRun({{ $run['id'] }})">Resume</x-filament::button>
                        <x-filament::button size="xs" color="danger" wire:click="cancelRun({{ $run['id'] }})">Cancel</x-filament::button>
                        <x-filament::button size="xs" color="gray" wire:click="reconcileSite({{ $run['site_id'] }})">Reconcile</x-filament::button>
                        <x-filament::button size="xs" color="warning" wire:click="runDiagnostic({{ $run['site_id'] }})">Diagnostic</x-filament::button>
                    </div>
                </div>
            @empty
                <div class="px-4 py-6 text-sm text-gray-500">No runs yet.</div>
            @endforelse
        </div>
    </div>

    @if ($diagnostic)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="font-medium">Diagnostic (readonly)</div>
            <pre class="mt-2 max-h-96 overflow-auto text-xs">{{ json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 dark:border-white/10 overflow-hidden">
        <div class="px-4 py-3 font-medium border-b border-gray-200 dark:border-white/10">Inbound events</div>
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @forelse ($events as $event)
                <div class="px-4 py-3 flex flex-wrap items-center gap-3 text-sm">
                    <span class="font-mono">#{{ $event['id'] }}</span>
                    <span>site {{ $event['site_id'] }}</span>
                    <span>{{ $event['event_type'] }}</span>
                    <span>{{ $event['status'] }}</span>
                    @if ($event['error'])
                        <span class="text-amber-600">{{ \Illuminate\Support\Str::limit($event['error'], 80) }}</span>
                    @endif
                    <div class="ml-auto">
                        <x-filament::button size="xs" wire:click="requeueEvent({{ $event['id'] }})">Requeue</x-filament::button>
                    </div>
                </div>
            @empty
                <div class="px-4 py-6 text-sm text-gray-500">No inbound events.</div>
            @endforelse
        </div>
    </div>
</div>

<div class="seo-agent-workspace__context flex h-full min-h-0 flex-col gap-4 text-sm">
    <div class="shrink-0">
        <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Context</h3>
        <dl class="mt-2 space-y-1">
            <div><dt class="text-gray-500">Site</dt><dd class="font-medium">{{ $workspaceContext['site_name'] ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">Project</dt><dd class="font-mono text-xs">{{ $workspaceContext['project_ref'] ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">Workspace</dt><dd class="font-mono text-xs">{{ $workspaceContext['workspace_ref'] ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">Article</dt><dd class="font-mono text-xs">{{ $workspaceContext['article_ref'] ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">User</dt><dd class="font-mono text-xs">{{ $workspaceContext['actor_ref'] ?? '—' }}</dd></div>
        </dl>
    </div>

    @if ($activeSkillKey)
        <div class="shrink-0">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {{ __('seo-content-ai::filament.agent_workspace.active_skill') }}
            </h3>
            <div class="mt-2 rounded-lg border border-primary-200 bg-primary-50/50 px-2 py-2 dark:border-primary-900 dark:bg-primary-950/30">
                <div class="font-medium">{{ $skillMeta['name'] ?? $activeSkillKey }}</div>
                <div class="font-mono text-[11px] text-primary-700 dark:text-primary-300">{{ $skillMeta['slash_command'] ?? '' }}</div>
                @if ($activeTemplateKey)
                    <div class="mt-1 text-[11px] text-gray-500">template: {{ $activeTemplateKey }}</div>
                @endif
                <div class="mt-1 text-[11px]">
                    {{ __('seo-content-ai::filament.agent_workspace.availability') }}:
                    <span class="font-medium">{{ $skillAvailability['status'] ?? '—' }}</span>
                </div>
                @if (! empty($skillAvailability['reason']))
                    <div class="mt-1 text-[11px] text-amber-700 dark:text-amber-300">{{ $skillAvailability['reason'] }}</div>
                @endif
                @if (! empty($skillMeta['capability']))
                    <div class="mt-1 text-[11px] text-gray-500">
                        {{ __('seo-content-ai::filament.agent_workspace.required_capabilities') }}:
                        <span class="font-mono">{{ $skillMeta['capability'] }}</span>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="flex min-h-0 flex-1 flex-col">
        <div class="mb-2 flex shrink-0 items-center justify-between gap-2">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {{ __('seo-content-ai::filament.agent_workspace.quick_commands') }}
            </h3>
            <button
                type="button"
                class="text-[11px] font-medium text-primary-600 hover:underline"
                x-on:click="openSkillBrowser()"
            >
                {{ __('seo-content-ai::filament.agent_workspace.view_all_skills') }}
            </button>
        </div>
        <div class="seo-agent-workspace__quick-list min-h-0 flex-1 space-y-1 overflow-y-auto">
            @foreach ($recommendedSkills as $row)
                {{-- Quick command: value + static selectCommand($el.value) via action-button. --}}
                <x-seo-content-ai::agent-workspace.action-button
                    action="selectCommand"
                    :value="$row['key']"
                    wire:key="agent-recommended-{{ $row['key'] }}"
                    class="seo-agent-workspace__quick-cmd {{ !($row['availability']['usable'] ?? true) ? 'is-disabled' : '' }}"
                    wire:loading.attr="disabled"
                    wire:target="selectSkill,selectCommand"
                >
                    <span class="seo-agent-workspace__quick-cmd-slash">{{ $row['slash_command'] }}</span>
                    <span class="seo-agent-workspace__quick-cmd-name">{{ $row['name'] }}</span>
                    @if (!($row['availability']['usable'] ?? true))
                        <span class="seo-agent-workspace__quick-cmd-reason">
                            {{ $row['availability']['reason'] ?? ($row['availability']['status'] ?? 'unavailable') }}
                        </span>
                    @endif
                </x-seo-content-ai::agent-workspace.action-button>
            @endforeach
        </div>
    </div>
</div>

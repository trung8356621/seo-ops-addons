@php
    $items = is_array($knowledgeItems ?? null) ? $knowledgeItems : [];
    $filters = is_array($knowledgeFilters ?? null) ? $knowledgeFilters : [];
    $detail = is_array($knowledgeDetail ?? null) ? $knowledgeDetail : null;
@endphp

<div class="seo-agent-workspace__knowledge space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold">Knowledge Base</h3>
        <div class="flex gap-2">
            <button type="button" class="seo-agent-workspace__ghost-btn" wire:click="refreshKnowledgeList" wire:loading.attr="disabled">
                Refresh
            </button>
            <x-seo-content-ai::agent-workspace.action-button
                action="selectSkill"
                value="knowledge.add"
                class="seo-agent-workspace__ghost-btn"
            >
                Create
            </x-seo-content-ai::agent-workspace.action-button>
            <button type="button" class="seo-agent-workspace__ghost-btn" wire:click="openChatPanel">
                Back to chat
            </button>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
        <x-select wire:model.live="knowledgeFilters.scope_type" class="text-xs">
            <option value="">Scope</option>
            <option value="site">Site</option>
            <option value="project">Project</option>
            <option value="workspace">Workspace</option>
            <option value="user_preference">User preference</option>
        </x-select>
        <x-select wire:model.live="knowledgeFilters.type" class="text-xs">
            <option value="">Type</option>
            <option value="brand">Brand</option>
            <option value="tone">Tone</option>
            <option value="seo_rule">SEO rule</option>
            <option value="prohibited_term">Prohibited</option>
            <option value="general_note">General note</option>
            <option value="project_decision">Project decision</option>
        </x-select>
        <x-select wire:model.live="knowledgeFilters.trust_level" class="text-xs">
            <option value="">Trust</option>
            <option value="system_verified">System verified</option>
            <option value="user_confirmed">User confirmed</option>
            <option value="source_verified">Source verified</option>
            <option value="unverified">Unverified</option>
        </x-select>
        <x-select wire:model.live="knowledgeFilters.status" class="text-xs">
            <option value="">Status</option>
            <option value="active">Active</option>
            <option value="draft">Draft</option>
            <option value="disabled">Disabled</option>
            <option value="superseded">Superseded</option>
        </x-select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
        <table class="min-w-full text-left text-sm">
            <thead class="border-b text-xs uppercase opacity-70">
                <tr>
                    <th class="px-2 py-2">Title</th>
                    <th class="px-2 py-2">Type</th>
                    <th class="px-2 py-2">Scope</th>
                    <th class="px-2 py-2">Trust</th>
                    <th class="px-2 py-2">Status</th>
                    <th class="px-2 py-2">Ver</th>
                    <th class="px-2 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $row)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="px-2 py-2">{{ $row['title'] ?? '—' }}</td>
                        <td class="px-2 py-2 text-xs">{{ $row['type'] ?? '—' }}</td>
                        <td class="px-2 py-2 text-xs">{{ $row['scope_type'] ?? '—' }}</td>
                        <td class="px-2 py-2 text-xs">{{ $row['trust_level'] ?? '—' }}</td>
                        <td class="px-2 py-2 text-xs">{{ $row['status'] ?? '—' }}</td>
                        <td class="px-2 py-2 text-xs">{{ $row['version'] ?? 1 }}</td>
                        <td class="px-2 py-2">
                            <x-seo-content-ai::agent-workspace.action-button
                                action="viewKnowledge"
                                :value="$row['hash_id'] ?? ''"
                                class="text-xs underline"
                            >
                                View
                            </x-seo-content-ai::agent-workspace.action-button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-2 py-4 text-xs opacity-70">Chưa có knowledge trong scope hiện tại.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($detail)
        <div class="seo-agent-workspace__plan-card space-y-2 text-sm">
            <div class="font-semibold">{{ $detail['title'] ?? '' }}</div>
            <div class="text-xs opacity-70">
                {{ $detail['hash_id'] ?? '' }} · {{ $detail['type'] ?? '' }} · v{{ $detail['version'] ?? 1 }}
                · {{ $detail['trust_level'] ?? '' }} · {{ $detail['source_type'] ?? '' }}
            </div>
            <div class="whitespace-pre-wrap text-xs">{{ $detail['content'] ?? '' }}</div>
            <div class="flex flex-wrap gap-2 pt-2">
                <button
                    type="button"
                    value="{{ $detail['hash_id'] ?? '' }}"
                    class="rounded-lg bg-white px-2 py-1 text-xs dark:bg-gray-700"
                    x-on:click="window.confirm('Forget knowledge này? Không xóa business source.') && $wire.forgetKnowledge($el.value)"
                >
                    Forget
                </button>
                <button type="button" class="rounded-lg bg-white px-2 py-1 text-xs dark:bg-gray-700" wire:click="clearKnowledgeDetail">
                    Close
                </button>
            </div>
        </div>
    @endif
</div>

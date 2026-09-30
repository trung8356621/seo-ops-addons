<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Persistence;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentApp;
use Omnichannel\Addons\AgentRuntime\Persistence\Models\AgentThread;

class AgentThreadRepository
{
    public function createThread(
        AgentApp $app,
        string $principalType,
        string $principalRef,
        ?int $userId,
        ?int $ownerId,
        string $scopeType,
        string $scopeRef,
        ?string $title = null,
    ): AgentThread {
        return AgentThread::create([
            'ulid' => (string) Str::ulid(),
            'agent_app_id' => $app->id,
            'principal_type' => $principalType,
            'principal_ref' => $principalRef,
            'user_id' => $userId,
            'owner_id' => $ownerId,
            'scope_type' => $scopeType,
            'scope_ref' => $scopeRef,
            'title' => $title,
            'status' => 'active',
        ]);
    }

    public function findForPrincipal(string $ulid, string $principalType, string $principalRef): ?AgentThread
    {
        return AgentThread::where('ulid', $ulid)
            ->forPrincipal($principalType, $principalRef)
            ->first();
    }

    public function listForPrincipal(
        string $principalType,
        string $principalRef,
        ?string $appKey = null,
        ?string $scopeRef = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = AgentThread::forPrincipal($principalType, $principalRef)
            ->active()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($appKey !== null) {
            $query->whereHas('agentApp', function ($q) use ($appKey) {
                $q->where('app_key', $appKey);
            });
        }

        if ($scopeRef !== null) {
            $query->where('scope_ref', $scopeRef);
        }

        return $query->paginate($perPage);
    }

    public function touchLastMessage(AgentThread $thread): void
    {
        $thread->update(['last_message_at' => now()]);
    }

    public function listArchivedForPrincipal(
        string $principalType,
        string $principalRef,
        ?string $appKey = null,
        ?string $scopeRef = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = AgentThread::forPrincipal($principalType, $principalRef)
            ->archived()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($appKey !== null) {
            $query->whereHas('agentApp', function ($q) use ($appKey) {
                $q->where('app_key', $appKey);
            });
        }

        if ($scopeRef !== null) {
            $query->where('scope_ref', $scopeRef);
        }

        return $query->paginate($perPage);
    }

    public function archiveThread(AgentThread $thread): void
    {
        $thread->update(['status' => 'archived']);
    }

    public function deleteThread(AgentThread $thread): void
    {
        $thread->delete();
    }
}

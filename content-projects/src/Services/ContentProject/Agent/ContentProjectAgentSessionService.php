<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentSession;
use Illuminate\Support\Str;

/**
 * CRUD agent sessions — compact metadata only.
 */
final class ContentProjectAgentSessionService
{
    public function create(AgentExecutionContext $context): ContentProjectAgentSession
    {
        $ttl = (int) config('seo-content-ai.content_project_agent.session_ttl_minutes', 120);
        $now = now();

        return ContentProjectAgentSession::query()->create([
            'public_ref' => 'cpsess_'.Str::lower((string) Str::ulid()),
            'tenant_id' => $this->tenantIdFromRef($context->tenantRef),
            'site_id' => (int) ($context->resolvedSiteId ?? 0),
            'actor_ref' => $context->actorRef,
            'status' => 'active',
            'started_at' => $now,
            'last_activity_at' => $now,
            'expires_at' => $now->copy()->addMinutes(max(1, $ttl)),
            'metadata' => [],
        ]);
    }

    public function findByPublicRef(string $sessionRef): ?ContentProjectAgentSession
    {
        $session = ContentProjectAgentSession::query()
            ->where('public_ref', trim($sessionRef))
            ->first();

        if (! $session instanceof ContentProjectAgentSession) {
            return null;
        }

        if ($session->expires_at !== null && now()->greaterThan($session->expires_at)) {
            $session->status = 'expired';
            $session->save();

            return null;
        }

        return $session;
    }

    public function touch(ContentProjectAgentSession $session): void
    {
        $ttl = (int) config('seo-content-ai.content_project_agent.session_ttl_minutes', 120);
        $session->last_activity_at = now();
        $session->expires_at = now()->addMinutes(max(1, $ttl));
        $session->save();
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function updateMetadata(ContentProjectAgentSession $session, array $patch): void
    {
        $allowed = ['last_project_ref', 'last_operation_ref', 'pending_confirmation_ref'];
        $metadata = is_array($session->metadata) ? $session->metadata : [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $patch)) {
                $metadata[$key] = $patch[$key];
            }
        }

        $session->metadata = $metadata;
        $session->save();
    }

    public function clearWorkspaceContext(ContentProjectAgentSession $session): void
    {
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        unset($metadata['last_project_ref'], $metadata['pending_confirmation_ref']);
        $session->metadata = $metadata;
        $session->save();
    }

    public function expirePastSessions(): int
    {
        return ContentProjectAgentSession::query()
            ->where('expires_at', '<', now())
            ->where('status', '!=', 'expired')
            ->update(['status' => 'expired']);
    }

    private function tenantIdFromRef(string $tenantRef): int
    {
        if (preg_match('/(\d+)/', $tenantRef, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}

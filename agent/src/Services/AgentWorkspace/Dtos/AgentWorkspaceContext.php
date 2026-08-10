<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos;

/**
 * Fail-closed Agent Workspace context bound to one site/tenant.
 */
final class AgentWorkspaceContext
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, bool>  $providers
     * @param  array<string, bool>  $extensions
     * @param  array<string, bool>  $featureFlags
     */
    public function __construct(
        public readonly string $tenantRef,
        public readonly string $siteRef,
        public readonly int $tenantId,
        public readonly int $siteId,
        public readonly ?int $connectionId,
        public readonly string $siteName,
        public readonly string $actorRef,
        public readonly int $actorUserId,
        public readonly string $role,
        public readonly array $scopes = [],
        public readonly ?string $projectRef = null,
        public readonly ?string $workspaceRef = null,
        public readonly ?string $articleRef = null,
        public readonly ?string $operationRef = null,
        public readonly ?string $projectPhase = null,
        public readonly array $providers = [],
        public readonly array $extensions = [],
        public readonly array $featureFlags = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAvailabilityContext(): array
    {
        return [
            'scopes' => $this->scopes,
            'project_ref' => $this->projectRef,
            'workspace_ref' => $this->workspaceRef,
            'article_ref' => $this->articleRef,
            'site_ref' => $this->siteRef,
            'providers' => $this->providers,
            'extensions' => $this->extensions,
            'feature_flags' => $this->featureFlags,
            'role' => $this->role,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'tenant_ref' => $this->tenantRef,
            'site_ref' => $this->siteRef,
            'site_id' => $this->siteId,
            'site_name' => $this->siteName,
            'connection_id' => $this->connectionId,
            'actor_ref' => $this->actorRef,
            'role' => $this->role,
            'project_ref' => $this->projectRef,
            'workspace_ref' => $this->workspaceRef,
            'article_ref' => $this->articleRef,
            'operation_ref' => $this->operationRef,
            'project_phase' => $this->projectPhase,
        ];
    }

    public function withSite(string $siteRef, int $siteId, string $siteName, ?int $connectionId = null): self
    {
        return new self(
            tenantRef: $this->tenantRef,
            siteRef: $siteRef,
            tenantId: $this->tenantId,
            siteId: $siteId,
            connectionId: $connectionId ?? $this->connectionId,
            siteName: $siteName,
            actorRef: $this->actorRef,
            actorUserId: $this->actorUserId,
            role: $this->role,
            scopes: $this->scopes,
            projectRef: null,
            workspaceRef: null,
            articleRef: null,
            operationRef: null,
            projectPhase: null,
            providers: $this->providers,
            extensions: $this->extensions,
            featureFlags: $this->featureFlags,
        );
    }
}

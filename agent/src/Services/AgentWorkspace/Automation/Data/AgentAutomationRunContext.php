<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationRunContext
{
    /**
     * @param  list<string>  $capabilities
     * @param  array<string, mixed>  $permissions
     * @param  array<string, mixed>  $knowledgeGrounding
     */
    public function __construct(
        public int $ownerUserId,
        public int $tenantId,
        public int $siteId,
        public string $siteRef,
        public string $connectionHash,
        public string $scopeType,
        public ?string $scopeRef,
        public array $capabilities,
        public array $permissions,
        public array $knowledgeGrounding,
        public int $definitionVersion,
        public string $definitionHash,
        public ?int $conversationId,
    ) {}
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data;

final readonly class AgentKnowledgeQuery
{
    /**
     * @param  list<string>  $scopeTypes
     * @param  list<string>  $types
     * @param  list<string>  $candidateSkillKeys
     * @param  list<string>  $minTrustLevels
     */
    public function __construct(
        public int $tenantId,
        public int $siteId,
        public ?string $connectionHash,
        public string $message,
        public string $siteRef,
        public ?string $projectRef = null,
        public ?string $workspaceRef = null,
        public ?string $conversationRef = null,
        public ?int $ownerUserId = null,
        public string $taskType = 'plan_generation',
        public array $scopeTypes = ['conversation', 'workspace', 'project', 'site', 'user_preference'],
        public array $types = [],
        public array $candidateSkillKeys = [],
        public array $minTrustLevels = ['system_verified', 'user_confirmed', 'source_verified', 'unverified'],
        public int $maxResults = 8,
        public int $tokenBudget = 1200,
        public bool $allowStaleWithWarning = true,
    ) {}
}

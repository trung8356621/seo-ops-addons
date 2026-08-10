<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

/**
 * Soft quotas for Agent Workspace presentation layer.
 * Does not block already-accepted business operations.
 */
final class AgentWorkspaceQuotaService
{
    public function __construct(
        private readonly int $maxActiveConversationsPerUser = 30,
        private readonly int $maxMessagesPerConversation = 500,
        private readonly int $maxSkillExecutionsPerHour = 120,
        private readonly int $maxMultiStepPlanActions = 8,
        private readonly int $maxTemplateItems = 20,
        private readonly int $maxVisibleSkills = 80,
    ) {}

    public function maxActiveConversationsPerUser(): int
    {
        return $this->maxActiveConversationsPerUser;
    }

    public function maxMessagesPerConversation(): int
    {
        return $this->maxMessagesPerConversation;
    }

    public function maxSkillExecutionsPerHour(): int
    {
        return $this->maxSkillExecutionsPerHour;
    }

    public function maxMultiStepPlanActions(): int
    {
        return $this->maxMultiStepPlanActions;
    }

    public function maxTemplateItems(): int
    {
        return $this->maxTemplateItems;
    }

    public function maxVisibleSkills(): int
    {
        return $this->maxVisibleSkills;
    }

    public function skillExecutionsExceeded(int $count): bool
    {
        return $count >= $this->maxSkillExecutionsPerHour;
    }

    public function conversationsExceeded(int $count): bool
    {
        return $count >= $this->maxActiveConversationsPerUser;
    }

    public function messagesExceeded(int $count): bool
    {
        return $count >= $this->maxMessagesPerConversation;
    }
}

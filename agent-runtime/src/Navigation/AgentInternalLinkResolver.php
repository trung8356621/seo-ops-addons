<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Navigation;

use Omnichannel\Addons\AgentRuntime\Domain\AgentProjectScope;

interface AgentInternalLinkResolver
{
    public function resolve(string $entityRef, AgentProjectScope $scope): ?string;
}

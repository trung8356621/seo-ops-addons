<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentGroundedContextPackage;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeQuery;

interface AgentKnowledgeRetriever
{
    public function retrieve(AgentKnowledgeQuery $query): AgentGroundedContextPackage;
}

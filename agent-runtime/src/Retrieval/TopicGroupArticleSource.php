<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Retrieval;

interface TopicGroupArticleSource
{
    /**
     * Keyword Group → keywords → focus articles → SEO score.
     * Semantic matching stays outside this interface's implementation boundary.
     *
     * @return array<string, mixed>
     */
    public function retrieve(int $siteId, string $query): array;
}

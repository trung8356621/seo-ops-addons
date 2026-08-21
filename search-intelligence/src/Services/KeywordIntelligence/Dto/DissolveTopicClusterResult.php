<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final readonly class DissolveTopicClusterResult
{
    private function __construct(
        public string $clusterKey,
        public int $affectedKeywordCount,
        public bool $wasAlreadyEmpty,
        public bool $success,
    ) {}

    public static function success(string $clusterKey, int $affectedKeywordCount): self
    {
        return new self($clusterKey, $affectedKeywordCount, false, true);
    }

    public static function alreadyEmpty(string $clusterKey): self
    {
        return new self($clusterKey, 0, true, true);
    }

    public static function invalidClusterKey(): self
    {
        return new self('', 0, false, false);
    }

    public static function failed(string $clusterKey): self
    {
        return new self($clusterKey, 0, false, false);
    }
}

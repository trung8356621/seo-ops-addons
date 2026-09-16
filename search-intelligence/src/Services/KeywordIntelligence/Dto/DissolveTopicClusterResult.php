<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final readonly class DissolveTopicClusterResult
{
    public const REASON_NONE = '';

    public const REASON_INVALID_KEY = 'invalid_key';

    public const REASON_FAILED = 'failed';

    /** Global cluster_key cannot be cleared while another site still owns member keyword(s). */
    public const REASON_SHARED_OWNERSHIP = 'shared_ownership';

    private function __construct(
        public string $clusterKey,
        public int $affectedKeywordCount,
        public bool $wasAlreadyEmpty,
        public bool $success,
        public string $failureReason = self::REASON_NONE,
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
        return new self('', 0, false, false, self::REASON_INVALID_KEY);
    }

    public static function failed(string $clusterKey): self
    {
        return new self($clusterKey, 0, false, false, self::REASON_FAILED);
    }

    public static function blockedSharedOwnership(string $clusterKey): self
    {
        return new self($clusterKey, 0, false, false, self::REASON_SHARED_OWNERSHIP);
    }
}

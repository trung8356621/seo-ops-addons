<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

final class ReclusterTopicClustersResult
{
    /**
     * @param  array<string, int>  $metrics
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $metrics,
        public readonly ?string $error = null,
    ) {}

    /**
     * @param  array<string, int>  $metrics
     */
    public static function ok(array $metrics): self
    {
        return new self(success: true, metrics: $metrics);
    }

    public static function failed(string $error): self
    {
        return new self(success: false, metrics: [], error: $error);
    }
}

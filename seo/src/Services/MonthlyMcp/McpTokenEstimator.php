<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

/**
 * Rough token estimate for MCP markdown. Replace with a real tokenizer later.
 */
final class McpTokenEstimator
{
    private const CHARS_PER_TOKEN = 4;

    /**
     * @return array{characters: int, estimated_tokens: int}
     */
    public function estimate(string $markdown): array
    {
        $characters = mb_strlen($markdown);
        $estimated = (int) ceil($characters / self::CHARS_PER_TOKEN);

        return [
            'characters' => $characters,
            'estimated_tokens' => max(0, $estimated),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Contracts;

/**
 * Domain attach PromptResult → allowlisted targets.
 * Hook Engine must not call this — callers / Business Actions only.
 */
interface PromptResultAttacher
{
    /**
     * @param  array<string, mixed>  $meta
     * @return array{attached: bool, deduplicated: bool, prompt_result_id: int, target_type: string, target_id: int}
     */
    public function attach(
        int $promptResultId,
        string $targetType,
        int $targetId,
        int $siteId,
        string $purpose = 'manual',
        array $meta = [],
    ): array;
}

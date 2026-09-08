<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * ARTICLE MODEL AUTHORITY:
 * For article generation, preserve AI Center sortable order.
 * Only explicit user model override may intentionally reorder candidates.
 * Health / Free Only / availability may filter but must preserve relative order.
 *
 * FastEconomy / BestQuality must NOT reorder article candidates.
 */
final class ArticleModelOrderAuthority
{
    /**
     * Whether generation_mode (FastEconomy / BestQuality) may reorder candidates.
     * Article writing hooks: never.
     */
    public static function allowsGenerationModeReorder(?string $hookKey): bool
    {
        return ! ArticleContentGenerationHooks::matches($hookKey);
    }
}

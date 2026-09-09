<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * MODEL ORDER AUTHORITY:
 * AI Center sortable order (areaEnabledModels) is the runtime execution order
 * for DEFAULT / ECONOMY / QUALITY. Cost class must not reorder candidates.
 *
 * Only explicit FreeOnly cost policy may remove paid candidates.
 * Only explicit user model override may intentionally float a preferred model.
 *
 * FastEconomy / BestQuality must NEVER reorder by free/paid or reverse the list.
 */
final class ArticleModelOrderAuthority
{
    /**
     * Whether generation_mode may reorder candidates.
     * Always false — manual sortable order wins for every hook.
     */
    public static function allowsGenerationModeReorder(?string $hookKey): bool
    {
        unset($hookKey);

        return false;
    }
}

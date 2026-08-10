<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscSearchAppearance: string
{
    case RichResults = 'rich_results';
    case Amp = 'amp';
    case Video = 'video';
    case Image = 'image';
    case Faq = 'faq';
    case ReviewSnippet = 'review_snippet';
    case Other = 'other';
    case None = 'none';

    public static function tryFromLoose(?string $value): self
    {
        $value = mb_strtolower(trim(str_replace(' ', '_', (string) $value)), 'UTF-8');
        if ($value === '' || $value === 'null') {
            return self::None;
        }

        return self::tryFrom($value) ?? self::Other;
    }
}

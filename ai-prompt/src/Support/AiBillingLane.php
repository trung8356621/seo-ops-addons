<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Generic billing / route class for a candidate — not OpenRouter-specific.
 */
enum AiBillingLane: string
{
    case Paid = 'paid';
    case Free = 'free';

    public static function fromCandidate(bool $isFree): self
    {
        return $isFree ? self::Free : self::Paid;
    }
}

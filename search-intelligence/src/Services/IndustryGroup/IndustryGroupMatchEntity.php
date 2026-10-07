<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup;

/**
 * Caller-supplied match entity. Not a Keyword Eloquent model.
 * Caller owns ref (e.g. keyword:abc123) for future redesign compatibility.
 */
final class IndustryGroupMatchEntity
{
    public function __construct(
        public readonly string $ref,
        public readonly string $text,
    ) {}
}

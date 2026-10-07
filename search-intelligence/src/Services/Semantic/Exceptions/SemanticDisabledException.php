<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions;

/**
 * Semantic integration is intentionally disabled (config).
 * Not a transport/availability failure — do not raise Operational Alert Hook.
 */
final class SemanticDisabledException extends SemanticHttpException
{
    public static function disabled(): self
    {
        return new self(
            'Semantic analytics is disabled (semantic.enabled=false).',
            'semantic_disabled',
        );
    }
}

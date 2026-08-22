<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * Canonical Automatic/Custom candidate sequence for a profile.
 * Image hard rules stay in ImageRoutingStrategy (media); this only orders eligible targets.
 *
 * @deprecated Prefer {@see CanonicalAiRouteResolver}
 */
final class AiModelPriorityResolver
{
    public function __construct(
        private readonly CanonicalAiRouteResolver $canonical,
    ) {}

    /**
     * @return list<RoutedAiCandidate>
     */
    public function resolve(int $userId, AiExecutionProfile $profile, AiRoutingContext $context): array
    {
        unset($userId);

        return $this->canonical->resolveExecutable($context->userId ?? 0, $profile, $context);
    }

    public function areaFor(AiExecutionProfile $profile): AiModelArea
    {
        return $this->canonical->areaFor($profile);
    }
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;

/**
 * Canonical production eligibility filters (provider/family) by execution profile + hook.
 *
 * DeepSeek is eligible for TextLongform (keyword discovery + article content) including every
 * physical route under a logical model (Direct + OpenRouter).
 * DeepSeek remains excluded from Outline/Vocabulary (TextReasoning).
 */
final class AiProductionRouteEligibility
{
    /**
     * @param  list<RoutedAiCandidate>  $candidates
     * @return list<RoutedAiCandidate>
     */
    public function filter(array $candidates, AiExecutionProfile $profile, ?AiRoutingContext $context = null): array
    {
        $hookKey = trim((string) ($context?->hookKey ?? ''));

        $out = [];
        foreach ($candidates as $candidate) {
            if ($this->isDeepSeekCandidate($candidate) && ! $this->deepSeekAllowed($profile, $hookKey)) {
                continue;
            }
            $out[] = $candidate;
        }

        return array_values($out);
    }

    public function deepSeekAllowed(AiExecutionProfile $profile, string $hookKey): bool
    {
        // Outline / Vocabulary production must not use DeepSeek (any physical route).
        if ($profile === AiExecutionProfile::TextReasoning) {
            return false;
        }

        return true;
    }

    private function isDeepSeekCandidate(RoutedAiCandidate $candidate): bool
    {
        if (strcasecmp((string) $candidate->provider, ApiConnectionProviders::DEEPSEEK) === 0) {
            return true;
        }

        $model = strtolower(trim((string) $candidate->model));

        return str_starts_with($model, 'deepseek/')
            || str_starts_with($model, 'deepseek-')
            || $model === 'deepseek';
    }
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;

/**
 * Canonical production eligibility filters (provider/family) by execution profile + hook.
 *
 * DeepSeek remains eligible for keyword discovery / keyword test (TextLongform + KD hook).
 * DeepSeek is not eligible for Outline/Vocabulary (TextReasoning) or article longform production.
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
        if ($profile === AiExecutionProfile::TextReasoning) {
            return false;
        }

        if ($profile === AiExecutionProfile::TextLongform) {
            if ($hookKey === '' || str_starts_with($hookKey, 'keyword.')) {
                return true;
            }

            if (str_starts_with($hookKey, 'article.')) {
                return false;
            }
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

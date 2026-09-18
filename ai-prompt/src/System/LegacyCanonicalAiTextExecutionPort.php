<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\System;

use App\System\Ai\Contracts\AiTextExecutionPort;
use Omnichannel\Addons\AiPrompt\Services\CanonicalAiTextExecutionService;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;

/**
 * Legacy adapter: System AI text port → CanonicalAiTextExecutionService (existing SEO DB / routing).
 * No DB migration. Preserves FreeOnly / priority / failure taxonomy via existing router.
 */
final class LegacyCanonicalAiTextExecutionPort implements AiTextExecutionPort
{
    public function __construct(
        private readonly CanonicalAiTextExecutionService $canonical,
    ) {}

    public function generate(string $compiledPrompt, string $hookKey, array $options = []): array
    {
        $profileRaw = $options['profile'] ?? null;
        $profile = is_string($profileRaw)
            ? (AiExecutionProfile::tryFrom($profileRaw) ?? AiExecutionProfile::TextFast)
            : AiExecutionProfile::TextFast;

        [$text, $usage, $candidate] = $this->canonical->generate(
            $compiledPrompt,
            $hookKey,
            $profile,
            null,
            $options,
        );

        $provider = null;
        $model = null;
        $physicalRoute = null;
        if (is_object($candidate) && method_exists($candidate, 'physicalRouteKey')) {
            $physicalRoute = (string) $candidate->physicalRouteKey();
        }
        if (is_object($candidate)) {
            if (isset($candidate->provider)) {
                $provider = (string) $candidate->provider;
            }
            if (isset($candidate->model)) {
                $model = (string) $candidate->model;
            }
        }

        return [
            'text' => $text,
            'usage' => is_array($usage) ? $usage : null,
            'provider' => $provider,
            'model' => $model,
            'physical_route' => $physicalRoute,
            'trace' => [
                'adapter' => 'legacy_canonical',
                'hook_key' => $hookKey,
            ],
        ];
    }
}

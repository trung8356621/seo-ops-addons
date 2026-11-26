<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiModelCapability;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;

/**
 * Capability gate for heuristic auto-map. Unknown stays unknown — no invented chat support.
 */
final class AiTextCompatibilityAssessor
{
    public const MIN_AUTO_MAP_CONFIDENCE = 0.55;

    public function __construct(
        private readonly ModelCapabilityRegistry $capabilities = new ModelCapabilityRegistry(),
    ) {}

    /**
     * @return array{
     *   compatible: bool,
     *   confidence: float,
     *   area: AiModelArea|null,
     *   capability: string,
     *   reason: string,
     *   capabilities: list<string>
     * }
     */
    public function assess(ApiConnection $connection, SeoAiModel $model): array
    {
        $raw = (string) $model->raw_model_name;
        $lower = strtolower($raw);
        $caps = $this->capabilities->capabilitiesFor($connection, $raw);

        if ($this->looksNonTextWorkload($lower)) {
            return $this->reject('rejected_non_text_workload', $caps);
        }

        if (! in_array(AiModelCapability::TextGenerate->value, $caps, true)) {
            return $this->reject('rejected_missing_text_generate', $caps);
        }

        if (! $this->looksChatCompatible($lower)) {
            return $this->reject('rejected_uncertain_chat_compatibility', $caps, confidence: 0.25);
        }

        $bag = is_array($model->capabilities) ? $model->capabilities : [];
        $primary = AiModelArea::tryFromMixed($bag[AiModelArea::PRIMARY_TYPE_KEY] ?? '');
        $area = $primary->isTextPrimary() ? $primary : AiModelArea::TextFast;

        $confidence = 0.58;
        if ($this->strongChatHint($lower)) {
            $confidence += 0.18;
        }
        if (in_array(AiModelCapability::TextReasoning->value, $caps, true) && $area === AiModelArea::TextReasoning) {
            $confidence += 0.05;
        }
        $meta = is_array($bag['provider_metadata'] ?? null) ? $bag['provider_metadata'] : [];
        if ($meta !== []) {
            $confidence += 0.08;
        }

        return [
            'compatible' => true,
            'confidence' => min(0.92, $confidence),
            'area' => $area,
            'capability' => AiModelCapability::TextGenerate->value,
            'reason' => 'text_generate_compatible',
            'capabilities' => $caps,
        ];
    }

    public function allowsHeuristicAutoMap(ApiConnection $connection): bool
    {
        $provider = strtolower(trim((string) $connection->provider));
        if ($provider === '') {
            return false;
        }
        // Major curated providers keep catalog-first behavior; unknowns stay Available.
        if (in_array($provider, [
            ApiConnectionProviders::OPENROUTER,
            ApiConnectionProviders::GEMINI,
            ApiConnectionProviders::CLAUDE,
            ApiConnectionProviders::DEEPSEEK,
        ], true)) {
            return false;
        }
        if ($provider === ApiConnectionProviders::NVIDIA) {
            return true;
        }
        if (ApiConnectionProviders::isSeo($provider) || ApiConnectionProviders::isExternal($provider)) {
            return false;
        }
        if (ApiConnectionProviders::allowsTemplateBackedText($provider, (int) ($connection->user_id ?? 0))) {
            return true;
        }
        if (ApiConnectionProviders::isCustomOpenAiProvider($provider)) {
            return true;
        }

        // Custom OpenAI-compatible connection keys (e.g. groqcloud) without a template row yet.
        return ! ApiConnectionProviders::isBuiltinAiProvider($provider)
            || $provider === ApiConnectionProviders::NVIDIA;
    }

    /**
     * @param  list<string>  $caps
     * @return array{
     *   compatible: bool,
     *   confidence: float,
     *   area: AiModelArea|null,
     *   capability: string,
     *   reason: string,
     *   capabilities: list<string>
     * }
     */
    private function reject(string $reason, array $caps, float $confidence = 0.0): array
    {
        return [
            'compatible' => false,
            'confidence' => $confidence,
            'area' => null,
            'capability' => '',
            'reason' => $reason,
            'capabilities' => $caps,
        ];
    }

    private function looksNonTextWorkload(string $lower): bool
    {
        if (OpenRouterModelEconomics::looksLikeNonChatId($lower)) {
            return true;
        }

        foreach ([
            'guard', 'safety', 'detector', 'retriev', 'rerank', 'embed',
            'clip', 'gliner', 'pii', 'calibrat', 'starcoder', 'codec',
            'whisper', 'tts', 'moderation', 'translate', 'riva', 'speech',
            'nemoretriever', 'nvclip',
        ] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return (str_contains($lower, 'image') && ! str_contains($lower, 'vl'))
            || str_contains($lower, 'video');
    }

    private function looksChatCompatible(string $lower): bool
    {
        return $this->strongChatHint($lower)
            || str_contains($lower, 'instruct')
            || str_contains($lower, '-it')
            || str_ends_with($lower, 'it')
            || str_contains($lower, 'chat')
            || str_contains($lower, 'nemotron')
            || str_contains($lower, 'reason');
    }

    private function strongChatHint(string $lower): bool
    {
        foreach ([
            'gemma', 'mistral', 'llama', 'qwen', 'kimi', 'minimax',
            'deepseek', 'phi-', 'gpt-', 'claude', 'nemotron',
        ] as $hint) {
            if (str_contains($lower, $hint)) {
                return true;
            }
        }

        return false;
    }
}

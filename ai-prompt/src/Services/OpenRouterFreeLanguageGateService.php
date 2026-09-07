<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\OpenRouterFreeLanguageState;
use Omnichannel\Addons\Seo\Services\SeoContentLanguageSettingsService;

/**
 * Language gate for OpenRouter free TEXT models.
 * English: auto-pass (no LLM). Non-English: PENDING until manual evaluation.
 */
final class OpenRouterFreeLanguageGateService
{
    public const CAP_KEY = 'language_gate';

    public function __construct(
        private readonly ?SeoContentLanguageSettingsService $languages = null,
    ) {}

    public function primaryLanguage(): string
    {
        $service = $this->languages ?? (function_exists('app')
            ? app(SeoContentLanguageSettingsService::class)
            : null);
        $lang = $service !== null
            ? strtolower(trim($service->getDefaultContentLanguage()))
            : 'en';
        if ($lang === '') {
            return 'en';
        }
        // Normalize vi-VN → vi, en_US → en
        if (str_contains($lang, '-') || str_contains($lang, '_')) {
            $lang = strtolower((string) preg_replace('/[_-].*$/', '', $lang));
        }

        return $lang !== '' ? $lang : 'en';
    }

    public function isEnglishPrimary(): bool
    {
        return str_starts_with($this->primaryLanguage(), 'en');
    }

    /**
     * Effective gate for pool membership / runtime.
     * English always Supported without consuming tokens.
     */
    public function effectiveState(SeoAiModel $model): OpenRouterFreeLanguageState
    {
        if ($this->isEnglishPrimary()) {
            return OpenRouterFreeLanguageState::Supported;
        }
        $lang = $this->primaryLanguage();
        $bag = $this->gateBag($model, $lang);
        $state = OpenRouterFreeLanguageState::tryFromMixed($bag['state'] ?? null);

        return $state ?? OpenRouterFreeLanguageState::Pending;
    }

    /**
     * @return array{state: string, confidence: ?float, reason: ?string, evaluated_at: ?string, fingerprint: ?string}
     */
    public function gateBag(SeoAiModel $model, string $language): array
    {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $all = is_array($caps[self::CAP_KEY] ?? null) ? $caps[self::CAP_KEY] : [];
        $bag = is_array($all[$language] ?? null) ? $all[$language] : [];

        return [
            'state' => (string) ($bag['state'] ?? OpenRouterFreeLanguageState::Pending->value),
            'confidence' => isset($bag['confidence']) ? (float) $bag['confidence'] : null,
            'reason' => isset($bag['reason']) ? (string) $bag['reason'] : null,
            'evaluated_at' => isset($bag['evaluated_at']) ? (string) $bag['evaluated_at'] : null,
            'fingerprint' => isset($bag['fingerprint']) ? (string) $bag['fingerprint'] : null,
        ];
    }

    public function evaluationFingerprint(SeoAiModel $model, string $language): string
    {
        return hash('sha256', strtolower(trim((string) $model->raw_model_name)).'|'.strtolower($language));
    }

    /**
     * Persist a manual evaluation result. Does not reorder OpenRouter ranking.
     */
    public function recordEvaluation(
        SeoAiModel $model,
        string $language,
        bool $supported,
        ?float $confidence = null,
        ?string $reason = null,
    ): void {
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $all = is_array($caps[self::CAP_KEY] ?? null) ? $caps[self::CAP_KEY] : [];
        $all[strtolower($language)] = [
            'state' => ($supported
                ? OpenRouterFreeLanguageState::Supported
                : OpenRouterFreeLanguageState::Unsupported)->value,
            'confidence' => $confidence,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'evaluated_at' => now()->toIso8601String(),
            'fingerprint' => $this->evaluationFingerprint($model, $language),
        ];
        $caps[self::CAP_KEY] = $all;
        // Keep legacy key as opaque; gate bag is authoritative.
        $caps['language_suitability'] = $supported ? 'supported' : 'unsupported';
        $model->capabilities = $caps;
        $model->save();
    }

    /**
     * Ensure newly discovered free models start as PENDING for non-English.
     */
    public function ensurePendingIfMissing(SeoAiModel $model): void
    {
        if ($this->isEnglishPrimary()) {
            return;
        }
        $lang = $this->primaryLanguage();
        $caps = is_array($model->capabilities) ? $model->capabilities : [];
        $all = is_array($caps[self::CAP_KEY] ?? null) ? $caps[self::CAP_KEY] : [];
        if (isset($all[$lang]) && is_array($all[$lang]) && ($all[$lang]['state'] ?? '') !== '') {
            return;
        }
        $all[$lang] = [
            'state' => OpenRouterFreeLanguageState::Pending->value,
            'confidence' => null,
            'reason' => null,
            'evaluated_at' => null,
            'fingerprint' => $this->evaluationFingerprint($model, $lang),
        ];
        $caps[self::CAP_KEY] = $all;
        $caps['language_suitability'] = 'pending';
        $model->capabilities = $caps;
        $model->save();
    }
}

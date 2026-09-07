<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

/**
 * Primary-language eligibility gate for Free Pool curation.
 * Ranking stays with OpenRouter scores — this only returns supported true/false.
 */
final class AiPrimaryLanguageGateService
{
    public const CONFIDENCE_HIGH = 'high';

    public const CONFIDENCE_MEDIUM = 'medium';

    public const CONFIDENCE_LOW = 'low';

    public function isEnglish(string $language): bool
    {
        $normalized = strtolower(trim($language));

        return in_array($normalized, ['en', 'eng', 'english', 'en-us', 'en-gb'], true);
    }

    /**
     * English skips paid AI evaluation entirely.
     *
     * @return array{supported: bool, confidence: string, skipped_llm: bool, reason: string}
     */
    public function englishPassthrough(string $modelKey): array
    {
        return [
            'supported' => true,
            'confidence' => self::CONFIDENCE_HIGH,
            'skipped_llm' => true,
            'reason' => 'english_baseline',
            'model_key' => $modelKey,
        ];
    }

    /**
     * Persist/reuse fingerprint — never auto-invoked from model sync.
     *
     * @param  array<string, mixed>  $prior
     */
    public function shouldReevaluate(array $prior, string $modelKey, string $language, string $fingerprint): bool
    {
        if ($this->isEnglish($language)) {
            return false;
        }
        if (($prior['model_key'] ?? '') !== $modelKey) {
            return true;
        }
        if (($prior['language'] ?? '') !== strtolower(trim($language))) {
            return true;
        }
        if (($prior['fingerprint'] ?? '') !== $fingerprint) {
            return true;
        }

        return ! array_key_exists('supported', $prior);
    }
}

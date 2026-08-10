<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordFunnelStage;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiExecutionContext;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\Ai\AiTextRequest;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\AiTextProviderInterface;
use Omnichannel\Addons\AiPrompt\Extension\Resolvers\AiProviderResolver;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto\KeywordIntentResult;
use Throwable;

/**
 * Rule-based intent classifier — AI optional via provider boundary later.
 * Manual override luôn thắng.
 *
 * Path AI (classifyWithOptionalAi) hoàn toàn optional: KHÔNG import trực tiếp
 * Gemini/Claude — chỉ đi qua AiProviderResolver (extension boundary), fail-safe về
 * source=needs_review nếu provider không khả dụng hoặc output không hợp lệ.
 */
final class KeywordIntentClassifier
{
    private const DEFAULT_AI_CONFIDENCE_THRESHOLD = 0.55;

    private const DEFAULT_CLASSIFIER_VERSION = 'rule-v1';

    public function __construct(
        private readonly ?AiProviderResolver $aiProviderResolver = null,
    ) {}

    /**
     * @return array{
     *   primary: KeywordSearchIntent,
     *   secondary: list<string>,
     *   funnel: KeywordFunnelStage,
     *   confidence: float,
     *   source: string
     * }
     */
    public function classify(string $displayKeyword, string $normalizedKeyword): array
    {
        $text = mb_strtolower(trim($displayKeyword !== '' ? $displayKeyword : $normalizedKeyword), 'UTF-8');
        $secondary = [];

        $primary = KeywordSearchIntent::Unknown;
        $confidence = 0.35;

        $localMarkers = [
            'tphcm', 'tp hcm',
            "h\u{1ED3} ch\u{00ED} minh", // hồ chí minh
            "h\u{00E0} n\u{1ED9}i", // hà nội
            'ha noi',
            "\u{0111}\u{00E0} n\u{1EB5}ng", // đà nẵng
            'da nang',
            "c\u{1EA7}n th\u{01A1}", // cần thơ
            'can tho',
            "g\u{1EA7}n \u{0111}\u{00E2}y", // gần đây
            'near me',
        ];
        $transactional = [
            'mua',
            "\u{0111}\u{1EB7}t h\u{00E0}ng", // đặt hàng
            'dat hang',
            "gi\u{00E1}", // giá
            'gia ',
            "b\u{00E1}o gi\u{00E1}", // báo giá
            'bao gia',
            'order', 'buy', 'pricing', 'cost',
        ];
        $commercial = [
            "d\u{1ECB}ch v\u{1EE5}", // dịch vụ
            'dich vu',
            'best', 'top',
            "so s\u{00E1}nh", // so sánh
            'so sanh',
            'review',
            "\u{0111}\u{00E1}nh gi\u{00E1}", // đánh giá
            'danh gia',
            'agency',
            "c\u{00F4}ng ty", // công ty
            'cong ty',
        ];
        $navigational = [
            'login',
            "\u{0111}\u{0103}ng nh\u{1EAD}p", // đăng nhập
            'dang nhap',
            'official',
            "trang ch\u{1EE7}", // trang chủ
            'trang chu',
        ];
        $informational = [
            "l\u{00E0} g\u{00EC}", // là gì
            'la gi',
            'what is', 'how to',
            "c\u{00E1}ch", // cách
            'cach ',
            "h\u{01B0}\u{1EDB}ng d\u{1EAB}n", // hướng dẫn
            'huong dan',
            'tips', 'checklist',
        ];

        if ($this->containsAny($text, $localMarkers)) {
            $primary = KeywordSearchIntent::Local;
            $confidence = 0.75;
            if ($this->containsAny($text, $commercial) || $this->containsAny($text, $transactional)) {
                $secondary[] = KeywordSearchIntent::Commercial->value;
                $secondary[] = KeywordSearchIntent::Transactional->value;
            }
        } elseif ($this->containsAny($text, $transactional)) {
            $primary = KeywordSearchIntent::Transactional;
            $confidence = 0.7;
        } elseif ($this->containsAny($text, $commercial)) {
            $primary = KeywordSearchIntent::Commercial;
            $confidence = 0.68;
        } elseif ($this->containsAny($text, $navigational)) {
            $primary = KeywordSearchIntent::Navigational;
            $confidence = 0.65;
        } elseif ($this->containsAny($text, $informational)) {
            $primary = KeywordSearchIntent::Informational;
            $confidence = 0.72;
        }

        if ($primary === KeywordSearchIntent::Local && $secondary !== []) {
            $primary = KeywordSearchIntent::Mixed;
            $confidence = min(0.8, $confidence + 0.05);
            array_unshift($secondary, KeywordSearchIntent::Local->value);
            $secondary = array_values(array_unique($secondary));
        }

        $funnel = match ($primary) {
            KeywordSearchIntent::Informational => KeywordFunnelStage::Awareness,
            KeywordSearchIntent::Commercial => KeywordFunnelStage::Consideration,
            KeywordSearchIntent::Transactional, KeywordSearchIntent::Local => KeywordFunnelStage::Decision,
            KeywordSearchIntent::Mixed => KeywordFunnelStage::Consideration,
            default => KeywordFunnelStage::Unknown,
        };

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'funnel' => $funnel,
            'confidence' => $confidence,
            'source' => 'rule',
        ];
    }

    /**
     * Wraps classify() into a stable DTO shape — không thay đổi logic rule-based hiện có.
     */
    public function classifyResult(string $displayKeyword, string $normalizedKeyword): KeywordIntentResult
    {
        $result = $this->classify($displayKeyword, $normalizedKeyword);

        return new KeywordIntentResult(
            primaryIntent: $result['primary'],
            secondaryIntents: $result['secondary'],
            funnel: $result['funnel'],
            confidence: $result['confidence'],
            source: $result['source'],
            reasonCodes: [],
            classifierVersion: $this->classifierVersion(),
        );
    }

    /**
     * Rule-first, AI chỉ được gọi khi use_ai=true VÀ confidence rule thấp hơn threshold.
     * Luôn hoạt động được mà không cần Laravel container (pure rule path là default).
     *
     * @param  array{use_ai?: bool, provider_key?: string|null, model?: string|null, site_id?: int|null, workspace_settings?: array<string, mixed>}  $options
     */
    public function classifyWithOptionalAi(string $displayKeyword, string $normalizedKeyword, array $options = []): KeywordIntentResult
    {
        $ruleResult = $this->classifyResult($displayKeyword, $normalizedKeyword);

        $useAi = (bool) ($options['use_ai'] ?? false);
        if (! $useAi || $ruleResult->confidence >= $this->aiConfidenceThreshold()) {
            return $ruleResult;
        }

        $providerKey = trim((string) ($options['provider_key'] ?? ''));
        if ($providerKey === '') {
            return $ruleResult;
        }

        try {
            $provider = $this->resolveAiTextProvider($providerKey);
            if ($provider === null) {
                return $ruleResult;
            }

            $siteId = isset($options['site_id']) ? (int) $options['site_id'] : null;
            $context = new AiExecutionContext(providerKey: $providerKey, siteId: $siteId);
            $request = new AiTextRequest(
                prompt: $this->buildAiIntentPrompt($displayKeyword, $normalizedKeyword),
                model: (string) ($options['model'] ?? ''),
            );

            $response = $provider->generate($request, $context);
            if (! $response->ok || trim($response->text) === '') {
                throw new \RuntimeException('AI intent classification returned an empty/failed result.');
            }

            return $this->parseAiIntentResponse($response->text, $ruleResult);
        } catch (Throwable) {
            return new KeywordIntentResult(
                primaryIntent: $ruleResult->primaryIntent,
                secondaryIntents: $ruleResult->secondaryIntents,
                funnel: $ruleResult->funnel,
                confidence: $ruleResult->confidence,
                source: 'needs_review',
                reasonCodes: ['keyword.intent_ai_invalid_output'],
                classifierVersion: $this->classifierVersion(),
            );
        }
    }

    /**
     * Resolve AiTextProviderInterface qua AiProviderResolver — injected trước, fallback
     * app() chỉ khi function_exists('app') (an toàn cho pure PHPUnit không boot container).
     * KHÔNG BAO GIỜ import trực tiếp implementation Gemini/Claude ở đây.
     */
    private function resolveAiTextProvider(string $providerKey): ?AiTextProviderInterface
    {
        $resolver = $this->aiProviderResolver;

        if ($resolver === null) {
            if (! class_exists(AiProviderResolver::class) || ! function_exists('app')) {
                return null;
            }

            try {
                $resolver = app(AiProviderResolver::class);
            } catch (Throwable) {
                return null;
            }
        }

        if (! $resolver instanceof AiProviderResolver) {
            return null;
        }

        try {
            return $resolver->resolveText($providerKey);
        } catch (Throwable) {
            return null;
        }
    }

    private function buildAiIntentPrompt(string $displayKeyword, string $normalizedKeyword): string
    {
        $keyword = $displayKeyword !== '' ? $displayKeyword : $normalizedKeyword;

        return 'Classify the search intent for the keyword: "'.$keyword.'". '
            .'Respond ONLY with compact JSON, no explanation: '
            .'{"primary_intent":"informational|commercial|transactional|navigational|local|mixed|unknown",'
            .'"secondary_intents":[],"funnel":"awareness|consideration|decision|retention|unknown","confidence":0.0}';
    }

    private function parseAiIntentResponse(string $raw, KeywordIntentResult $fallback): KeywordIntentResult
    {
        $json = trim($raw);
        if (str_starts_with($json, '```')) {
            $json = trim((string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $json));
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('AI intent output is not valid JSON.');
        }

        $primary = KeywordSearchIntent::tryFrom((string) ($decoded['primary_intent'] ?? ''));
        if ($primary === null) {
            throw new \RuntimeException('AI intent output missing a valid primary_intent.');
        }

        $funnel = KeywordFunnelStage::tryFrom((string) ($decoded['funnel'] ?? '')) ?? $fallback->funnel;
        $confidence = is_numeric($decoded['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $decoded['confidence']))
            : $fallback->confidence;

        $secondaryRaw = is_array($decoded['secondary_intents'] ?? null) ? $decoded['secondary_intents'] : [];
        $secondary = [];
        foreach ($secondaryRaw as $item) {
            $enum = KeywordSearchIntent::tryFrom((string) $item);
            if ($enum !== null) {
                $secondary[] = $enum->value;
            }
        }

        return new KeywordIntentResult(
            primaryIntent: $primary,
            secondaryIntents: array_values(array_unique($secondary)),
            funnel: $funnel,
            confidence: $confidence,
            source: 'ai',
            reasonCodes: [],
            classifierVersion: $this->classifierVersion(),
        );
    }

    private function aiConfidenceThreshold(): float
    {
        if (! function_exists('config')) {
            return self::DEFAULT_AI_CONFIDENCE_THRESHOLD;
        }

        try {
            return (float) config('seo-content-ai.keyword_intelligence.intent.ai_confidence_threshold', self::DEFAULT_AI_CONFIDENCE_THRESHOLD);
        } catch (Throwable) {
            return self::DEFAULT_AI_CONFIDENCE_THRESHOLD;
        }
    }

    private function classifierVersion(): string
    {
        if (! function_exists('config')) {
            return self::DEFAULT_CLASSIFIER_VERSION;
        }

        try {
            return (string) config('seo-content-ai.keyword_intelligence.intent.classifier_version', self::DEFAULT_CLASSIFIER_VERSION);
        } catch (Throwable) {
            return self::DEFAULT_CLASSIFIER_VERSION;
        }
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}

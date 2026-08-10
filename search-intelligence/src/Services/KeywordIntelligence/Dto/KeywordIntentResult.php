<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Dto;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordFunnelStage;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;

/**
 * Kết quả phân loại intent — bọc output của KeywordIntentClassifier (rule hoặc AI)
 * thành một shape ổn định cho Application layer / Filament / Agent.
 */
final class KeywordIntentResult
{
    /**
     * @param  list<string>  $secondaryIntents
     * @param  list<string>  $reasonCodes
     */
    public function __construct(
        public readonly KeywordSearchIntent $primaryIntent,
        public readonly array $secondaryIntents,
        public readonly KeywordFunnelStage $funnel,
        public readonly float $confidence,
        public readonly string $source,
        public readonly array $reasonCodes = [],
        public readonly string $classifierVersion = 'rule-v1',
    ) {}

    /**
     * @return array{
     *   primary_intent: string,
     *   secondary_intents: list<string>,
     *   funnel: string,
     *   confidence: float,
     *   source: string,
     *   reason_codes: list<string>,
     *   classifier_version: string
     * }
     */
    public function toArray(): array
    {
        return [
            'primary_intent' => $this->primaryIntent->value,
            'secondary_intents' => $this->secondaryIntents,
            'funnel' => $this->funnel->value,
            'confidence' => $this->confidence,
            'source' => $this->source,
            'reason_codes' => $this->reasonCodes,
            'classifier_version' => $this->classifierVersion,
        ];
    }
}

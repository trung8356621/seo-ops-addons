<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Các giai đoạn xử lý của một keyword analysis operation.
 */
enum KeywordAnalysisStage: string
{
    case Normalizing = 'normalizing';
    case Deduplicate = 'deduplicate';
    case ClassifyingIntent = 'classifying_intent';
    case Scoring = 'scoring';
    case MappingContent = 'mapping_content';
    case Clustering = 'clustering';
    /** @deprecated Phase 2 pipeline skips topical map — kept for BC */
    case BuildingTopics = 'building_topics';
    case DetectingCannibalization = 'detecting_cannibalization';
    case Finalize = 'finalize';
    case Completed = 'completed';
    case Failed = 'failed';
}

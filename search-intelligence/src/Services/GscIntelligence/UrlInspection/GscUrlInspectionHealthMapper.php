<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\UrlInspection;

use Omnichannel\Addons\Seo\Enums\ArticleIndexCheckStatus;

/**
 * Map Google URL Inspection machine fields → Index Health check status.
 * Never emits dropped (derived by ArticleIndexHealthRecorder).
 */
final class GscUrlInspectionHealthMapper
{
    public function map(GscUrlInspectionResult $result): ArticleIndexCheckStatus
    {
        $verdict = strtoupper(trim((string) ($result->verdict ?? '')));

        return match ($verdict) {
            'PASS' => ArticleIndexCheckStatus::Indexed,
            'FAIL' => ArticleIndexCheckStatus::NotIndexed,
            'PARTIAL', 'NEUTRAL', 'VERDICT_UNSPECIFIED', '' => ArticleIndexCheckStatus::Unknown,
            default => ArticleIndexCheckStatus::Unknown,
        };
    }
}

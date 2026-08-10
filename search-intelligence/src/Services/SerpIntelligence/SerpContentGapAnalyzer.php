<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpContentGapType;

/**
 * Content gap analysis — không tạo strong gap chỉ từ 1 heading.
 */
final class SerpContentGapAnalyzer
{
    public const CONTENT_GAP_ANALYZER_VERSION = '1.0.0';

    /**
     * @param  array<string, mixed>  $ownEvidence
     * @param  list<array<string, mixed>>  $competitorEvidence
     * @return list<array{
     *   gap_type: SerpContentGapType,
     *   importance: float,
     *   frequency: float,
     *   confidence: float,
     *   reason_codes: list<string>,
     *   metadata: array<string, mixed>
     * }>
     */
    public function analyze(array $ownEvidence, array $competitorEvidence, ?array $config = null): array
    {
        if ($competitorEvidence === []) {
            return [];
        }

        $minFrequency = (float) ($config['min_frequency'] ?? $this->configFloat('content_gap.min_frequency', 0.3));
        $minConfidence = (float) ($config['min_confidence'] ?? $this->configFloat('content_gap.min_confidence', 0.45));
        $gaps = [];

        $competitorCount = count($competitorEvidence);
        $faqPresent = $this->countWhere($competitorEvidence, static fn (array $row): bool => ((int) ($row['faq_count'] ?? 0)) > 0);
        $schemaPresent = $this->countWhere($competitorEvidence, static fn (array $row): bool => (($row['schema_types'] ?? []) !== []));
        $mediaRich = $this->countWhere($competitorEvidence, static fn (array $row): bool => ((int) ($row['media_count'] ?? 0)) >= 3);
        $deepContent = $this->countWhere($competitorEvidence, static fn (array $row): bool => ((int) ($row['word_count_approx'] ?? 0)) >= 800);

        $ownFaq = (int) ($ownEvidence['faq_count'] ?? 0);
        $ownSchema = is_array($ownEvidence['schema_types'] ?? null) ? count($ownEvidence['schema_types']) : 0;
        $ownMedia = (int) ($ownEvidence['media_count'] ?? 0);
        $ownWords = (int) ($ownEvidence['word_count_approx'] ?? 0);

        if ($faqPresent / $competitorCount >= $minFrequency && $ownFaq === 0) {
            $gaps[] = $this->gap(SerpContentGapType::MissingQuestion, $faqPresent / $competitorCount, ['competitors_with_faq']);
        }

        if ($schemaPresent / $competitorCount >= $minFrequency && $ownSchema === 0) {
            $gaps[] = $this->gap(SerpContentGapType::MissingSchema, $schemaPresent / $competitorCount, ['competitors_with_schema']);
        }

        if ($mediaRich / $competitorCount >= $minFrequency && $ownMedia < 2) {
            $gaps[] = $this->gap(SerpContentGapType::MissingMedia, $mediaRich / $competitorCount, ['competitors_media_rich']);
        }

        if ($deepContent / $competitorCount >= $minFrequency && $ownWords > 0 && $ownWords < 500) {
            $gaps[] = $this->gap(SerpContentGapType::WeakCoverage, $deepContent / $competitorCount, ['competitors_deep_content']);
        }

        $comparisonSignals = $this->countWhere($competitorEvidence, static fn (array $row): bool => self::hasComparisonSignals($row));
        if ($comparisonSignals / $competitorCount >= $minFrequency) {
            $gaps[] = $this->gap(SerpContentGapType::MissingComparison, $comparisonSignals / $competitorCount, ['competitors_comparison_format']);
        }

        $sectionGaps = $this->detectStructuredSectionGaps($ownEvidence, $competitorEvidence);
        foreach ($sectionGaps as $sectionGap) {
            $gaps[] = $sectionGap;
        }

        return array_values(array_filter(
            $gaps,
            static fn (array $gap): bool => ($gap['confidence'] ?? 0.0) >= $minConfidence,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $competitorEvidence
     * @return list<array<string, mixed>>
     */
    private function detectStructuredSectionGaps(array $ownEvidence, array $competitorEvidence): array
    {
        $sectionCounts = [];
        foreach ($competitorEvidence as $row) {
            $headings = is_array($row['headings']['h2'] ?? null) ? $row['headings']['h2'] : [];
            foreach ($headings as $heading) {
                if (! is_string($heading) || mb_strlen($heading, 'UTF-8') < 4) {
                    continue;
                }
                $key = mb_strtolower(trim($heading), 'UTF-8');
                $sectionCounts[$key] = ($sectionCounts[$key] ?? 0) + 1;
            }
        }

        if ($sectionCounts === []) {
            return [];
        }

        arsort($sectionCounts);
        $ownH2 = [];
        foreach (is_array($ownEvidence['headings']['h2'] ?? null) ? $ownEvidence['headings']['h2'] : [] as $heading) {
            if (is_string($heading)) {
                $ownH2[] = mb_strtolower(trim($heading), 'UTF-8');
            }
        }

        $gaps = [];
        $competitorCount = count($competitorEvidence);
        $minFrequency = $this->configFloat('content_gap.section_min_frequency', 0.4);

        foreach ($sectionCounts as $section => $count) {
            if ($count / $competitorCount < $minFrequency) {
                continue;
            }
            if (in_array($section, $ownH2, true)) {
                continue;
            }

            // Không tạo strong gap chỉ từ 1 heading — cần frequency >= threshold và >=2 competitors.
            if ($count < 2) {
                continue;
            }

            $gaps[] = $this->gap(
                SerpContentGapType::MissingHeading,
                $count / $competitorCount,
                ['missing_h2_section', 'section:'.$section],
                ['section' => $section, 'competitor_hits' => $count],
            );
        }

        return $gaps;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function countWhere(array $rows, callable $predicate): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (is_array($row) && $predicate($row)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param array<string, mixed> $row */
    private static function hasComparisonSignals(array $row): bool
    {
        $schema = is_array($row['schema_types'] ?? null) ? $row['schema_types'] : [];
        foreach ($schema as $type) {
            if (is_string($type) && str_contains(mb_strtolower($type, 'UTF-8'), 'product')) {
                return true;
            }
        }

        $title = mb_strtolower((string) ($row['title'] ?? ''), 'UTF-8');

        return str_contains($title, 'vs') || str_contains($title, 'so sánh') || str_contains($title, 'compare');
    }

    /**
     * @return array<string, mixed>
     */
    private function gap(SerpContentGapType $type, float $frequency, array $reasonCodes, array $metadata = []): array
    {
        $importance = min(0.95, 0.35 + ($frequency * 0.6));
        $confidence = min(0.92, 0.4 + ($frequency * 0.5));

        return [
            'gap_type' => $type,
            'importance' => round($importance, 4),
            'frequency' => round($frequency, 4),
            'confidence' => round($confidence, 4),
            'reason_codes' => $reasonCodes,
            'metadata' => array_merge(['analyzer_version' => self::CONTENT_GAP_ANALYZER_VERSION], $metadata),
        ];
    }

    private function configFloat(string $key, float $default): float
    {
        if (! function_exists('config')) {
            return $default;
        }

        try {
            return (float) config('seo-content-ai.serp_intelligence.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}

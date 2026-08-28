<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscContentAction;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityType;

/**
 * Map GSC opportunity/cannibalization evidence → Planning Intelligence signals.
 * Deterministic. Does not write Draft / Article / focus keyword.
 */
final class GscPlanningSignalNormalizer
{
    public const EVIDENCE_TYPE = 'gsc_observed_query';

    /**
     * @param  list<array<string, mixed>>  $opportunities
     * @param  list<array<string, mixed>>  $cannibalization
     * @return list<array{
     *   type: string,
     *   label: string,
     *   query: string,
     *   lane: string,
     *   evidence_type: string,
     *   metrics: array<string, mixed>
     * }>
     */
    public function normalize(array $opportunities, array $cannibalization = []): array
    {
        $out = [];

        foreach ($opportunities as $opp) {
            if (! is_array($opp)) {
                continue;
            }
            $mapped = $this->mapOpportunity($opp);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        foreach ($cannibalization as $issue) {
            if (! is_array($issue)) {
                continue;
            }
            $query = trim((string) ($issue['normalized_query'] ?? $issue['query'] ?? ''));
            if ($query === '') {
                continue;
            }
            $competing = is_array($issue['competing_pages'] ?? null) ? $issue['competing_pages'] : [];
            $primaryPage = '';
            $bestImpressions = -1;
            foreach ($competing as $pageRow) {
                if (! is_array($pageRow)) {
                    continue;
                }
                $candidate = trim((string) ($pageRow['page'] ?? ''));
                $impressions = (int) ($pageRow['impressions'] ?? 0);
                if ($candidate !== '' && $impressions > $bestImpressions) {
                    $bestImpressions = $impressions;
                    $primaryPage = $candidate;
                }
            }

            $metrics = is_array($issue['evidence'] ?? null) ? $issue['evidence'] : [];
            if ($metrics === [] && is_array($issue['metadata'] ?? null)) {
                $metrics = $issue['metadata'];
            }
            if ($primaryPage !== '') {
                $metrics['primary_page'] = $primaryPage;
                $metrics['competing_pages'] = $competing;
            }

            $out[] = [
                'type' => 'possible_cannibalization',
                'label' => $query.' — possible cannibalization across pages',
                'query' => $query,
                'lane' => 'improvement_signal',
                'evidence_type' => self::EVIDENCE_TYPE,
                'metrics' => $metrics,
            ];
        }

        return $this->prioritizeAndCap($out);
    }

    /**
     * @param  array<string, mixed>  $opp
     * @return array{
     *   type: string,
     *   label: string,
     *   query: string,
     *   lane: string,
     *   evidence_type: string,
     *   metrics: array<string, mixed>
     * }|null
     */
    private function mapOpportunity(array $opp): ?array
    {
        $type = (string) ($opp['type'] ?? '');
        $query = trim((string) ($opp['normalized_query'] ?? $opp['query'] ?? ''));
        if ($query === '' || $type === '') {
            return null;
        }

        $evidence = is_array($opp['evidence'] ?? null) ? $opp['evidence'] : [];
        $hasArticle = trim((string) ($opp['article_ref'] ?? $opp['keyword_ref'] ?? '')) !== ''
            || (($opp['has_published_page'] ?? false) === true);

        return match ($type) {
            GscOpportunityType::ImpressionGrowth->value => [
                'type' => 'rising_query',
                'label' => $query.' — rising GSC impressions',
                'query' => $query,
                'lane' => $hasArticle ? 'improvement_signal' : 'new_content_signal',
                'evidence_type' => self::EVIDENCE_TYPE,
                'metrics' => $evidence,
            ],
            GscOpportunityType::ClickDecline->value,
            GscOpportunityType::PositionDecline->value,
            GscOpportunityType::ContentDecay->value => [
                'type' => match ($type) {
                    GscOpportunityType::ContentDecay->value => 'content_decay',
                    GscOpportunityType::PositionDecline->value => 'falling_query',
                    default => 'falling_query',
                },
                'label' => $query.' — '.$this->declineLabel($type, $evidence),
                'query' => $query,
                'lane' => $hasArticle ? 'improvement_signal' : 'new_content_signal',
                'evidence_type' => self::EVIDENCE_TYPE,
                'metrics' => $evidence,
            ],
            GscOpportunityType::HighImpressionLowCtr->value => [
                'type' => 'high_impression_low_ctr',
                'label' => $query.' — high GSC impressions, weak CTR',
                'query' => $query,
                'lane' => $hasArticle ? 'improvement_signal' : 'new_content_signal',
                'evidence_type' => self::EVIDENCE_TYPE,
                'metrics' => $evidence,
            ],
            GscOpportunityType::NearPageOne->value => [
                'type' => 'near_page_one',
                'label' => $query.' — near page one (GSC position)',
                'query' => $query,
                'lane' => $hasArticle ? 'improvement_signal' : 'new_content_signal',
                'evidence_type' => self::EVIDENCE_TYPE,
                'metrics' => $evidence,
            ],
            GscOpportunityType::UnmappedQuery->value,
            GscOpportunityType::NewQueryOpportunity->value => [
                'type' => 'new_content_opportunity',
                'label' => $query.' — GSC opportunity with no covered page',
                'query' => $query,
                'lane' => 'new_content_signal',
                'evidence_type' => self::EVIDENCE_TYPE,
                'metrics' => $evidence,
            ],
            GscOpportunityType::QueryCannibalization->value,
            GscOpportunityType::PageCannibalization->value => [
                'type' => 'possible_cannibalization',
                'label' => $query.' — possible cannibalization',
                'query' => $query,
                'lane' => 'improvement_signal',
                'evidence_type' => self::EVIDENCE_TYPE,
                'metrics' => $evidence,
            ],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function declineLabel(string $type, array $evidence): string
    {
        if ($type === GscOpportunityType::ContentDecay->value) {
            $pct = isset($evidence['drop_pct']) ? round(((float) $evidence['drop_pct']) * 100, 1) : null;

            return $pct !== null
                ? 'content decay (clicks down '.$pct.'%)'
                : 'content decay';
        }

        if ($type === GscOpportunityType::PositionDecline->value) {
            $prev = $evidence['previous_position'] ?? null;
            $cur = $evidence['position'] ?? $evidence['current_position'] ?? null;
            if (is_numeric($prev) && is_numeric($cur)) {
                return 'position fell '.(string) $prev.' → '.(string) $cur;
            }

            return 'position worsened';
        }

        return 'falling GSC clicks';
    }

    /**
     * @param  list<array{type: string, label: string, query: string, lane: string, evidence_type: string, metrics: array<string, mixed>}>  $signals
     * @return list<array{type: string, label: string, query: string, lane: string, evidence_type: string, metrics: array<string, mixed>}>
     */
    private function prioritizeAndCap(array $signals): array
    {
        $priority = [
            'content_decay' => 100,
            'falling_query' => 90,
            'high_impression_low_ctr' => 80,
            'possible_cannibalization' => 75,
            'near_page_one' => 70,
            'rising_query' => 60,
            'new_content_opportunity' => 50,
            'strong_query' => 40,
        ];

        usort($signals, static function (array $a, array $b) use ($priority): int {
            $pa = $priority[$a['type']] ?? 0;
            $pb = $priority[$b['type']] ?? 0;
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            $ia = (int) ($a['metrics']['impressions'] ?? 0);
            $ib = (int) ($b['metrics']['impressions'] ?? 0);

            return $ib <=> $ia;
        });

        $seen = [];
        $deduped = [];
        foreach ($signals as $signal) {
            $key = $signal['type'].'|'.$signal['query'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $signal;
            if (count($deduped) >= GscIntelligencePolicy::MAX_PLANNING_SIGNALS) {
                break;
            }
        }

        return $deduped;
    }

    /**
     * Recommend planning lane without writing Draft.
     *
     * @param  array{type: string, lane: string, query: string}  $signal
     */
    public function recommendedAction(array $signal): GscContentAction
    {
        return match ($signal['lane'] ?? '') {
            'new_content_signal' => GscContentAction::WriteNew,
            'improvement_signal' => match ($signal['type'] ?? '') {
                'content_decay' => GscContentAction::NeedsReview,
                'possible_cannibalization' => GscContentAction::Differentiate,
                default => GscContentAction::Improve,
            },
            default => GscContentAction::NeedsReview,
        };
    }
}

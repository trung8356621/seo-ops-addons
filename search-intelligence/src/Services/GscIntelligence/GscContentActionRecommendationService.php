<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscContentAction;
use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscOpportunityType;

/**
 * Recommend content action từ GSC evidence — rewrite chỉ khi có reviewed evidence flag.
 */
final class GscContentActionRecommendationService
{
    public const ALGORITHM_VERSION = '1.0.0';

    /**
     * @param  array<string, mixed>  $context
     *   article_ref?, keyword_ref?, opportunities?, cannibalization?, reviewed_evidence?, blocked?
     * @return array{action: GscContentAction, reason_codes: list<string>, article_ref: ?string}
     */
    public function recommend(array $metrics, array $context = []): array
    {
        if (($context['blocked'] ?? false) === true) {
            return $this->result(GscContentAction::Blocked, ['blocked'], $context['article_ref'] ?? null);
        }

        $articleRef = isset($context['article_ref']) ? (string) $context['article_ref'] : null;
        $reviewedEvidence = ($context['reviewed_evidence'] ?? false) === true
            || ($context['reviewed_action'] ?? '') !== '';

        $opportunities = is_array($context['opportunities'] ?? null) ? $context['opportunities'] : [];
        $cannibalization = is_array($context['cannibalization'] ?? null) ? $context['cannibalization'] : [];

        foreach ($opportunities as $opp) {
            if (! is_array($opp)) {
                continue;
            }

            $type = (string) ($opp['type'] ?? '');
            if ($type === GscOpportunityType::UnmappedQuery->value) {
                return $this->result(GscContentAction::WriteNew, ['unmapped_query', 'gsc_opportunity'], null);
            }

            if ($type === GscOpportunityType::ContentDecay->value && $articleRef !== null) {
                if ($reviewedEvidence) {
                    return $this->result(GscContentAction::Rewrite, ['content_decay', 'reviewed_evidence'], $articleRef);
                }

                return $this->result(GscContentAction::NeedsReview, ['content_decay', 'rewrite_requires_review'], $articleRef);
            }

            if ($type === GscOpportunityType::HighImpressionLowCtr->value && $articleRef !== null) {
                return $this->result(GscContentAction::Improve, ['high_impression_low_ctr'], $articleRef);
            }

            if ($type === GscOpportunityType::NearPageOne->value && $articleRef !== null) {
                return $this->result(GscContentAction::Improve, ['near_page_one'], $articleRef);
            }

            if ($type === GscOpportunityType::ImpressionGrowth->value && $articleRef === null) {
                return $this->result(GscContentAction::WriteNew, ['impression_growth_unmapped'], null);
            }
        }

        if ($cannibalization !== []) {
            return $this->result(
                GscContentAction::Differentiate,
                ['query_cannibalization_detected'],
                $articleRef,
            );
        }

        $impressions = (int) ($metrics['impressions'] ?? 0);
        if ($impressions === 0 && $articleRef === null) {
            return $this->result(GscContentAction::NeedsReview, ['no_gsc_signal'], null);
        }

        if ($articleRef !== null && $impressions > 0) {
            return $this->result(GscContentAction::Keep, ['stable_gsc_signal'], $articleRef);
        }

        return $this->result(GscContentAction::NeedsReview, ['insufficient_context'], $articleRef);
    }

    /**
     * @param  list<string>  $reasons
     * @return array{action: GscContentAction, reason_codes: list<string>, article_ref: ?string, algorithm_version: string}
     */
    private function result(GscContentAction $action, array $reasons, ?string $articleRef): array
    {
        $ref = $articleRef !== null && trim($articleRef) !== '' ? trim($articleRef) : null;

        return [
            'action' => $action,
            'reason_codes' => $reasons,
            'article_ref' => $ref,
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];
    }
}

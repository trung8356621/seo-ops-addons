<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Serp\SerpClusterEvidenceStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKeywordCluster;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordIntelligence\SeoKiKeyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoSerpClusterEvidence;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectPreviewToken;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\KeywordIntelligencePublicRef;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordManualOverrideGuard;
use RuntimeException;

/**
 * Approve/reject cluster evidence; apply intent/page_type/content_action với preview tokens.
 * source=serp_evidence — không auto overwrite manual intent.
 */
final class SerpEvidenceApplyService
{
    public function __construct(
        private readonly KeywordManualOverrideGuard $manualGuard,
        private readonly ContentProjectPreviewToken $previewToken,
    ) {}

    public function approve(SeoSerpClusterEvidence $evidence, ?int $reviewedBy = null): SeoSerpClusterEvidence
    {
        $evidence->status = SerpClusterEvidenceStatus::Approved;
        $evidence->reviewed_by = $reviewedBy;
        $evidence->reviewed_at = now();
        $evidence->save();

        return $evidence->fresh() ?? $evidence;
    }

    public function reject(SeoSerpClusterEvidence $evidence, ?int $reviewedBy = null): SeoSerpClusterEvidence
    {
        $evidence->status = SerpClusterEvidenceStatus::Rejected;
        $evidence->reviewed_by = $reviewedBy;
        $evidence->reviewed_at = now();
        $evidence->save();

        return $evidence->fresh() ?? $evidence;
    }

    /**
     * @return array<string, mixed>
     */
    public function previewApplyIntent(SeoSerpClusterEvidence $evidence, SeoKeywordCluster $cluster): array
    {
        $primary = $cluster->primaryKeyword;
        $manualBlocked = $primary instanceof SeoKiKeyword && $this->manualGuard->isManual($primary, 'search_intent');

        return [
            'evidence_ref' => $evidence->public_ref,
            'cluster_ref' => $cluster->public_ref,
            'suggested_intent' => $evidence->observed_intent,
            'current_intent' => $primary?->search_intent,
            'manual_blocked' => $manualBlocked,
            'requires_confirmation' => $manualBlocked,
            'source' => 'serp_evidence',
        ];
    }

    /**
     * @return array{applied: bool, cluster_ref: string, field: string, value: ?string, skipped_reason?: string}
     */
    public function applyIntent(
        SeoSerpClusterEvidence $evidence,
        SeoKeywordCluster $cluster,
        bool $force = false,
    ): array {
        if ($evidence->status !== SerpClusterEvidenceStatus::Approved) {
            throw new RuntimeException('serp.evidence_not_approved');
        }

        $primary = $cluster->primaryKeyword;
        if (! $primary instanceof SeoKiKeyword) {
            throw new RuntimeException('serp.cluster_missing_primary_keyword');
        }

        if ($this->manualGuard->isManual($primary, 'search_intent') && ! $force) {
            return [
                'applied' => false,
                'cluster_ref' => $cluster->public_ref,
                'field' => 'search_intent',
                'value' => null,
                'skipped_reason' => 'manual_override',
            ];
        }

        $fieldSources = (array) ($primary->field_sources ?? []);
        $primary->search_intent = $evidence->observed_intent;
        $fieldSources['search_intent'] = 'serp_evidence';
        $primary->field_sources = $fieldSources;
        $primary->save();

        $evidence->status = SerpClusterEvidenceStatus::Applied;
        $evidence->save();

        return [
            'applied' => true,
            'cluster_ref' => $cluster->public_ref,
            'field' => 'search_intent',
            'value' => $evidence->observed_intent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function previewApplyContentAction(SeoSerpClusterEvidence $evidence, SeoKeywordCluster $cluster): array
    {
        return [
            'evidence_ref' => $evidence->public_ref,
            'cluster_ref' => $cluster->public_ref,
            'suggested_action' => $evidence->recommended_action,
            'suggested_content_type' => $evidence->recommended_content_type,
            'current_content_action' => is_array($cluster->metadata) ? ($cluster->metadata['content_project_action'] ?? null) : null,
            'source' => 'serp_evidence',
        ];
    }

    /**
     * @return array{applied: bool, cluster_ref: string, content_action: ?string}
     */
    public function applyContentAction(SeoSerpClusterEvidence $evidence, SeoKeywordCluster $cluster): array
    {
        if ($evidence->status !== SerpClusterEvidenceStatus::Approved) {
            throw new RuntimeException('serp.evidence_not_approved');
        }

        $metadata = is_array($cluster->metadata) ? $cluster->metadata : [];
        $metadata['content_project_action'] = $evidence->recommended_action;
        $metadata['content_project_action_source'] = 'serp_evidence';
        $cluster->metadata = $metadata;
        $cluster->save();

        return [
            'applied' => true,
            'cluster_ref' => $cluster->public_ref,
            'content_action' => $evidence->recommended_action,
        ];
    }

    /**
     * @param  array<string, mixed>  $fingerprint
     */
    public function issueConfirmationToken(array $fingerprint): string
    {
        return $this->previewToken->issue($fingerprint);
    }

    public function resolveEvidence(string $evidenceRef): SeoSerpClusterEvidence
    {
        $id = KeywordIntelligencePublicRef::resolveSerpClusterEvidenceIdStrict($evidenceRef);
        $evidence = SeoSerpClusterEvidence::query()->find($id);

        if (! $evidence instanceof SeoSerpClusterEvidence) {
            throw new RuntimeException('SERP cluster evidence không tồn tại.');
        }

        return $evidence;
    }
}

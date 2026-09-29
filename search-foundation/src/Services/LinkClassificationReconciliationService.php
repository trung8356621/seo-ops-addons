<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapDestinationKind;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Support\LinkDestinationClassifier;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;

/**
 * Reconciles seo_link_maps link_type, destination_kind, target_site_id,
 * and is_semantic_eligible based on canonical LinkDestinationClassifier rules.
 *
 * External / needs_review / wiki_trust relationships are reclassified when
 * trusted domain definitions or target destinations change.
 * Stronger identities (ManagedCrossSite, Internal, Social, Contact) are preserved.
 */
class LinkClassificationReconciliationService
{
    private const CONNECTION = 'omi_seo_ai';

    /** @var array<int, int|null> */
    private array $siteIdCache = [];

    /**
     * Reconcile link maps in chunks.
     *
     * @param  callable(?int $total, ?int $updated, ?array $patch, ?object $row): void|null  $onProgress
     * @return array{total: int, updated: int, patches: list<array<string, mixed>>}
     */
    public function reconcile(
        int $chunkSize = 500,
        bool $dryRun = false,
        ?callable $onProgress = null,
    ): array {
        LinkDestinationClassifier::clearManagedSiteHostsCache();
        $this->siteIdCache = [];

        $chunkSize = max(1, $chunkSize);
        $updated = 0;
        $total = 0;
        $patches = [];

        if (! Schema::connection(self::CONNECTION)->hasTable('seo_link_maps')) {
            return ['total' => 0, 'updated' => 0, 'patches' => []];
        }

        DB::connection(self::CONNECTION)
            ->table('seo_link_maps')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($dryRun, $onProgress, &$updated, &$total, &$patches): void {
                $total += count($rows);

                foreach ($rows as $row) {
                    $patch = $this->computePatch($row);
                    if ($patch === null) {
                        continue;
                    }

                    if (! $dryRun) {
                        $patch['updated_at'] = now();
                        DB::connection(self::CONNECTION)
                            ->table('seo_link_maps')
                            ->where('id', $row->id)
                            ->update($patch);
                    } else {
                        $patches[] = [
                            'id' => (int) $row->id,
                            'patch' => $patch,
                        ];
                    }

                    $updated++;

                    if ($onProgress !== null) {
                        $onProgress($total, $updated, $patch, $row);
                    }
                }

                if ($onProgress !== null) {
                    $onProgress($total, $updated, null, null);
                }
            });

        return [
            'total' => $total,
            'updated' => $updated,
            'patches' => $patches,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function computePatch(object $row): ?array
    {
        $desired = $this->desiredFields($row);
        $patch = [];

        foreach ($desired as $key => $value) {
            if (! $this->sameField($key, $row->{$key} ?? null, $value)) {
                $patch[$key] = $value;
            }
        }

        return $patch === [] ? null : $patch;
    }

    /**
     * @return array{
     *     link_type: string,
     *     destination_kind: string,
     *     is_semantic_eligible: bool,
     *     target_site_id: int|null
     * }
     */
    public function desiredFields(object $row): array
    {
        $stored = SeoLinkMapType::tryFrom((string) ($row->link_type ?? '')) ?? SeoLinkMapType::External;
        $classified = $this->classifyRow($row);

        $canTransition = $stored === SeoLinkMapType::External
            || $stored === SeoLinkMapType::NeedsReview
            || $stored === SeoLinkMapType::WikiTrust;

        if ($canTransition || $classified['link_type'] === $stored) {
            return [
                'link_type' => $classified['link_type']->value,
                'destination_kind' => $classified['destination_kind']->value,
                'is_semantic_eligible' => $classified['is_semantic_eligible'],
                'target_site_id' => $classified['target_site_id'],
            ];
        }

        return $this->metadataForStoredType($stored, $row);
    }

    /**
     * @return array{
     *     link_type: SeoLinkMapType,
     *     destination_kind: SeoLinkMapDestinationKind,
     *     target_site_id: int|null,
     *     is_semantic_eligible: bool,
     *     host: string,
     *     is_cta: bool
     * }
     */
    public function classifyRow(object $row): array
    {
        $sourceArticleId = (int) ($row->source_article_id ?? 0);
        $sourceSiteId = $sourceArticleId > 0
            ? ($this->lookupArticleSiteId($sourceArticleId) ?? 0)
            : 0;

        $targetArticle = null;
        $targetArticleId = (int) ($row->target_article_id ?? 0);
        if ($targetArticleId > 0) {
            $targetArticle = $this->articleForClassification($targetArticleId);
        }

        $href = trim((string) ($row->target_external_url ?? ''));
        $host = $href !== '' ? SeoLinkMapLinkTypeClassifier::resolveHost($href) : '';
        $isTrustHost = $host !== '' && SeoLinkMapLinkTypeClassifier::isWikiTrustHost($host);

        return LinkDestinationClassifier::classify(
            $href,
            $sourceSiteId,
            $targetArticle,
            $isTrustHost,
        );
    }

    /**
     * Metadata repair for a row whose link_type is already current and disagrees
     * with a fresh URL classification. Does not change that stored type.
     *
     * @return array{
     *     link_type: string,
     *     destination_kind: string,
     *     is_semantic_eligible: bool,
     *     target_site_id: int|null
     * }
     */
    public function metadataForStoredType(SeoLinkMapType $stored, object $row): array
    {
        $targetSiteId = null;
        if ($stored === SeoLinkMapType::ManagedCrossSite) {
            $targetArticleId = (int) ($row->target_article_id ?? 0);
            $fromArticle = $targetArticleId > 0 ? $this->lookupArticleSiteId($targetArticleId) : null;
            if ($fromArticle !== null && $fromArticle > 0) {
                $targetSiteId = $fromArticle;
            } else {
                $existing = (int) ($row->target_site_id ?? 0);
                $targetSiteId = $existing > 0 ? $existing : null;
            }
        }

        return [
            'link_type' => $stored->value,
            'destination_kind' => $this->kindForType($stored)->value,
            'is_semantic_eligible' => $stored->isSemanticEligible(),
            'target_site_id' => $targetSiteId,
        ];
    }

    public function kindForType(SeoLinkMapType $type): SeoLinkMapDestinationKind
    {
        return match ($type) {
            SeoLinkMapType::Social => SeoLinkMapDestinationKind::Social,
            SeoLinkMapType::Contact => SeoLinkMapDestinationKind::Contact,
            SeoLinkMapType::WikiTrust => SeoLinkMapDestinationKind::Reference,
            SeoLinkMapType::Internal, SeoLinkMapType::ManagedCrossSite => SeoLinkMapDestinationKind::Content,
            default => SeoLinkMapDestinationKind::Other,
        };
    }

    public function sameField(string $key, mixed $current, mixed $desired): bool
    {
        if ($key === 'target_site_id') {
            return $this->normalizeSiteId($current) === $this->normalizeSiteId($desired);
        }

        if ($key === 'is_semantic_eligible') {
            return $this->normalizeBool($current) === $this->normalizeBool($desired);
        }

        return (string) ($current ?? '') === (string) ($desired ?? '');
    }

    public function normalizeSiteId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    public function normalizeBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function articleForClassification(int $articleId): ?SeoArticle
    {
        $siteId = $this->lookupArticleSiteId($articleId);
        if ($siteId === null || $siteId <= 0) {
            return null;
        }

        $article = new SeoArticle();
        $article->forceFill([
            'id' => $articleId,
            'site_id' => $siteId,
        ]);
        $article->exists = true;

        return $article;
    }

    public function lookupArticleSiteId(int $articleId): ?int
    {
        if ($articleId <= 0) {
            return null;
        }

        if (array_key_exists($articleId, $this->siteIdCache)) {
            return $this->siteIdCache[$articleId];
        }

        $record = SeoArticle::query()
            ->whereKey($articleId)
            ->value('site_id');

        $this->siteIdCache[$articleId] = $record !== null ? (int) $record : null;

        return $this->siteIdCache[$articleId];
    }
}

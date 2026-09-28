<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapDestinationKind;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Support\LinkDestinationClassifier;
use Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier;

/**
 * Idempotent reconciliation of seo_link_maps classification.
 *
 * Stale external / needs_review rows are reclassified through LinkDestinationClassifier.
 * Rows that already use a current type only have incomplete metadata repaired, and only
 * when that repair matches the canonical classifier or the stored type's own metadata.
 * A second run writes nothing when the persisted fields already match.
 */
final class BackfillLinkClassificationCommand extends Command
{
    protected $signature = 'seo:backfill-link-classification
        {--dry-run : Print changes without persisting them}
        {--chunk=500 : Number of rows to process per chunk}';

    protected $description = 'Reconcile seo_link_maps link_type, destination_kind, target_site_id, and is_semantic_eligible';

    private const CONNECTION = 'omi_seo_ai';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be persisted.');
        }

        $this->info("Reconciling seo_link_maps in chunks of {$chunkSize}…");

        $updated = 0;
        $total = 0;

        DB::connection(self::CONNECTION)
            ->table('seo_link_maps')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use ($dryRun, &$updated, &$total): void {
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
                        $this->line(sprintf(
                            '  [DRY] id=%d  %s',
                            $row->id,
                            json_encode($patch, JSON_UNESCAPED_UNICODE),
                        ));
                    }

                    $updated++;
                }

                $this->line(sprintf('  Processed %d rows so far (%d will be updated)…', $total, $updated));
            });

        $verb = $dryRun ? 'Would update' : 'Updated';
        $this->info(sprintf('%s %d / %d rows.', $verb, $updated, $total));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function computePatch(object $row): ?array
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
    private function desiredFields(object $row): array
    {
        $stored = SeoLinkMapType::tryFrom((string) ($row->link_type ?? '')) ?? SeoLinkMapType::External;
        $classified = $this->classifyRow($row);
        $stale = $stored === SeoLinkMapType::External || $stored === SeoLinkMapType::NeedsReview;

        if ($stale || $classified['link_type'] === $stored) {
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
    private function classifyRow(object $row): array
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
    private function metadataForStoredType(SeoLinkMapType $stored, object $row): array
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

    private function kindForType(SeoLinkMapType $type): SeoLinkMapDestinationKind
    {
        return match ($type) {
            SeoLinkMapType::Social => SeoLinkMapDestinationKind::Social,
            SeoLinkMapType::Contact => SeoLinkMapDestinationKind::Contact,
            SeoLinkMapType::WikiTrust => SeoLinkMapDestinationKind::Reference,
            SeoLinkMapType::Internal, SeoLinkMapType::ManagedCrossSite => SeoLinkMapDestinationKind::Content,
            default => SeoLinkMapDestinationKind::Other,
        };
    }

    private function sameField(string $key, mixed $current, mixed $desired): bool
    {
        if ($key === 'target_site_id') {
            return $this->normalizeSiteId($current) === $this->normalizeSiteId($desired);
        }

        if ($key === 'is_semantic_eligible') {
            return $this->normalizeBool($current) === $this->normalizeBool($desired);
        }

        return (string) ($current ?? '') === (string) ($desired ?? '');
    }

    private function normalizeSiteId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function normalizeBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function articleForClassification(int $articleId): ?SeoArticle
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

    /** @var array<int, int|null> */
    private array $siteIdCache = [];

    private function lookupArticleSiteId(int $articleId): ?int
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

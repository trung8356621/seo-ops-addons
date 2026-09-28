<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapDestinationKind;
use Omnichannel\Addons\Seo\Enums\SeoLinkMapType;
use Omnichannel\Addons\Seo\Support\LinkDestinationClassifier;

/**
 * Backfills destination_kind, is_semantic_eligible, and target_site_id for existing
 * seo_link_maps rows that were created before Phase 1: Link Classification SSOT.
 *
 * Processing rules (applied in order):
 *   1. link_type = external   AND host is social  → social,   destination_kind=social,    is_semantic_eligible=false
 *   2. link_type = wiki_trust                      → keep,     destination_kind=reference, is_semantic_eligible=true
 *   3. link_type = internal                        → keep,     destination_kind=content,   is_semantic_eligible=true
 *   4. link_type = external   AND target_article_id from a different site
 *                                                  → managed_cross_site, destination_kind=content, target_site_id set
 *   5. All remaining external / needs_review rows  → keep link_type, destination_kind=other, is_semantic_eligible=true
 */
final class BackfillLinkClassificationCommand extends Command
{
    protected $signature = 'seo:backfill-link-classification
        {--dry-run : Print changes without persisting them}
        {--chunk=500 : Number of rows to process per chunk}';

    protected $description = 'Backfill destination_kind, target_site_id, and is_semantic_eligible on seo_link_maps';

    private const CONNECTION = 'omi_seo_ai';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be persisted.');
        }

        $this->info("Processing seo_link_maps in chunks of {$chunkSize}…");

        $updated = 0;
        $total   = 0;

        DB::connection(self::CONNECTION)
            ->table('seo_link_maps')
            ->orderBy('id')
            ->chunk($chunkSize, function ($rows) use ($dryRun, &$updated, &$total) {
                $total += count($rows);

                foreach ($rows as $row) {
                    $patch = $this->computePatch($row);

                    if ($patch === null) {
                        continue;
                    }

                    if (! $dryRun) {
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
     * Compute the update patch for a single row, or null if no changes are needed.
     *
     * @param  object $row  A raw DB row from seo_link_maps.
     * @return array<string,mixed>|null
     */
    private function computePatch(object $row): ?array
    {
        $linkType = SeoLinkMapType::tryFrom((string) ($row->link_type ?? '')) ?? SeoLinkMapType::External;

        // --- Rule 1: external link with social host ---
        if ($linkType === SeoLinkMapType::External) {
            $url  = (string) ($row->target_external_url ?? '');
            $host = $url !== '' ? \Omnichannel\Addons\Seo\Support\SeoLinkMapLinkTypeClassifier::resolveHost($url) : '';

            if ($host !== '' && LinkDestinationClassifier::isSocialHost($host)) {
                return [
                    'link_type'            => SeoLinkMapType::Social->value,
                    'destination_kind'     => SeoLinkMapDestinationKind::Social->value,
                    'is_semantic_eligible' => false,
                    'target_site_id'       => null,
                ];
            }

            // --- Rule 4: external that actually targets a cross-site managed article ---
            if (! empty($row->target_article_id) && ! empty($row->source_article_id)) {
                $targetSiteId = $this->lookupArticleSiteId((int) $row->target_article_id);
                $sourceSiteId = $this->lookupArticleSiteId((int) $row->source_article_id);

                if ($targetSiteId !== null && $sourceSiteId !== null && $targetSiteId !== $sourceSiteId) {
                    return [
                        'link_type'            => SeoLinkMapType::ManagedCrossSite->value,
                        'destination_kind'     => SeoLinkMapDestinationKind::Content->value,
                        'is_semantic_eligible' => true,
                        'target_site_id'       => $targetSiteId,
                    ];
                }
            }

            // --- Rule 5: remaining external rows ---
            return [
                'link_type'            => SeoLinkMapType::External->value,
                'destination_kind'     => SeoLinkMapDestinationKind::Other->value,
                'is_semantic_eligible' => true,
                'target_site_id'       => null,
            ];
        }

        // --- Rule 2: wiki_trust ---
        if ($linkType === SeoLinkMapType::WikiTrust) {
            return [
                'destination_kind'     => SeoLinkMapDestinationKind::Reference->value,
                'is_semantic_eligible' => true,
                'target_site_id'       => null,
            ];
        }

        // --- Rule 3: internal ---
        if ($linkType === SeoLinkMapType::Internal) {
            return [
                'destination_kind'     => SeoLinkMapDestinationKind::Content->value,
                'is_semantic_eligible' => true,
                'target_site_id'       => null,
            ];
        }

        // Rows with already-classified new link_type values: no patch needed
        return null;
    }

    /** @var array<int,int|null> */
    private array $siteIdCache = [];

    private function lookupArticleSiteId(int $articleId): ?int
    {
        if (array_key_exists($articleId, $this->siteIdCache)) {
            return $this->siteIdCache[$articleId];
        }

        $record = \Omnichannel\Addons\Content\Models\SeoArticle::query()
            ->whereKey($articleId)
            ->value('site_id');

        $this->siteIdCache[$articleId] = $record !== null ? (int) $record : null;

        return $this->siteIdCache[$articleId];
    }
}

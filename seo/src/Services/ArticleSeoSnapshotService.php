<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services;

use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;

/**
 * Typed SEO snapshot for query/report/MCP — WP plugin keys stay in raw_meta.
 */
final class ArticleSeoSnapshotService
{
    public function __construct(
        private readonly SeoArticleProfileWriter $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $item
     */
    public function persistFromSyncItem(SeoArticle $article, array $item): void
    {
        $seo = is_array($item['seo'] ?? null) ? $item['seo'] : [];
        $robots = is_array($seo['robots'] ?? null) ? $seo['robots'] : [];
        $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];

        $focus = Keyword::preparePhraseForStorage((string) (
            $seo['focus_keyword'] ?? $scoring['focus_keyword'] ?? ''
        ));
        $canonical = trim((string) ($seo['canonical'] ?? $seo['canonical_url'] ?? ''));
        $metaDescription = trim((string) ($seo['meta_description'] ?? $scoring['meta_description'] ?? ''));
        $seoTitle = trim((string) ($seo['seo_title'] ?? $scoring['seo_title'] ?? ''));
        $schemaType = trim((string) ($seo['schema_type'] ?? ''));
        $sourcePlugin = trim((string) ($seo['plugin'] ?? ''));
        $metaHash = trim((string) ($item['seo_meta_hash'] ?? ''));
        $contentHash = trim((string) ($item['content_hash'] ?? ''));

        if ($metaHash === '') {
            $metaHash = hash('sha256', implode('|', [
                $seoTitle,
                $metaDescription,
                $focus,
                $canonical,
                ($robots['index'] ?? true) ? '1' : '0',
                ($robots['follow'] ?? true) ? '1' : '0',
                $schemaType,
                $sourcePlugin,
            ]));
        }

        $attributes = [];
        foreach ([
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'meta_description' => $metaDescription !== '' ? $metaDescription : null,
            'focus_keyword' => $focus !== '' ? $focus : null,
            'canonical_url' => $canonical !== '' ? $canonical : null,
            'is_indexable' => (bool) ($robots['index'] ?? true),
            'is_followable' => (bool) ($robots['follow'] ?? true),
            'schema_type' => $schemaType !== '' ? $schemaType : null,
            'source_plugin' => $sourcePlugin !== '' ? $sourcePlugin : null,
            'meta_hash' => $metaHash,
            'content_hash' => $contentHash !== '' ? $contentHash : null,
            'raw_meta' => $seo,
            'synced_at' => now(),
        ] as $column => $value) {
            if ($this->hasProfileColumn($column)) {
                $attributes[$column] = $value;
            }
        }

        if ($attributes === []) {
            return;
        }

        $this->writer->upsert($article, $attributes);
    }

    public function isAnalysisStale(?string $currentContentHash, ?string $analyzedContentHash): bool
    {
        $current = trim((string) $currentContentHash);
        $analyzed = trim((string) $analyzedContentHash);
        if ($current === '' || $analyzed === '') {
            return false;
        }

        return $current !== $analyzed;
    }

    private function hasProfileColumn(string $column): bool
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_article_profiles')) {
            return false;
        }

        return Schema::connection('omi_seo_ai')->hasColumn('seo_article_profiles', $column);
    }
}

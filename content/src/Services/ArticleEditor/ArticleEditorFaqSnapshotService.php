<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleFaqEditorService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;

/**
 * Canonical FAQ snapshot for Article Editor (Phase 2C).
 * Domain SoT = seo_faqs table; body only holds [omi_faq] placeholder.
 */
final class ArticleEditorFaqSnapshotService
{
    public const META_SNAPSHOT_VERSION = 'editor_faq_snapshot_version';

    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(SeoArticle $article, ?User $viewer = null): array
    {
        $items = $this->faqEditor->payloadForArticle($article);
        $normalized = [];
        foreach ($items as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized[] = [
                'id' => isset($row['id']) ? (int) $row['id'] : null,
                'question' => (string) ($row['question'] ?? ''),
                'answer' => (string) ($row['answer'] ?? ''),
                'more' => (string) ($row['more'] ?? ''),
                'position' => (int) ($row['sort_order'] ?? $index),
                'sort_order' => (int) ($row['sort_order'] ?? $index),
                'source' => 'manual',
                'status' => 'ready',
                'duplicate' => (bool) ($row['duplicate'] ?? false),
                'duplicate_scope' => $row['duplicate_scope'] ?? null,
                'updated_at' => null,
            ];
        }

        return [
            'version' => self::SCHEMA_VERSION,
            'snapshot_version' => $this->currentVersion($article),
            'article_id' => (int) $article->getKey(),
            'document_version' => max(1, (int) ($article->document_version ?? 1)),
            'generated_at' => now()->utc()->toIso8601String(),
            'items' => $normalized,
            'capabilities' => $this->capabilities($article, $viewer),
        ];
    }

    public function currentVersion(SeoArticle $article): int
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas
            ->firstWhere('meta_key', self::META_SNAPSHOT_VERSION)?->meta_value;

        return max(1, (int) $raw);
    }

    public function bumpVersion(SeoArticle $article): int
    {
        $next = $this->currentVersion($article) + 1;
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_SNAPSHOT_VERSION],
            ['meta_value' => (string) $next],
        );
        $article->unsetRelation('articleMetas');

        return $next;
    }

    /**
     * @return array<string, bool>
     */
    private function capabilities(SeoArticle $article, ?User $viewer): array
    {
        $archived = $article->relationLoaded('contentArchiveItem')
            ? $article->contentArchiveItem?->archived_at !== null
            : $article->contentArchiveItem()->exists();
        $canEdit = ! $archived && SeoAccessControl::canAccessArticle($article);
        $canGenerate = $canEdit
            && SeoAccessControl::canAccessManagerFeatures()
            && $this->workflowSettings->getRenewFaqPromptId() !== null;

        return [
            'can_edit' => $canEdit,
            'can_generate_ai' => $canGenerate,
            'can_import' => $canEdit && SeoAccessControl::canAccessManagerFeatures(),
            'viewer_id' => $viewer?->id,
        ];
    }
}

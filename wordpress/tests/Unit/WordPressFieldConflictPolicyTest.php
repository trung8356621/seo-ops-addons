<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Omnichannel\Addons\WordPress\Services\WordPressFieldConflictService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class WordPressFieldConflictPolicyTest extends TestCase
{
    public function test_existing_wp_article_slug_is_sent_as_post_name_contract(): void
    {
        $source = $this->methodSource(new ReflectionMethod(WordPressArticleSyncService::class, 'buildEditorSyncPayload'));

        self::assertStringContainsString("'slug' => (string) (\$article->slug ?? '')", $source);
        self::assertStringNotContainsString('shouldPreserveLinkedPostSlug', $source);
        self::assertStringContainsString("if (\$conflictField === 'slug')", $source);
    }

    public function test_wordpress_canonicalized_duplicate_slug_updates_laravel_with_warning_contract(): void
    {
        $source = $this->methodSource(new ReflectionMethod(WordPressArticleSyncService::class, 'completeEditorSyncResponse'));

        self::assertStringContainsString("\$article->update(['slug' => \$remoteSlug])", $source);
        self::assertStringContainsString('WordPress Ä‘Ã£ Ä‘á»•i slug thÃ nh', $source);
        self::assertStringContainsString('rememberSuccessfulSync', $source);
    }

    public function test_rewrite_article_can_sync_slug_without_special_unlock(): void
    {
        $source = $this->methodSource(new ReflectionMethod(WordPressArticleSyncService::class, 'buildEditorSyncPayload'));

        self::assertStringNotContainsString('allow_slug_update', $source);
        self::assertStringNotContainsString('MODE_REWRITE_UPDATE_EXISTING', $source);
        self::assertStringContainsString('detectConflicts', $source);
    }

    public function test_wp_post_id_alone_does_not_trigger_conflict(): void
    {
        $article = new SeoArticle(['wp_post_id' => 123, 'slug' => 'local-new']);
        $article->setRelation('articleMetas', collect());

        $conflicts = (new WordPressFieldConflictService())->detectConflicts($article, ['slug' => 'local-new']);

        self::assertSame([], $conflicts);
    }

    public function test_same_field_changed_independently_creates_conflict(): void
    {
        $article = $this->articleWithSnapshots(
            ['slug' => 'baseline'],
            ['slug' => 'wordpress-new'],
        );

        $conflicts = (new WordPressFieldConflictService())->detectConflicts($article, ['slug' => 'laravel-new']);

        self::assertArrayHasKey('slug', $conflicts);
        self::assertSame('baseline', $conflicts['slug']['baseline']);
        self::assertSame('laravel-new', $conflicts['slug']['local']);
        self::assertSame('wordpress-new', $conflicts['slug']['wordpress']);
    }

    public function test_detected_field_conflict_blocks_http_sync_contract(): void
    {
        $source = $this->methodSource(new ReflectionMethod(WordPressArticleSyncService::class, 'executeEditorSyncRequest'));

        self::assertStringContainsString('field_conflicts', $source);
        self::assertStringContainsString('wp_field_conflict', $source);
        self::assertStringContainsString('PhÃ¡t hiá»‡n xung Ä‘á»™t WordPress á»Ÿ field', $source);
    }

    public function test_different_fields_changed_on_each_side_merge_without_conflict(): void
    {
        $article = $this->articleWithSnapshots(
            ['slug' => 'baseline', 'title' => 'Old title'],
            ['slug' => 'baseline', 'title' => 'WP title'],
        );

        $conflicts = (new WordPressFieldConflictService())->detectConflicts($article, [
            'slug' => 'laravel-new',
            'title' => 'Old title',
        ]);

        self::assertSame([], $conflicts);
    }

    public function test_save_article_never_silently_restores_old_wp_slug_contract(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Http/Controllers/ArticleEditorSyncController.php',
        );

        self::assertStringContainsString('article.content.update', $source);
        self::assertStringNotContainsString('wp_slug', $source);
        self::assertStringNotContainsString('resolveStoredWordPressPermalink', $source);
    }

    public function test_laravel_managed_media_policy_allows_meta_and_attachment_slug_contract(): void
    {
        $classifier = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/mediaSourceClassification.js',
        );
        $metaUpdate = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressAttachmentMetaUpdateService.php',
        );
        $editor = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/components/SeoArticleEditor.jsx',
        );

        self::assertStringContainsString('isLaravelManagedMedia', $classifier);
        self::assertStringContainsString('pendingBinaryVersion', $classifier);
        self::assertStringContainsString('local_media', $classifier);
        self::assertStringContainsString('alt_text', $metaUpdate);
        self::assertStringContainsString('title', $metaUpdate);
        self::assertStringContainsString('new_slug: trimmed', $editor);
    }

    public function test_laravel_managed_media_with_pending_binary_can_replace_wp_image_contract(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressLocalMediaSyncService.php',
        );

        self::assertStringContainsString('replace-binary', $source);
        self::assertStringContainsString('whereNotNull(\'wp_attachment_id\')', $source);
        self::assertStringContainsString('orWhereColumnAfterMeta(\'updated_at\', \'>\', \'wp_synced_at\')', $source);
    }

    public function test_wp_only_image_remains_protected_from_automatic_binary_replacement(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/wordpress/src/Services/WordPressLocalMediaSyncService.php',
        );
        $classifier = (string) file_get_contents(
            ProjectRoot::addonsPath().'/media/resources/js/utils/mediaSourceClassification.js',
        );

        self::assertStringContainsString('SeoMedia::query()', $source);
        self::assertStringContainsString('where(\'article_id\', $articleId)', $source);
        self::assertStringContainsString("classifyMediaSource(row) === 'wordpress' && !isLaravelManagedMedia(row)", $classifier);
    }

    private function articleWithSnapshots(array $baseline, array $latestWp): SeoArticle
    {
        $article = new SeoArticle(['wp_post_id' => 123]);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => WordPressFieldConflictService::META_LAST_SYNCED_FIELD_SNAPSHOT,
                'meta_value' => json_encode($baseline, JSON_THROW_ON_ERROR),
            ]),
            new ArticleMeta([
                'meta_key' => WordPressFieldConflictService::META_LATEST_FIELD_SNAPSHOT,
                'meta_value' => json_encode($latestWp, JSON_THROW_ON_ERROR),
            ]),
        ]));

        return $article;
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            max(0, $method->getStartLine() - 1),
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}

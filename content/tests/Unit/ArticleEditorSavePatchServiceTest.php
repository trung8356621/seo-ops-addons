<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorSavePatchService;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArticleEditorSavePatchServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_patch_contains_article_metadata_and_flags(): void
    {
        $article = SeoArticle::query()->create([
            'site_id' => 1,
            'user_id' => 1,
            'title' => 'Patch title',
            'slug' => 'patch-title',
            'status' => 'draft',
            'seo_score' => 72.5,
            'type' => 'article',
        ]);

        $context = new ArticleEditorSaveContext(
            title: 'Patch title',
            slug: 'patch-title',
            postType: 'article',
            status: 'draft',
            visibility: 'public',
            publishDay: '08',
            publishMonth: '07',
            publishYear: '2026',
            publishHour: '09',
            publishMinute: '30',
            seoMetaDescription: 'Meta',
            focusKeyword: 'keyword',
        );

        $patch = app(ArticleEditorSavePatchService::class)->build(
            $article,
            $context,
            ['score' => 72.5],
        );

        $this->assertSame('Patch title', $patch['article']['title']);
        $this->assertSame('draft', $patch['article']['status']);
        $this->assertSame(72.5, $patch['article']['seo_score']);
        $this->assertArrayHasKey('updated_at', $patch['article']);
        $this->assertArrayHasKey('local_edit_pending', $patch['flags']);
        $this->assertStringContainsString('Đã lưu lúc', (string) $patch['publish_box']['saved_at_label']);
    }
}

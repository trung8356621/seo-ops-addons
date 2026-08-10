<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleRevision;
use Omnichannel\Addons\Content\Services\SeoArticleRevisionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class SeoArticleRevisionRestoreServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_restore_revision_to_article_updates_title_body_and_seo_meta(): void
    {
        $article = SeoArticle::query()->create([
            'site_id' => 1,
            'title' => 'Current title',
            'body' => '<p>Current body</p>',
            'status' => 'draft',
            'type' => 'article',
        ]);

        $revision = SeoArticleRevision::query()->create([
            'article_id' => (int) $article->id,
            'user_id' => 1,
            'title' => 'Old title',
            'content' => '<p>Old body</p>',
            'seo_meta' => [
                'seo_title' => 'Old SEO title',
                'meta_description' => 'Old meta description',
                'focus_keyword' => 'old keyword',
                'seo_score' => 72.5,
            ],
        ]);

        $service = app(SeoArticleRevisionService::class);
        $restored = $service->restoreRevisionToArticle($article, $revision);

        $this->assertSame('Old title', $restored->title);
        $this->assertSame('<p>Old body</p>', $restored->body);
        $this->assertSame(72.5, (float) $restored->seo_score);

        $restored->load('articleMetas');
        $this->assertNull($restored->articleMetas->firstWhere('meta_key', 'seo_title'));
        $this->assertSame(
            'Old meta description',
            (string) ($restored->articleMetas->firstWhere('meta_key', 'seo_meta_description')?->meta_value ?? ''),
        );
    }
}

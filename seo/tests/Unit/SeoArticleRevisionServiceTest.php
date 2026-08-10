<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleRevision;
use Omnichannel\Addons\Content\Services\SeoArticleRevisionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class SeoArticleRevisionServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_capture_after_save_prunes_old_revisions(): void
    {
        $article = SeoArticle::query()->create([
            'site_id' => 1,
            'title' => 'Revision test',
            'body' => '<p>Initial</p>',
            'status' => 'draft',
            'type' => 'article',
        ]);

        $service = app(SeoArticleRevisionService::class);

        for ($index = 1; $index <= 16; $index++) {
            $service->captureAfterSave(
                $article,
                'Title '.$index,
                '<p>Body '.$index.'</p>',
                ['seo_title' => 'SEO '.$index],
                1,
            );
        }

        $this->assertSame(15, $service->countForArticle((int) $article->id));

        $latest = SeoArticleRevision::query()
            ->where('article_id', $article->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($latest);
        $this->assertSame('Title 16', $latest->title);
        $this->assertSame('<p>Body 16</p>', $latest->content);
    }

    public function test_clear_all_for_article_removes_all_revisions(): void
    {
        $article = SeoArticle::query()->create([
            'site_id' => 1,
            'title' => 'Clear revisions test',
            'body' => '<p>Initial</p>',
            'status' => 'draft',
            'type' => 'article',
        ]);

        $service = app(SeoArticleRevisionService::class);

        for ($index = 1; $index <= 3; $index++) {
            $service->captureAfterSave(
                $article,
                'Title '.$index,
                '<p>Body '.$index.'</p>',
                ['seo_title' => 'SEO '.$index],
                1,
            );
        }

        $this->assertSame(3, $service->countForArticle((int) $article->id));

        $deleted = $service->clearAllForArticle((int) $article->id);

        $this->assertSame(3, $deleted);
        $this->assertSame(0, $service->countForArticle((int) $article->id));
    }
}

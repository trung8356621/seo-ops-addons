<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Tests\Unit;

use Omnichannel\Addons\Social\Models\SeoArticleSocialLink;
use Omnichannel\Addons\Social\Services\ArticleSocialLinkService;
use Omnichannel\Addons\Social\Services\SocialSupportedDomainService;
use Omnichannel\Addons\Social\Services\SocialUrlNormalizer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ArticleSocialLinkServiceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private ?ArticleSocialLinkService $service = null;

    protected function setUp(): void
    {
        parent::setUp();

        $domainService = SocialSupportedDomainService::withSupportedDomains([
            'facebook.com',
            'linkedin.com',
            'x.com',
        ]);

        $this->service = new ArticleSocialLinkService(
            $domainService,
            new SocialUrlNormalizer($domainService),
        );
    }

    private function requireArticleSocialLinksTable(): void
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_article_social_links')) {
            $this->markTestSkipped('seo_article_social_links table is not available.');
        }
    }

    public function test_build_save_notification_reports_partial_success(): void
    {
        $notification = $this->service->buildSaveNotification([
            'saved' => 6,
            'duplicate' => 1,
            'unsupported' => 2,
            'invalid' => 1,
        ]);

        self::assertSame('success', $notification['level']);
        self::assertStringContainsString('6', $notification['title']);
    }

    public function test_mixed_batch_partially_succeeds_without_rollback(): void
    {
        $this->requireArticleSocialLinksTable();

        $articleId = $this->resolveExistingArticleId();
        if ($articleId <= 0) {
            $this->markTestSkipped('No article available for ArticleSocialLinkService DB test.');
        }

        $raw = implode("\n", [
            'https://www.facebook.com/post/1',
            'https://linkedin.com/feed/update/1',
            'https://evilfacebook.com/post/2',
            'not-a-url',
            'https://www.facebook.com/post/1',
            'https://x.com/user/status/1',
        ]);

        $result = $this->service->savePastedLines($articleId, $raw, 99);

        self::assertSame(3, $result['saved']);
        self::assertSame(1, $result['duplicate']);
        self::assertSame(1, $result['unsupported']);
        self::assertSame(1, $result['invalid']);
        self::assertSame(3, SeoArticleSocialLink::query()->where('article_id', $articleId)->count());
    }

    private function resolveExistingArticleId(): int
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('articles')) {
            return 0;
        }

        $fromLinks = (int) (SeoArticleSocialLink::query()->max('article_id') ?? 0);
        if ($fromLinks > 0) {
            return $fromLinks;
        }

        $fromArticles = \Omnichannel\Addons\Content\Models\SeoArticle::query()->orderByDesc('id')->value('id');

        return (int) ($fromArticles ?? 0);
    }
}

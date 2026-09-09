<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectBindArticleAuthority;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Bind after external create: site authority = task.site_id, never project.site_id override.
 */
final class ContentProjectBindArticleSiteAuthorityTest extends TestCase
{
    private const SITE_A = 10;

    private const SITE_B = 20;

    private const ARTICLE_LOCAL = 13628;

    public function test_a1_project_site_a_task_site_b_article_site_b_passes(): void
    {
        $siteId = ContentProjectBindArticleAuthority::resolveSiteId(self::SITE_B, self::SITE_A);
        self::assertSame(self::SITE_B, $siteId);

        $gate = ContentProjectBindArticleAuthority::evaluateLocalCandidate(
            self::ARTICLE_LOCAL,
            $siteId,
            self::SITE_B,
        );
        self::assertTrue($gate['ok']);
        self::assertSame(self::ARTICLE_LOCAL, $gate['article_id']);
    }

    public function test_a2_article_on_project_site_not_task_site_fails_mismatch(): void
    {
        $siteId = ContentProjectBindArticleAuthority::resolveSiteId(self::SITE_B, self::SITE_A);
        $gate = ContentProjectBindArticleAuthority::evaluateLocalCandidate(
            self::ARTICLE_LOCAL,
            $siteId,
            self::SITE_A,
        );

        self::assertFalse($gate['ok']);
        self::assertSame(ContentProjectErrorCode::ArticleSiteMismatch->value, $gate['error_code']);
        self::assertStringContainsString('article_site_mismatch', (string) $gate['message']);
        self::assertSame(self::ARTICLE_LOCAL, $gate['debug']['article_id'] ?? null);
        self::assertSame(self::SITE_A, $gate['debug']['article_site_id'] ?? null);
        self::assertSame(self::SITE_B, $gate['debug']['task_site_id'] ?? null);
    }

    public function test_a3_non_local_candidate_fails_missing(): void
    {
        $gate = ContentProjectBindArticleAuthority::evaluateLocalCandidate(
            999999,
            self::SITE_B,
            null,
        );

        self::assertFalse($gate['ok']);
        self::assertSame(ContentProjectErrorCode::ArticleRelationMissing->value, $gate['error_code']);
        self::assertStringContainsString('không phải local articles.id', (string) $gate['message']);
        self::assertStringNotContainsString('article_site_mismatch', (string) $gate['message']);
    }

    public function test_a4_null_project_site_task_site_b_passes(): void
    {
        $siteId = ContentProjectBindArticleAuthority::resolveSiteId(self::SITE_B, 0);
        self::assertSame(self::SITE_B, $siteId);

        $gate = ContentProjectBindArticleAuthority::evaluateLocalCandidate(
            self::ARTICLE_LOCAL,
            $siteId,
            self::SITE_B,
        );
        self::assertTrue($gate['ok']);
    }

    public function test_a5_two_tasks_same_project_bind_independently(): void
    {
        $task1Site = ContentProjectBindArticleAuthority::resolveSiteId(self::SITE_A, self::SITE_A);
        $task2Site = ContentProjectBindArticleAuthority::resolveSiteId(self::SITE_B, self::SITE_A);

        $a = ContentProjectBindArticleAuthority::evaluateLocalCandidate(201, $task1Site, self::SITE_A);
        $b = ContentProjectBindArticleAuthority::evaluateLocalCandidate(202, $task2Site, self::SITE_B);

        self::assertTrue($a['ok']);
        self::assertTrue($b['ok']);
        self::assertSame(201, $a['article_id']);
        self::assertSame(202, $b['article_id']);
    }

    public function test_bind_service_prefers_task_site_over_project_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectRunItemService::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectBindArticleAuthority::resolveSiteId', $src);
        self::assertStringContainsString('$taskSiteId = (int) ($task->site_id ?? 0)', $src);
        self::assertStringContainsString('evaluateLocalCandidate', $src);

        $authority = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectBindArticleAuthority::class))->getFileName(),
        );
        self::assertStringContainsString('ArticleSiteMismatch', $authority);
        self::assertStringContainsString('article_site_mismatch', $authority);

        // Must not prefer project.site_id before task.site_id.
        $projectFirst = '$siteId = (int) ($task->project?->site_id ?? 0)';
        self::assertStringNotContainsString($projectFirst, $src);
    }

    public function test_project_site_is_legacy_fallback_only_when_task_site_missing(): void
    {
        self::assertSame(self::SITE_A, ContentProjectBindArticleAuthority::resolveSiteId(0, self::SITE_A));
        self::assertSame(0, ContentProjectBindArticleAuthority::resolveSiteId(0, 0));
        self::assertSame(self::SITE_B, ContentProjectBindArticleAuthority::resolveSiteId(self::SITE_B, self::SITE_A));
    }
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditExistingContentSuggestionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\SeoAudit\SeoAuditSuggestionFilterSet;
use Omnichannel\Addons\Seo\Support\SeoAnalyticsArticleScope;
use ReflectionClass;
use Tests\TestCase;

final class SeoAnalyticsPageExclusionContractTest extends TestCase
{
    public function test_seo_audit_default_exclusion_uses_analytics_scope(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAuditExistingContentSuggestionService::class))->getFileName(),
        );
        $this->assertStringContainsString('SeoAnalyticsArticleScope', $src);
        $this->assertStringContainsString('applyToArticleQuery', $src);
        $this->assertStringContainsString('POST_TYPE_MODE_SPECIFIC', $src);
        $this->assertStringContainsString('POST_TYPE_MODE_ALL', $src);
        $this->assertStringContainsString('ArticleResource::applyPostTypeFilterScope', $src);
        $this->assertStringNotContainsString('// Default: all except page', $src);
        $this->assertSame('all_except_page', SeoAuditSuggestionFilterSet::POST_TYPE_MODE_ALL_EXCEPT_PAGE);
    }

    public function test_analytics_scope_helper_exists_for_task_article_id(): void
    {
        $this->assertTrue(method_exists(SeoAnalyticsArticleScope::class, 'applyToTaskArticleId'));
        $this->assertTrue(method_exists(SeoAnalyticsArticleScope::class, 'applyToArticleQuery'));
    }
}

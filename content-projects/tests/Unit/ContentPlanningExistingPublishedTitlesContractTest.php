<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceCaps;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\NewContent\ContentPlanningIntelligenceService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * existingPublishedTitles() contract — Generate coverage evidence.
 */
final class ContentPlanningExistingPublishedTitlesContractTest extends TestCase
{
    public function test_method_exists_private_and_site_scoped_publish_only(): void
    {
        $method = new ReflectionMethod(ContentPlanningIntelligenceService::class, 'existingPublishedTitles');
        self::assertTrue($method->isPrivate());
        self::assertSame(1, $method->getNumberOfParameters());

        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentPlanningIntelligenceService::class))->getFileName(),
        );

        self::assertStringContainsString('private function existingPublishedTitles(int $siteId): array', $src);
        self::assertStringContainsString("SeoArticle::query()", $src);
        self::assertStringContainsString("->where('site_id', \$siteId)", $src);
        self::assertStringContainsString("->whereHas('wordpressLink'", $src);
        self::assertStringContainsString("observed_post_status', 'publish'", $src);
        self::assertStringContainsString("'coverage' => 'covered'", $src);
        self::assertStringContainsString('ContentPlanningIntelligenceCaps::EXISTING_TOPICS', $src);
        self::assertStringContainsString('catch (Throwable)', $src);
        self::assertStringNotContainsString('wp_post_type', $src);
        self::assertStringNotContainsString('ContentType::', $src);
        self::assertSame(100, ContentPlanningIntelligenceCaps::EXISTING_TOPICS);
    }

    public function test_build_calls_existing_published_titles_when_site_context_enabled(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentPlanningIntelligenceService::class))->getFileName(),
        );

        self::assertStringContainsString("\$existingTopics = \$this->existingPublishedTitles(\$siteId);", $src);

        $publicMethods = get_class_methods(ContentPlanningIntelligenceService::class);
        foreach (['principalKeywords', 'annotateCoverage', 'clusterSummaries', 'plannedTopics', 'rejectedTopics', 'mcpSignals', 'gscSignals'] as $name) {
            self::assertTrue(
                (new ReflectionClass(ContentPlanningIntelligenceService::class))->hasMethod($name),
                "Expected method {$name} to exist",
            );
        }
        unset($publicMethods);
    }
}

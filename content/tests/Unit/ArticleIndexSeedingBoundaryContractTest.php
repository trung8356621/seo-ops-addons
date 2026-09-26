<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleManualIndexMarkerService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Index mark must not depend on Seeding. Handoff is Core event only.
 */
final class ArticleIndexSeedingBoundaryContractTest extends TestCase
{
    public function test_content_index_marker_has_no_seeding_imports(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ArticleManualIndexMarkerService::class))->getFileName()
        );

        self::assertStringContainsString('ArticleIndexStatusChanged', $source);
        self::assertStringContainsString('EventBus', $source);
        self::assertStringContainsString('catch (Throwable)', $source);
        self::assertStringNotContainsString('WebsiteShare', $source);
        self::assertStringNotContainsString('Omnichannel\\Addons\\Seeding', $source);
        self::assertStringNotContainsString('SeedingSocialAccount', $source);
        self::assertStringNotContainsString('seeding_social', $source);
        self::assertStringNotContainsString('class_exists(\\Omnichannel\\Addons\\Seeding', $source);
    }

    public function test_seeding_listener_is_fail_safe_and_optional(): void
    {
        $listenerPath = dirname(__DIR__, 3).'/seeding/src/Listeners/ArticleIndexStatusChangedListener.php';
        self::assertFileExists($listenerPath);
        $listener = (string) file_get_contents($listenerPath);

        self::assertStringContainsString('WebsiteShareJobService', $listener);
        self::assertStringContainsString('catch (Throwable)', $listener);
        self::assertStringContainsString('Never break Content index mark path', $listener);

        $provider = (string) file_get_contents(
            dirname(__DIR__, 3).'/seeding/src/SeedingServiceProvider.php'
        );
        self::assertStringContainsString('ArticleIndexStatusChanged', $provider);
        self::assertStringContainsString('ArticleIndexStatusChangedListener', $provider);
    }

    public function test_website_share_preserves_indexed_true_false_semantics(): void
    {
        $service = (string) file_get_contents(
            dirname(__DIR__, 3).'/seeding/src/Services/WebsiteShareJobService.php'
        );
        self::assertStringContainsString('handleIndexStatusChanged', $service);
        self::assertStringContainsString('CheckArticleForWebsiteShareJob', $service);
        self::assertStringContainsString('if (! $event->indexed)', $service);
        self::assertStringContainsString('cancelScheduledForArticle', $service);
    }
}

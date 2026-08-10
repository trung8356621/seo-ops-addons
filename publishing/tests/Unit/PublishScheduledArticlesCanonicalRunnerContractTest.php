<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Console\PublishScheduledArticlesCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\Publishing\Services\ScheduledArticlePublishRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract: seo:publish-scheduled-articles is thin shell over ScheduledArticlePublishRunner
 * which owns CP queue runner (no direct WordPress publish in command).
 */
final class PublishScheduledArticlesCanonicalRunnerContractTest extends TestCase
{
    public function test_command_delegates_only_to_scheduled_runner(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(PublishScheduledArticlesCommand::class))->getFileName(),
        );

        self::assertStringContainsString('ScheduledArticlePublishRunner', $source);
        self::assertStringNotContainsString('WordPressPublisher', $source);
        self::assertStringNotContainsString('publishForArticle', $source);
        self::assertStringNotContainsString('ContentProjectPublishingQueueRunner', $source);
    }

    public function test_runner_uses_content_project_queue_runner(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ScheduledArticlePublishRunner::class))->getFileName(),
        );

        self::assertStringContainsString(ContentProjectPublishingQueueRunner::class, $source);
        self::assertStringContainsString('dispatchDue', $source);
        self::assertStringNotContainsString('queue:work', $source);
        self::assertStringNotContainsString('forceRelease', $source);
    }
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Console\PublishScheduledArticlesCommand;
use App\Addons\SeoContentAi\SeoContentAiServiceProvider;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBusRegistrar;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueRunner;
use Omnichannel\Addons\Publishing\Services\ScheduledArticlePublishRunner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Freeze: one Laravel schedule publish dispatcher → ContentProjectPublishingQueueRunner.
 * No second scheduled publish runner. No direct WP mutate from scheduler path.
 */
final class PublishingSchedulerArchitectureFreezeTest extends TestCase
{
    public function test_single_publish_schedule_name_and_no_early_return_skip(): void
    {
        $provider = (string) file_get_contents(
            (new ReflectionClass(SeoContentAiServiceProvider::class))->getFileName(),
        );

        self::assertSame(
            1,
            substr_count($provider, "seo-content-ai:publish-scheduled-articles"),
        );
        self::assertStringContainsString('PublishScheduledArticlesCommand::class', $provider);
        self::assertStringNotContainsString(
            "if (\$alreadyRegistered) {\n                return;",
            $provider,
        );
        self::assertDoesNotMatchRegularExpression(
            '/->command\(\s*ContentProjectPublishingQueueRunner::class/',
            $provider,
        );
    }

    public function test_command_delegates_to_scheduled_runner_not_wordpress(): void
    {
        $command = (string) file_get_contents(
            (new ReflectionClass(PublishScheduledArticlesCommand::class))->getFileName(),
        );
        $runner = (string) file_get_contents(
            (new ReflectionClass(ScheduledArticlePublishRunner::class))->getFileName(),
        );
        $queueRunner = (string) file_get_contents(
            (new ReflectionClass(ContentProjectPublishingQueueRunner::class))->getFileName(),
        );

        self::assertStringContainsString('ScheduledArticlePublishRunner', $command);
        self::assertStringContainsString('contentProjectQueue->dispatchDue', $runner);
        self::assertStringContainsString('ProcessScheduledProjectItemPublishCommand', $queueRunner);
        self::assertStringNotContainsString('publishScheduledArticle', $runner);
        self::assertStringNotContainsString('WordPressPublisher', $runner);
        self::assertStringNotContainsString('WordPressPublisher', $command);
    }

    public function test_command_bus_registrar_skips_broken_handlers(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectCommandBusRegistrar::class))->getFileName(),
        );

        self::assertStringContainsString('content_project_command_bus_handler_skipped', $source);
        self::assertStringContainsString('catch (Throwable', $source);
    }

    public function test_agent_optional_commands_guarded_by_class_exists(): void
    {
        $provider = (string) file_get_contents(
            (new ReflectionClass(SeoContentAiServiceProvider::class))->getFileName(),
        );

        self::assertStringContainsString(
            'DispatchDueAgentAutomationsCommand::class)',
            $provider,
        );
        self::assertStringContainsString('class_exists($optionalCommand)', $provider);
    }
}

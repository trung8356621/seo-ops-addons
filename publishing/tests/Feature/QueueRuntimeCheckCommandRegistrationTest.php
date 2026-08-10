<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Feature;

use Omnichannel\Addons\Publishing\Console\QueueRuntimeCheckCommand;
use App\Addons\SeoContentAi\SeoContentAiServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Boots Laravel app and asserts console registration for seo:queue-runtime-check.
 */
final class QueueRuntimeCheckCommandRegistrationTest extends TestCase
{
    public function test_queue_runtime_check_command_is_registered_with_artisan(): void
    {
        if (! $this->app->providerIsLoaded(SeoContentAiServiceProvider::class)) {
            $this->app->register(SeoContentAiServiceProvider::class);
        }

        $commands = Artisan::all();

        $this->assertArrayHasKey(
            'seo:queue-runtime-check',
            $commands,
            'SeoContentAiServiceProvider must register seo:queue-runtime-check in runningInConsole().',
        );

        $this->assertInstanceOf(
            QueueRuntimeCheckCommand::class,
            $commands['seo:queue-runtime-check'],
        );

        $this->assertSame(
            'seo:queue-runtime-check',
            $commands['seo:queue-runtime-check']->getName(),
        );
    }

    public function test_provider_source_lists_queue_runtime_check_command(): void
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(SeoContentAiServiceProvider::class))->getFileName(),
        );

        $this->assertStringContainsString(
            'QueueRuntimeCheckCommand::class',
            $source,
        );
        $this->assertStringContainsString(
            "seo:queue-runtime-check",
            (string) file_get_contents(
                (string) (new \ReflectionClass(QueueRuntimeCheckCommand::class))->getFileName(),
            ),
        );
    }
}

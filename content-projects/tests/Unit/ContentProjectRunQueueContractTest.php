<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Jobs\RunContentProjectArticleJob;
use Omnichannel\Addons\ContentProjects\Support\Queue\ContentProjectQueueRuntimeSafety;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Source contracts for Content Project generation queue + job timing.
 */
final class ContentProjectRunQueueContractTest extends TestCase
{
    public function test_queue_name_defaults_to_seo_content_run_without_config(): void
    {
        self::assertSame(
            ContentProjectQueueRuntimeSafety::DEFAULT_RUN_QUEUE,
            ContentProjectRunEngineFeature::queueName(),
        );
        self::assertSame('seo-content-run', ContentProjectRunEngineFeature::queueName());
    }

    public function test_config_key_and_env_documented_in_config_file(): void
    {
        $configPath = dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'seo-content-ai.php';
        self::assertFileExists($configPath);

        $source = (string) file_get_contents($configPath);
        self::assertStringContainsString("'run_queue'", $source);
        self::assertStringContainsString("env('CONTENT_PROJECT_RUN_QUEUE', 'seo-content-run')", $source);
    }

    public function test_job_timeout_tries_unique_for_contract(): void
    {
        $job = new RunContentProjectArticleJob(1, 2, 3, 1, 'token');

        self::assertSame(ContentProjectQueueRuntimeSafety::EXPECTED_JOB_TIMEOUT, $job->timeout);
        self::assertSame(ContentProjectQueueRuntimeSafety::EXPECTED_JOB_TRIES, $job->tries);
        self::assertSame(ContentProjectQueueRuntimeSafety::EXPECTED_JOB_UNIQUE_FOR, $job->uniqueFor);
        self::assertSame('content-project-run-article:1:3:1', $job->uniqueId());
        self::assertTrue(is_a(RunContentProjectArticleJob::class, ShouldBeUnique::class, true));

        $ref = new ReflectionClass(RunContentProjectArticleJob::class);
        self::assertFalse($ref->hasProperty('backoff'), 'Job must not declare $backoff unless contract changes');
    }

    public function test_job_constructor_sets_queue_from_feature(): void
    {
        $job = new RunContentProjectArticleJob(10, 20, 30, 2, 'token-abc');
        self::assertSame(ContentProjectRunEngineFeature::queueName(), $job->queue);
    }

    public function test_database_queue_retry_after_fallback_is_at_least_production_target(): void
    {
        $queueConfigPath = dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'queue.php';
        self::assertFileExists($queueConfigPath);
        $source = (string) file_get_contents($queueConfigPath);
        self::assertStringContainsString("env('DB_QUEUE_RETRY_AFTER', 1200)", $source);
        self::assertStringNotContainsString("env('DB_QUEUE_RETRY_AFTER', 90)", $source);
    }

    public function test_env_example_documents_retry_after_1200(): void
    {
        $envExample = dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'.env.example';
        self::assertFileExists($envExample);
        $source = (string) file_get_contents($envExample);
        self::assertMatchesRegularExpression('/^DB_QUEUE_RETRY_AFTER=1200/m', $source);
    }

    public function test_queue_runtime_check_command_is_read_only(): void
    {
        $path = (string) (new ReflectionClass(
            \Omnichannel\Addons\Publishing\Console\QueueRuntimeCheckCommand::class,
        ))->getFileName();
        $source = (string) file_get_contents($path);

        self::assertStringContainsString("seo:queue-runtime-check", $source);
        self::assertStringNotContainsString('DB::table', $source);
        self::assertStringNotContainsString("->table('jobs')", $source);
        self::assertStringNotContainsString('dispatch(', $source);
        self::assertStringNotContainsString('Cache::', $source);
    }

    public function test_reflection_defaults_match_expected_constants(): void
    {
        $ref = new ReflectionClass(RunContentProjectArticleJob::class);
        self::assertSame(
            ContentProjectQueueRuntimeSafety::EXPECTED_JOB_TIMEOUT,
            (int) $ref->getProperty('timeout')->getDefaultValue(),
        );
        self::assertSame(
            ContentProjectQueueRuntimeSafety::EXPECTED_JOB_TRIES,
            (int) $ref->getProperty('tries')->getDefaultValue(),
        );
        self::assertSame(
            ContentProjectQueueRuntimeSafety::EXPECTED_JOB_UNIQUE_FOR,
            (int) $ref->getProperty('uniqueFor')->getDefaultValue(),
        );
        unset($ref);
        self::assertTrue((new ReflectionProperty(RunContentProjectArticleJob::class, 'timeout'))->isPublic());
    }
}

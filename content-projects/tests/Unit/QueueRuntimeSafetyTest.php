<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\Queue\ContentProjectQueueRuntimeSafety;
use PHPUnit\Framework\TestCase;

/**
 * Pure safety-matrix for retry_after vs RunContentProjectArticleJob timeout.
 */
final class QueueRuntimeSafetyTest extends TestCase
{
    public function test_safe_when_retry_after_greater_than_timeout(): void
    {
        $result = ContentProjectQueueRuntimeSafety::evaluate([
            'queue_connection' => 'database',
            'retry_after' => 1200,
            'run_queue' => 'seo-content-run',
            'job_timeout' => 900,
            'job_tries' => 1,
            'job_unique_for' => 900,
            'pcntl' => true,
        ]);

        self::assertTrue($result['safe']);
        self::assertSame([], $result['failures']);
        self::assertContains('PASS retry_after is greater than job timeout', $result['lines']);
        self::assertSame(0, $result['safe'] ? 0 : 1);
    }

    public function test_unsafe_when_retry_after_equals_timeout(): void
    {
        $result = ContentProjectQueueRuntimeSafety::evaluate([
            'queue_connection' => 'database',
            'retry_after' => 900,
            'run_queue' => 'seo-content-run',
            'job_timeout' => 900,
            'job_tries' => 1,
            'job_unique_for' => 900,
            'pcntl' => true,
        ]);

        self::assertFalse($result['safe']);
        self::assertNotEmpty($result['failures']);
        self::assertStringContainsString('retry_after (900) must be greater than job timeout (900)', $result['failures'][0]);
        self::assertSame(1, $result['safe'] ? 0 : 1);
    }

    public function test_unsafe_when_retry_after_smaller_than_timeout(): void
    {
        $result = ContentProjectQueueRuntimeSafety::evaluate([
            'queue_connection' => 'database',
            'retry_after' => 90,
            'run_queue' => 'seo-content-run',
            'job_timeout' => 900,
            'job_tries' => 1,
            'job_unique_for' => 900,
            'pcntl' => false,
        ]);

        self::assertFalse($result['safe']);
        self::assertStringContainsString('retry_after (90) must be greater than job timeout (900)', $result['failures'][0]);
        self::assertContains('FAIL unsafe queue runtime configuration', $result['lines']);
        self::assertSame(1, $result['safe'] ? 0 : 1);
    }

    public function test_unsafe_when_run_queue_empty(): void
    {
        $result = ContentProjectQueueRuntimeSafety::evaluate([
            'queue_connection' => 'database',
            'retry_after' => 1200,
            'run_queue' => '',
            'job_timeout' => 900,
            'job_tries' => 1,
            'job_unique_for' => 900,
        ]);

        self::assertFalse($result['safe']);
        self::assertContains('Content Project run queue is empty', $result['failures']);
    }

    public function test_unsafe_when_retry_after_null(): void
    {
        $result = ContentProjectQueueRuntimeSafety::evaluate([
            'queue_connection' => 'database',
            'retry_after' => null,
            'run_queue' => 'seo-content-run',
            'job_timeout' => 900,
            'job_tries' => 1,
            'job_unique_for' => 900,
        ]);

        self::assertFalse($result['safe']);
        self::assertContains('retry_after is null or invalid', $result['failures']);
    }

    public function test_output_notes_aapanel_cannot_be_verified_from_source(): void
    {
        $result = ContentProjectQueueRuntimeSafety::evaluate([
            'queue_connection' => 'database',
            'retry_after' => 1200,
            'run_queue' => 'seo-content-run',
            'job_timeout' => 900,
            'job_tries' => 1,
            'job_unique_for' => 900,
        ]);

        self::assertContains('aaPanel queue coverage cannot be verified from Laravel source.', $result['lines']);
        self::assertContains('Expected dedicated worker queue: seo-content-run', $result['lines']);
    }
}

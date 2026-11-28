<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Omnichannel\Addons\ContentProjects\Jobs\GenerateNewContentSuggestionsJob;
use Tests\TestCase;

final class GenerateNewContentSuggestionsJobContinuationTest extends TestCase
{
    public function test_job_uses_unique_until_processing_and_without_overlapping_middleware(): void
    {
        $jobA = new GenerateNewContentSuggestionsJob(52, 81, 2);
        $jobB = new GenerateNewContentSuggestionsJob(53, 81, 2);

        self::assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $jobA);
        self::assertSame('content-project-new-content:run:52', $jobA->uniqueId());
        self::assertSame('content-project-new-content:run:53', $jobB->uniqueId());
        self::assertNotSame($jobA->uniqueId(), $jobB->uniqueId());

        $middleware = $jobA->middleware();
        self::assertCount(1, $middleware);
        self::assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
    }

    public function test_continuation_dispatch_is_queued_with_delay(): void
    {
        Queue::fake();

        GenerateNewContentSuggestionsJob::dispatch(53, 81, 2)
            ->delay(now()->addSeconds(15));

        Queue::assertPushed(GenerateNewContentSuggestionsJob::class, static function (GenerateNewContentSuggestionsJob $job): bool {
            return $job->plannerRunId === 53
                && $job->projectId === 81
                && $job->actorId === 2
                && $job->delay !== null;
        });
    }
}

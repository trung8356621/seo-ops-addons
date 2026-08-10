<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunPreflightService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

final class SeoProjectRunPreflightServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_accepts_the_has_many_relation_returned_by_pending_tasks_query(): void
    {
        $tasks = Mockery::mock(HasMany::class);
        $tasks->shouldReceive('where')
            ->once()
            ->with('status', SeoProjectTask::STATUS_PENDING)
            ->andReturnSelf();
        $tasks->shouldReceive('orderBy')
            ->once()
            ->with('target_date')
            ->andReturnSelf();
        $tasks->shouldReceive('orderBy')
            ->once()
            ->with('id')
            ->andReturnSelf();
        $tasks->shouldReceive('limit')
            ->once()
            ->with(5)
            ->andReturnSelf();
        $tasks->shouldReceive('get')
            ->once()
            ->andReturn(new Collection);

        $project = Mockery::mock(SeoProject::class)->makePartial();
        $project->shouldReceive('tasks')
            ->once()
            ->andReturn($tasks);

        $service = new SeoProjectRunPreflightService;

        self::assertSame([], $service->findKeywordTitleConflicts($project, 5));
    }
}

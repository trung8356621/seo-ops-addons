<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectContinuationService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemAllocator;
use Omnichannel\Addons\ContentProjects\Services\KeywordProjectAssignmentService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use Omnichannel\Addons\Seo\Services\SeoIssueProjectTaskAssignmentService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectAllocationContractTest extends TestCase
{
    public function test_next_month_uses_calendar_rollover(): void
    {
        $service = new ContentProjectContinuationService();

        self::assertSame('2026-08-01', $service->nextMonth(Carbon::parse('2026-07-01'))->toDateString());
        self::assertSame('2026-12-01', $service->nextMonth(Carbon::parse('2026-11-01'))->toDateString());
        self::assertSame('2027-01-01', $service->nextMonth(Carbon::parse('2026-12-01'))->toDateString());
        self::assertNotSame('2027-13-01', $service->nextMonth(Carbon::parse('2026-12-01'))->format('Y-n-d'));
    }

    public function test_chain_lookup_requires_user_id_and_site_id(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ContentProjectContinuationService::class))->getFileName()
        );

        self::assertStringContainsString("->where('site_id', \$siteId)", $source);
        self::assertStringContainsString("->where('user_id', \$userId)", $source);
        self::assertStringContainsString('KIND_MONTHLY', $source);
        self::assertStringContainsString('lockForUpdate', $source);
        self::assertStringContainsString('GET_LOCK', $source);
        self::assertStringContainsString('CreateContentProjectCommand', $source);
        self::assertStringContainsString("'total_tasks' => 0", $source);
        self::assertStringContainsString("'description' => \$source->description", $source);
        self::assertStringNotContainsString('owner_name', $source);
        self::assertStringNotContainsString('staff_name', $source);
        self::assertStringNotContainsString('forceFill($source->getAttributes())', $source);
    }

    public function test_move_service_no_longer_falls_back_to_any_domain_project(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SeoProjectTaskMoveService::class))->getFileName()
        );

        self::assertStringContainsString('findOrCreateContinuation', $source);
        self::assertStringNotContainsString('$any instanceof SeoProject', $source);
    }

    public function test_shared_assignment_paths_use_allocator_not_capacity_bypass(): void
    {
        $article = (string) file_get_contents(
            (new ReflectionClass(SeoIssueProjectTaskAssignmentService::class))->getFileName()
        );
        $keyword = (string) file_get_contents(
            (new ReflectionClass(KeywordProjectAssignmentService::class))->getFileName()
        );
        $allocator = (string) file_get_contents(
            (new ReflectionClass(ContentProjectItemAllocator::class))->getFileName()
        );

        self::assertStringContainsString('ContentProjectItemAllocator', $article);
        self::assertStringContainsString('projectWithRemainingCapacity', $article);
        self::assertStringNotContainsString('if (! $ignoreMonthlyCapacity && $currentTotal >= $max)', $article);
        self::assertStringContainsString('ContentProjectItemAllocator', $keyword);
        self::assertStringNotContainsString('if (! $ignoreMonthlyCapacity && $currentTotal >= $max)', $keyword);
        self::assertStringContainsString('ContentProjectAllocationSession', $allocator);
    }
}

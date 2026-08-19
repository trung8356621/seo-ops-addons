<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectImproveManualOnlyGenerationGuard;
use PHPUnit\Framework\TestCase;

final class ContentProjectImproveManualOnlyGenerationGuardTest extends TestCase
{
    public function test_filters_improve_by_default(): void
    {
        $itemIds = [10, 11, 12];
        $typesById = [
            10 => SeoProjectTask::TYPE_CREATE,
            11 => SeoProjectTask::TYPE_IMPROVE,
            12 => SeoProjectTask::TYPE_REWRITE,
        ];

        $r = ContentProjectImproveManualOnlyGenerationGuard::filterItemIds(
            $itemIds,
            $typesById,
            allowImproveGeneration: false,
        );

        self::assertSame([10, 12], $r['eligible_ids']);
        self::assertSame([11], $r['skipped_improve_ids']);
        self::assertSame(1, $r['skipped_improve_count']);
    }

    public function test_allows_improve_when_explicit_override_enabled(): void
    {
        $itemIds = [10, 11, 12];
        $typesById = [
            10 => SeoProjectTask::TYPE_CREATE,
            11 => SeoProjectTask::TYPE_IMPROVE,
            12 => SeoProjectTask::TYPE_REWRITE,
        ];

        $r = ContentProjectImproveManualOnlyGenerationGuard::filterItemIds(
            $itemIds,
            $typesById,
            allowImproveGeneration: true,
        );

        self::assertSame([10, 11, 12], $r['eligible_ids']);
        self::assertSame([], $r['skipped_improve_ids']);
        self::assertSame(0, $r['skipped_improve_count']);
    }
}


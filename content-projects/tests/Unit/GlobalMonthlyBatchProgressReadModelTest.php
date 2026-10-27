<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatchItem;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly\GlobalMonthlyBatchProgressReadModel;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectProgressPercent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class GlobalMonthlyBatchProgressReadModelTest extends TestCase
{
    public function test_read_model_source_queries_task_title_and_keyword_not_post_title(): void
    {
        $ref = new ReflectionClass(GlobalMonthlyBatchProgressReadModel::class);
        $src = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringNotContainsString("get(['id', 'post_title', 'keyword'])", $src);
        self::assertStringNotContainsString('task->post_title', $src);
        self::assertStringContainsString("get(['id', 'title', 'keyword'])", $src);
        self::assertStringContainsString('task->title', $src);
        self::assertStringContainsString('task->keyword', $src);
    }

    public function test_read_model_has_child_run_item_overlay_contract(): void
    {
        $ref = new ReflectionClass(GlobalMonthlyBatchProgressReadModel::class);
        $src = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringContainsString('SeoProjectRunItem::query()', $src);
        self::assertStringContainsString('SeoProjectRunItemStatus::fromLegacy', $src);
        self::assertStringContainsString('STATUS_SUCCEEDED', $src);
        self::assertStringContainsString('STATUS_RUNNING', $src);
        self::assertStringContainsString('STATUS_PENDING', $src);
    }

    public function test_bucket_mapping_matches_status_buckets(): void
    {
        $rm = new GlobalMonthlyBatchProgressReadModel();
        $ref = new ReflectionMethod($rm, 'bucket');
        $ref->setAccessible(true);

        self::assertSame('completed', $ref->invoke($rm, SeoContentGlobalBatchItem::STATUS_SUCCEEDED));
        self::assertSame('processing', $ref->invoke($rm, SeoContentGlobalBatchItem::STATUS_RUNNING));
        self::assertSame('failed', $ref->invoke($rm, SeoContentGlobalBatchItem::STATUS_FAILED));
        self::assertSame('skipped', $ref->invoke($rm, SeoContentGlobalBatchItem::STATUS_SKIPPED));
        self::assertSame('cancelled', $ref->invoke($rm, SeoContentGlobalBatchItem::STATUS_CANCELLED));
        self::assertSame('pending', $ref->invoke($rm, SeoContentGlobalBatchItem::STATUS_PENDING));
        self::assertSame('pending', $ref->invoke($rm, 'unknown'));
    }

    public function test_progress_percent_math_on_global_batch_dimensions(): void
    {
        // 98 items, 6 completed, 0 failed, 0 skipped, active run
        $mathActive = ContentProjectProgressPercent::compute(98, 6, 0, 0, false);
        self::assertSame(98, $mathActive['snapshot_total']);
        self::assertSame(98, $mathActive['eligible_total']);
        self::assertSame(6, $mathActive['processed']);
        self::assertSame(6, $mathActive['succeeded']);
        self::assertSame(6, $mathActive['percent']);

        // Terminal run with failures: 98 items, 90 success, 8 failed
        $mathTerminal = ContentProjectProgressPercent::compute(98, 90, 8, 0, true);
        self::assertSame(100, $mathTerminal['percent']);
        self::assertSame(90, $mathTerminal['succeeded']);
        self::assertSame(8, $mathTerminal['failed']);
        self::assertFalse($mathTerminal['no_eligible_work']);
    }

    public function test_format_duration_contract(): void
    {
        self::assertNull(GlobalMonthlyBatchProgressReadModel::formatDuration(null));
        self::assertNull(GlobalMonthlyBatchProgressReadModel::formatDuration(-5));
        self::assertSame('0 giây', GlobalMonthlyBatchProgressReadModel::formatDuration(0));
        self::assertSame('45 giây', GlobalMonthlyBatchProgressReadModel::formatDuration(45));
        self::assertSame('2 phút 35 giây', GlobalMonthlyBatchProgressReadModel::formatDuration(155));
        self::assertSame('5 phút 44 giây', GlobalMonthlyBatchProgressReadModel::formatDuration(344));
        self::assertSame('1 giờ 2 phút 3 giây', GlobalMonthlyBatchProgressReadModel::formatDuration(3723));
    }

    public function test_eager_loads_project_owner_and_started_at_ts(): void
    {
        $ref = new ReflectionClass(GlobalMonthlyBatchProgressReadModel::class);
        $src = (string) file_get_contents((string) $ref->getFileName());

        self::assertStringContainsString("User::query()", $src);
        self::assertStringContainsString("whereIn('id', \$userIds)", $src);
        self::assertStringContainsString("'owner_name'", $src);
        self::assertStringContainsString("'started_at_ts'", $src);
        self::assertStringContainsString("'current_project_owner'", $src);
    }
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProgressReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ContentProjectProgressReadModelOrderingTest extends TestCase
{
    public function test_bucket_mapping_excludes_failed_and_skipped_from_completed(): void
    {
        $method = new ReflectionMethod(ContentProjectProgressReadModel::class, 'bucketForStatus');
        $method->setAccessible(true);
        $model = (new ReflectionClass(ContentProjectProgressReadModel::class))->newInstanceWithoutConstructor();

        self::assertSame('completed', $method->invoke($model, SeoProjectRunItemStatus::Success, false));
        self::assertSame('processing', $method->invoke($model, SeoProjectRunItemStatus::Processing, false));
        self::assertSame('failed', $method->invoke($model, SeoProjectRunItemStatus::Failed, false));
        self::assertSame('skipped', $method->invoke($model, SeoProjectRunItemStatus::Skipped, false));
        self::assertSame('pending', $method->invoke($model, SeoProjectRunItemStatus::Pending, false));
        self::assertSame('cancelled', $method->invoke($model, SeoProjectRunItemStatus::Pending, true));
        self::assertSame('pending', $method->invoke($model, SeoProjectRunItemStatus::Paused, false));
        self::assertSame('cancelled', $method->invoke($model, SeoProjectRunItemStatus::Manual, true));
    }

    public function test_item_query_uses_run_item_id_order_not_task_id(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectProgressReadModel::class))->getFileName(),
        );

        self::assertMatchesRegularExpression("/articleExecution\(\)\\s*->\\s*orderBy\\('id'\\)/", $src);
        self::assertStringNotContainsString("orderBy('task_id')", $src);
        self::assertStringNotContainsString('orderByDesc(\'task_id\')', $src);
    }
}

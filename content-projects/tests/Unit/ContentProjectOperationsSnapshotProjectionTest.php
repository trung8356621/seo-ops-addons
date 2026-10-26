<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectOperationsSnapshotProjectionTest extends TestCase
{
    public function test_operations_query_projects_warning_scalars_without_hydrating_full_snapshots(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectItemOperationsReadModel::class))->getFileName(),
        );

        self::assertStringContainsString(
            "'output_snapshot->warning_code as snapshot_warning_code'",
            $source,
        );
        self::assertStringContainsString(
            "'output_snapshot->actual_words as snapshot_actual_words'",
            $source,
        );
        self::assertDoesNotMatchRegularExpression(
            "/'finished_at',\\s*'output_snapshot',/",
            $source,
        );
    }
}

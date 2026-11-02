<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectEditorialListIndex;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectNeedsEditingIndex;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectOpsStateClassifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectEditorialListIndexTest extends TestCase
{
    public function test_empty_project_ids_return_empty_map(): void
    {
        self::assertSame([], ContentProjectEditorialListIndex::countsByProject([], 0, false));
        self::assertSame([], ContentProjectEditorialListIndex::countsByProject([0, -1], 1, true));
    }

    public function test_index_reuses_ops_classifier_and_needs_editing_evidence(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectEditorialListIndex::class))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectNeedsEditingIndex::evidenceRow', $src);
        self::assertStringContainsString('ContentProjectOpsStateClassifier::classify', $src);
        self::assertStringContainsString('BUCKET_NEEDS_REVIEW', $src);
        self::assertStringContainsString('BUCKET_IN_REVIEW', $src);
        self::assertStringContainsString('inContentProjectWorkingSet()', $src);
        self::assertStringContainsString('isUngeneratedPlanStatus', $src);
        self::assertTrue(class_exists(ContentProjectNeedsEditingIndex::class));
        self::assertTrue(class_exists(ContentProjectOpsStateClassifier::class));
    }
}

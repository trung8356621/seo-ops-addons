<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Support\PublishingTaxonomyHierarchyFlattener;
use PHPUnit\Framework\TestCase;

final class PublishingTaxonomyHierarchyFlattenerTest extends TestCase
{
    public function test_flattens_category_hierarchy_with_prefixes(): void
    {
        $flat = PublishingTaxonomyHierarchyFlattener::flatten([
            ['id' => 3, 'name' => 'Quốc tế', 'parent' => 1],
            ['id' => 1, 'name' => 'Tin tức', 'parent' => 0],
            ['id' => 2, 'name' => 'Trong nước', 'parent' => 1],
        ]);

        self::assertSame([
            ['id' => 1, 'label' => 'Tin tức'],
            ['id' => 3, 'label' => '— Quốc tế'],
            ['id' => 2, 'label' => '— Trong nước'],
        ], $flat);
    }

    public function test_tags_stay_flat_when_parent_is_zero(): void
    {
        $flat = PublishingTaxonomyHierarchyFlattener::flatten([
            ['id' => 21, 'name' => 'Content', 'parent' => 0],
            ['id' => 20, 'name' => 'SEO', 'parent' => 0],
        ]);

        self::assertSame([
            ['id' => 21, 'label' => 'Content'],
            ['id' => 20, 'label' => 'SEO'],
        ], $flat);
    }
}

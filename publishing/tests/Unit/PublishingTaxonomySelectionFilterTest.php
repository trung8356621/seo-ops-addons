<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Support\PublishingTaxonomySelectionFilter;
use PHPUnit\Framework\TestCase;

final class PublishingTaxonomySelectionFilterTest extends TestCase
{
    public function test_keeps_selected_ids_that_exist_in_catalog(): void
    {
        self::assertSame(
            [12, 25],
            PublishingTaxonomySelectionFilter::filter([12, 99, 25, 12], [12, 25, 40], true),
        );
    }

    public function test_drops_invalid_ids_when_catalog_is_available(): void
    {
        self::assertSame(
            [],
            PublishingTaxonomySelectionFilter::filter([99], [12, 25], true),
        );
    }

    public function test_does_not_wipe_selected_ids_when_catalog_unavailable(): void
    {
        self::assertSame(
            [12, 25],
            PublishingTaxonomySelectionFilter::filter([12, 25], [], false),
        );
    }
}

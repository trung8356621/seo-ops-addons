<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\AllDomainsDashboardService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AllDomainsDashboardGroupByContractTest extends TestCase
{
    public function test_team_productivity_replaces_articles_star_before_group_by(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(AllDomainsDashboardService::class))->getFileName(),
        );

        self::assertStringContainsString("->select('articles.user_id')", $source);
        self::assertStringContainsString("->selectRaw('COUNT(*) as aggregate')", $source);
        self::assertStringContainsString("->groupBy('articles.user_id')", $source);
        self::assertStringNotContainsString("->selectRaw('user_id, COUNT(*) as aggregate')", $source);
    }
}

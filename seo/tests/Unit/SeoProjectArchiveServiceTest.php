<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\SeoProjectArchiveService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskEventRecorder;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskLifecycleService;
use ReflectionClass;
use Tests\TestCase;

final class SeoProjectArchiveServiceTest extends TestCase
{
    public function test_it_exposes_site_level_archive_api(): void
    {
        $ref = new ReflectionClass(SeoProjectArchiveService::class);

        self::assertTrue($ref->hasMethod('batchesForProject'));
        self::assertTrue($ref->hasMethod('archiveProject'));
        self::assertTrue($ref->hasMethod('archiveTasks'));
        self::assertTrue($ref->hasMethod('buildGroupedDashboard'));
        self::assertTrue($ref->hasMethod('countForSite'));
        self::assertTrue($ref->hasMethod('unarchiveItem'));
        self::assertFalse($ref->hasMethod('findOrCreateArchiveProject'));
    }

    public function test_constructor_depends_on_lifecycle_service(): void
    {
        $ctor = (new ReflectionClass(SeoProjectArchiveService::class))->getConstructor();
        $this->assertNotNull($ctor);
        $params = $ctor->getParameters();
        $this->assertGreaterThanOrEqual(1, count($params));
        $this->assertSame(SeoProjectTaskLifecycleService::class, $params[0]->getType()?->getName());
        $this->assertSame(SeoProjectTaskEventRecorder::class, $params[1]->getType()?->getName());
    }
}

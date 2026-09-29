<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Services\SiteHealth\SiteHealthStateService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SiteHealthLifecycleContractTest extends TestCase
{
    public function test_debounce_incident_dedupe_recovery_and_new_outage_contract(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(SiteHealthStateService::class))->getFileName());
        self::assertStringContainsString('$failures < 2', $source);
        self::assertStringContainsString('active_incident_id', $source);
        self::assertStringContainsString("'resolved_at' => \$now", $source);
        self::assertStringContainsString("'active_incident_id' => null", $source);
        self::assertStringContainsString('SiteHealthIncident::query()->create', $source);
        self::assertStringContainsString('incidentActive', $source);
        self::assertStringContainsString('incidentResolved', $source);
    }
}

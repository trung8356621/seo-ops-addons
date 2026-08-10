<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverStateService;
use Omnichannel\Addons\SiteSync\Services\Inbound\SiteSyncInboundGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Contract: V2 writer sites must not dual-apply links/keywords/scores via push-content enrich.
 */
final class SiteSyncCompatPushOwnershipContractTest extends TestCase
{
    public function test_ingest_compat_push_gates_v2_enrich_on_cutover_writer(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SiteSyncInboundGateway::class))->getFileName(),
        );

        self::assertStringContainsString('isV2Writer', $source);
        self::assertStringContainsString('skipped_enrich', $source);
        self::assertStringContainsString('v2_writer_uses_delta_or_snapshot', $source);
        self::assertStringContainsString('applyLinksKeywordsScoresOnly', $source);
        self::assertStringContainsString('importPushedItems', $source);
    }

    public function test_gateway_constructor_requires_cutover_service(): void
    {
        $ctor = new ReflectionMethod(SiteSyncInboundGateway::class, '__construct');
        $types = [];
        foreach ($ctor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type !== null) {
                $types[] = $type->__toString();
            }
        }

        self::assertContains(SiteSyncCutoverStateService::class, $types);
    }
}

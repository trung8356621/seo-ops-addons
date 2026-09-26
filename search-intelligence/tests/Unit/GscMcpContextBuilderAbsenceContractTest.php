<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMcpContextBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class GscMcpContextBuilderAbsenceContractTest extends TestCase
{
    public function test_build_marks_empty_period_rows_as_no_synced_data(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GscMcpContextBuilder::class))->getFileName()
        );

        self::assertStringContainsString("emptyPayload(\$domain, \$periodKey, \$property->public_ref, 'no_synced_data')", $src);
        self::assertStringContainsString('if ($currentRows === [])', $src);
    }

    public function test_from_prepared_empty_rows_are_absent_not_zero_metrics(): void
    {
        $builder = (new ReflectionClass(GscMcpContextBuilder::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(GscMcpContextBuilder::class, 'fromPrepared');
        $method->setAccessible(true);

        /** @var array{metrics: array<string, mixed>} $payload */
        $payload = $method->invoke(
            $builder,
            [],
            [],
            'example.com',
            '2026-09',
            '2026-08',
            'gsc:1',
            'sc-domain:example.com',
            false,
            null,
        );

        self::assertTrue(($payload['metrics']['absent'] ?? false) === true);
        self::assertSame('no_synced_data', $payload['metrics']['absent_reason'] ?? null);
        self::assertArrayNotHasKey('performance', $payload);
    }

    public function test_latest_synced_period_resolver_exists_and_is_row_based(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(GscMcpContextBuilder::class))->getFileName()
        );

        self::assertTrue(method_exists(GscMcpContextBuilder::class, 'latestSyncedPeriodOnOrBefore'));
        self::assertStringContainsString('max(\'metric_date\')', $src);
        self::assertStringContainsString('seo_gsc_daily_metrics', $src);
        self::assertStringNotContainsString('last_synced_at', explode(
            'function latestSyncedPeriodOnOrBefore',
            $src,
            2
        )[1] ?? '');
    }
}

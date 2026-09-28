<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * installation namespace must survive after App\Models\ClientControlState was removed.
 */
final class SeedingServiceResolverContractTest extends TestCase
{
    public function test_installation_namespace_reads_control_state_without_deleted_model(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Support/SeedingServiceResolver.php');

        self::assertStringNotContainsString('ClientControlState', $source);
        self::assertStringContainsString("Schema::hasTable('client_control_state')", $source);
        self::assertStringContainsString("DB::table('client_control_state')", $source);
        self::assertStringContainsString("->value('installation_id')", $source);
        self::assertStringContainsString("return 'app:local'", $source);
    }
}

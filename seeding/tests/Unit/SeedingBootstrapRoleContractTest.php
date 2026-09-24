<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Omnichannel\Addons\Seeding\Support\SeedingRoleAssignment;
use Omnichannel\Addons\Seeding\Support\SeedingServiceHealth;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Bootstrap must project real seeding.* roles — not flatten every non-manager to seeder.
 */
final class SeedingBootstrapRoleContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_bootstrap_uses_role_assignment_not_manager_binary(): void
    {
        $source = (string) file_get_contents(
            $this->addonRoot().'/src/Support/SeedingServiceHealth.php'
        );

        self::assertStringContainsString('projectRole', $source);
        self::assertStringContainsString('SeedingRoleAssignment', $source);
        self::assertStringContainsString('SeedingAccess::ROLE_MANAGER', $source);
        self::assertStringContainsString('resolveForUser', $source);
        self::assertStringNotContainsString("'role' => \$access->isManager(\$user) ? 'manager' : 'seeder'", $source);
    }

    public function test_role_constants_match_expected_frontend_values(): void
    {
        self::assertSame('seeding.manager', SeedingAccess::ROLE_MANAGER);
        self::assertSame('seeding.topic_creator', SeedingAccess::ROLE_TOPIC_CREATOR);
        self::assertSame('seeding.seeder', SeedingAccess::ROLE_SEEDER);
    }

    public function test_role_assignment_precedence_is_manager_topic_creator_seeder(): void
    {
        self::assertSame([
            SeedingAccess::ROLE_MANAGER,
            SeedingAccess::ROLE_TOPIC_CREATOR,
            SeedingAccess::ROLE_SEEDER,
        ], SeedingRoleAssignment::PRECEDENCE);
    }

    public function test_normalize_accepts_all_three_roles(): void
    {
        $ref = new ReflectionClass(SeedingRoleAssignment::class);
        /** @var SeedingRoleAssignment $assignment */
        $assignment = $ref->newInstanceWithoutConstructor();

        self::assertSame(SeedingAccess::ROLE_MANAGER, $assignment->normalize('seeding.manager'));
        self::assertSame(SeedingAccess::ROLE_TOPIC_CREATOR, $assignment->normalize('seeding.topic_creator'));
        self::assertSame(SeedingAccess::ROLE_SEEDER, $assignment->normalize('seeding.seeder'));
        self::assertSame(SeedingAccess::ROLE_SEEDER, $assignment->normalize('unknown'));
    }

    public function test_project_role_method_exists_and_is_private(): void
    {
        $ref = new ReflectionClass(SeedingServiceHealth::class);
        self::assertTrue($ref->hasMethod('projectRole'));
        $method = $ref->getMethod('projectRole');
        self::assertTrue($method->isPrivate());
        self::assertSame('string', (string) $method->getReturnType());
    }

    public function test_workspace_gates_seeder_quick_feed_on_exact_role(): void
    {
        $workspace = (string) file_get_contents(
            $this->addonRoot().'/resources/js/seeding/SeedingWorkspace.jsx'
        );
        self::assertStringContainsString("ROLE_SEEDER = 'seeding.seeder'", $workspace);
        self::assertStringContainsString('isSeederQuickFeed', $workspace);
        self::assertStringContainsString('SeederQuickFeed', $workspace);
        self::assertStringContainsString("seedingRole === ROLE_SEEDER && !manager", $workspace);
    }
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Enums\SeedingTopicSourceType;
use PHPUnit\Framework\TestCase;

final class SeedingTopicSourceTypeRetirementContractTest extends TestCase
{
    public function test_seeding_v1_case_is_removed(): void
    {
        $values = array_map(
            static fn (SeedingTopicSourceType $case): string => $case->value,
            SeedingTopicSourceType::cases(),
        );

        self::assertSame(['manual', 'other'], $values);
        self::assertNull(SeedingTopicSourceType::tryFrom('seeding_v1'));
        self::assertFalse(defined(SeedingTopicSourceType::class.'::SeedingV1'));
    }

    public function test_normalization_migration_exists(): void
    {
        $path = dirname(__DIR__, 2)
            .'/database/migrations/2026_09_26_170100_normalize_seeding_v1_source_type_to_other.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString("where('source_type', 'seeding_v1')", $src);
        self::assertStringContainsString("'other'", $src);
    }

    public function test_social_context_resolver_does_not_treat_seeding_v1_as_current_source(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/SeedingSocialContextResolver.php'
        );
        self::assertStringNotContainsString('seeding_v1', $src);
    }
}

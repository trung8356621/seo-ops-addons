<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\LegacyAddonPath;
use Omnichannel\Addons\Content\Console\MigrateSeoArticleReviewsCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Structural coverage cho backfill command `seo:migrate-article-reviews` (không chạm DB —
 * chỉ kiểm tra signature/registration theo pattern SeoProjectTaskMoveServiceTest).
 */
final class MigrateSeoArticleReviewsCommandTest extends TestCase
{
    public function test_command_declares_the_expected_signature_and_dry_run_option(): void
    {
        $command = new ReflectionClass(MigrateSeoArticleReviewsCommand::class);
        $signature = $command->getProperty('signature');
        $signature->setAccessible(true);
        $instance = $command->newInstanceWithoutConstructor();

        $value = (string) $signature->getValue($instance);

        self::assertStringStartsWith('seo:migrate-article-reviews', $value);
        self::assertStringContainsString('--dry-run', $value);
    }

    public function test_command_exposes_handle_and_is_idempotent_by_design(): void
    {
        $ref = new ReflectionClass(MigrateSeoArticleReviewsCommand::class);

        self::assertTrue($ref->hasMethod('handle'));
        self::assertTrue($ref->hasMethod('migrateArchiveHistory'));
        self::assertTrue($ref->hasMethod('backfillPendingReview'));

        $method = $ref->getMethod('migrateArchiveHistory');
        $source = $this->readMethodSource($method);

        // Idempotency guard: phải kiểm tra bản ghi đã tồn tại trước khi tạo mới.
        self::assertStringContainsString('alreadyMigrated', $source);
        self::assertStringContainsString('->exists()', $source);
    }

    public function test_command_is_registered_in_the_service_provider(): void
    {
        $providerFile = (string) file_get_contents(
            LegacyAddonPath::resolve('SeoContentAiServiceProvider.php'),
        );

        self::assertStringContainsString(MigrateSeoArticleReviewsCommand::class, $providerFile);
    }

    private function readMethodSource(\ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }
}

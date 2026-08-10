<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers\UpdateContentProjectItemHandler;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectTaskStatusNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectTaskStatusNormalizerTest extends TestCase
{
    public function test_canonical_enum_values_include_draft_and_processing(): void
    {
        $values = SeoProjectTaskStatus::values();
        self::assertContains('draft', $values);
        self::assertContains('processing', $values);
        self::assertContains(SeoProjectTask::STATUS_DRAFT, $values);
        self::assertContains(SeoProjectTask::STATUS_PROCESSING, $values);
    }

    public function test_model_constants_match_enum(): void
    {
        self::assertSame(SeoProjectTaskStatus::Pending->value, SeoProjectTask::STATUS_PENDING);
        self::assertSame(SeoProjectTaskStatus::Writing->value, SeoProjectTask::STATUS_WRITING);
        self::assertSame(SeoProjectTaskStatus::Processing->value, SeoProjectTask::STATUS_PROCESSING);
        self::assertSame(SeoProjectTaskStatus::Draft->value, SeoProjectTask::STATUS_DRAFT);
        self::assertSame(SeoProjectTaskStatus::Cancelled->value, SeoProjectTask::STATUS_CANCELLED);
    }

    public function test_legacy_aliases_map(): void
    {
        self::assertSame(SeoProjectTaskStatus::Pending, ContentProjectTaskStatusNormalizer::tryNormalize('waiting'));
        self::assertSame(SeoProjectTaskStatus::Writing, ContentProjectTaskStatusNormalizer::tryNormalize('running'));
        self::assertSame(SeoProjectTaskStatus::Completed, ContentProjectTaskStatusNormalizer::tryNormalize('done'));
        self::assertSame(SeoProjectTaskStatus::Cancelled, ContentProjectTaskStatusNormalizer::tryNormalize('canceled'));
    }

    public function test_unknown_fails_closed(): void
    {
        self::assertNull(ContentProjectTaskStatusNormalizer::tryNormalize('totally_bogus'));
        $this->expectException(InvalidArgumentException::class);
        ContentProjectTaskStatusNormalizer::normalizeOrFail('totally_bogus');
    }

    public function test_update_handler_rejects_free_form_status(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(UpdateContentProjectItemHandler::class))->getFileName(),
        );
        self::assertStringContainsString('ContentProjectTaskStatusNormalizer::normalizeOrFail', $src);
        self::assertStringContainsString('archive/cancel commands', $src);
    }

    public function test_generation_recovery_does_not_write_publish_error(): void
    {
        $path = ProjectRoot::addonsPath().'/content-projects/src/Services/ContentProject/ContentProjectGenerationRecoveryService.php';
        $src = (string) file_get_contents($path);
        self::assertStringNotContainsString("'last_publish_error' => \$reason", $src);
        self::assertStringContainsString('generation failures belong on run_item', $src);
    }
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunAction;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use PHPUnit\Framework\TestCase;

final class SeoProjectTaskStatusEnumTest extends TestCase
{
    public function test_legacy_status_constants_still_present_on_model(): void
    {
        $this->assertSame('pending', SeoProjectTask::STATUS_PENDING);
        $this->assertSame('writing', SeoProjectTask::STATUS_WRITING);
        $this->assertSame('reviewing', SeoProjectTask::STATUS_REVIEWING);
        $this->assertSame('completed', SeoProjectTask::STATUS_COMPLETED);
        $this->assertSame('failed', SeoProjectTask::STATUS_FAILED);
    }

    public function test_enum_preserves_legacy_values(): void
    {
        foreach (SeoProjectTaskStatus::legacyValues() as $value) {
            $this->assertContains($value, SeoProjectTaskStatus::values());
            $this->assertNotNull(SeoProjectTaskStatus::tryFrom($value));
        }
    }

    public function test_status_values_are_unique(): void
    {
        $values = SeoProjectTaskStatus::values();
        $this->assertSame(count($values), count(array_unique($values)));
    }

    public function test_action_and_event_values_are_unique_and_disjoint_where_required(): void
    {
        $actions = SeoProjectRunAction::values();
        $events = SeoProjectTaskEventType::values();

        $this->assertSame(count($actions), count(array_unique($actions)));
        $this->assertSame(count($events), count(array_unique($events)));
        $this->assertContains('article.create', $actions);
        $this->assertContains('article.created', $events);
        $this->assertNotContains('article.create', $events);
        $this->assertNotContains('article.created', $actions);
    }

    public function test_terminal_and_active_helpers(): void
    {
        $this->assertTrue(SeoProjectTaskStatus::Completed->isTerminal());
        $this->assertTrue(SeoProjectTaskStatus::Failed->isTerminal());
        $this->assertTrue(SeoProjectTaskStatus::Archived->isTerminal());
        $this->assertTrue(SeoProjectTaskStatus::Cancelled->isTerminal());

        $this->assertFalse(SeoProjectTaskStatus::Pending->isTerminal());
        $this->assertTrue(SeoProjectTaskStatus::Pending->isActive());
        $this->assertTrue(SeoProjectTaskStatus::Writing->isActive());
        $this->assertTrue(SeoProjectTaskStatus::Processing->isActive());
        $this->assertTrue(SeoProjectTaskStatus::Reviewing->isActive());

        $this->assertFalse(SeoProjectTaskStatus::Draft->isActive());
        $this->assertTrue(SeoProjectTaskStatus::Draft->isDraft());
        $this->assertFalse(SeoProjectTaskStatus::Completed->isActive());
    }

    public function test_run_item_status_includes_processing_and_skipped(): void
    {
        $this->assertContains('processing', SeoProjectRunItemStatus::values());
        $this->assertContains('skipped', SeoProjectRunItemStatus::values());
        $this->assertSame('pending', SeoProjectRunItemStatus::Processing->toLegacyJsonStatus());
        $this->assertSame('success', SeoProjectRunItemStatus::Skipped->toLegacyJsonStatus());
    }

    public function test_legacy_task_type_mapper_skeleton(): void
    {
        $this->assertSame(
            SeoProjectRunAction::ArticleCreate,
            SeoProjectRunAction::fromLegacyTaskType('new_keyword'),
        );
        $this->assertSame(
            SeoProjectRunAction::ArticleRewrite,
            SeoProjectRunAction::fromLegacyTaskType('rewrite'),
        );
        $this->assertSame(
            SeoProjectRunAction::ArticleRewrite,
            SeoProjectRunAction::fromLegacyTaskType('improve'),
        );
        $this->assertSame(
            SeoProjectRunAction::ArticleCreate,
            SeoProjectRunAction::fromLegacyTaskType('create'),
        );
        $this->assertSame(
            SeoProjectRunItemStatus::Manual,
            SeoProjectRunItemStatus::fromLegacy('manual'),
        );
    }
}

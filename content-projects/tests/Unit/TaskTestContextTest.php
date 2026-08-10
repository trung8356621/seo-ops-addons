<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use PHPUnit\Framework\TestCase;

final class TaskTestContextTest extends TestCase
{
    public function test_it_preserves_project_task_type_when_serialized(): void
    {
        $context = new TaskTestContext(
            article: null,
            isNewArticle: false,
            matchedBy: 'title',
            variables: ['post_title' => 'Rewrite title'],
            summary: 'Existing article',
            siteId: 1,
            postType: 'article',
            projectTaskType: SeoProjectTask::TYPE_REWRITE,
        );

        $restored = TaskTestContext::fromArray($context->toArray());

        self::assertSame(SeoProjectTask::TYPE_REWRITE, $restored->projectTaskType);
        self::assertSame(1, $restored->siteId);
        self::assertSame('article', $restored->postType);
    }
}

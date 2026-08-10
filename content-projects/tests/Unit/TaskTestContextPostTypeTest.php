<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\TaskTestContext;
use PHPUnit\Framework\TestCase;

final class TaskTestContextPostTypeTest extends TestCase
{
    public function test_with_post_type_overrides_without_losing_other_fields(): void
    {
        $context = new TaskTestContext(
            article: null,
            isNewArticle: true,
            matchedBy: null,
            variables: ['focus_keyword' => 'áo thun'],
            summary: 'test',
            siteId: 12,
            postType: SeoProjectTask::POST_TYPE_PRODUCT,
            projectTaskType: SeoProjectTask::TYPE_NEW_KEYWORD,
        );

        $updated = $context->withPostType(SeoProjectTask::POST_TYPE_ARTICLE);

        self::assertSame(SeoProjectTask::POST_TYPE_ARTICLE, $updated->postType);
        self::assertSame(12, $updated->siteId);
        self::assertSame(SeoProjectTask::TYPE_NEW_KEYWORD, $updated->projectTaskType);
        self::assertSame('áo thun', $updated->variables['focus_keyword']);
        self::assertTrue($updated->isNewArticle);
    }
}

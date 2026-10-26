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

    public function test_to_array_from_array_preserves_nested_workflow_snapshot(): void
    {
        $snapshot = [
            'flow_data_hash' => 'abc123',
            'roles' => ['article_content_generate' => 'node_1'],
            'nodes' => [['id' => 'node_1', 'type' => 'prompt']],
        ];

        $context = new TaskTestContext(
            article: null,
            isNewArticle: true,
            matchedBy: 'content_project_create',
            variables: [
                'post_title' => 'Tiêu đề bài',
                'focus_keyword' => 'keyword',
                'article_length' => 1500,
                'workflow_execution_snapshot' => $snapshot,
                'flow_data_hash' => null,
            ],
            summary: 'Create article',
            siteId: 4,
            projectTaskType: SeoProjectTask::TYPE_CREATE,
        );

        $restored = TaskTestContext::fromArray($context->toArray());

        self::assertSame('Tiêu đề bài', $restored->variables['post_title']);
        self::assertSame('keyword', $restored->variables['focus_keyword']);
        self::assertSame('1500', $restored->variables['article_length']);
        self::assertSame('', $restored->variables['flow_data_hash']);
        self::assertIsArray($restored->variables['workflow_execution_snapshot']);
        self::assertSame($snapshot, $restored->variables['workflow_execution_snapshot']);
        self::assertNotSame('Array', $restored->variables['workflow_execution_snapshot']);
    }

    public function test_from_array_does_not_coerce_array_variables_to_string(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            if (str_contains($message, 'Array to string conversion')) {
                throw new \ErrorException($message, 0, $severity);
            }

            return false;
        });

        try {
            $restored = TaskTestContext::fromArray([
                'article_id' => null,
                'is_new_article' => true,
                'matched_by' => 'system_workflow',
                'summary' => 'raw',
                'variables' => [
                    'input' => 'brief text',
                    'workflow_execution_snapshot' => [
                        'flow_data_hash' => 'h1',
                        'nested' => ['a' => 1],
                    ],
                ],
            ]);
        } finally {
            restore_error_handler();
        }

        self::assertSame('brief text', $restored->variables['input']);
        self::assertSame(
            ['flow_data_hash' => 'h1', 'nested' => ['a' => 1]],
            $restored->variables['workflow_execution_snapshot'],
        );
    }

    public function test_roundtrip_preserves_retry_identity_variables_and_snapshot(): void
    {
        $context = new TaskTestContext(
            article: null,
            isNewArticle: false,
            matchedBy: 'existing',
            variables: [
                'project_task_id' => '99',
                'run_item_id' => '12',
                'workflow_execution_snapshot' => ['flow_data_hash' => 'stable'],
            ],
            summary: 'Retry rewrite',
            siteId: 4,
            projectTaskType: SeoProjectTask::TYPE_REWRITE,
        );

        $payload = $context->toArray();
        // article_id stays in payload for callers; fromArray loads only when numeric>0 (DB).
        self::assertArrayHasKey('article_id', $payload);

        $restored = TaskTestContext::fromArray($payload);

        self::assertSame('99', $restored->variables['project_task_id']);
        self::assertSame('12', $restored->variables['run_item_id']);
        self::assertSame(
            ['flow_data_hash' => 'stable'],
            $restored->variables['workflow_execution_snapshot'],
        );
        self::assertSame(SeoProjectTask::TYPE_REWRITE, $restored->projectTaskType);
        self::assertFalse($restored->isNewArticle);
    }
}

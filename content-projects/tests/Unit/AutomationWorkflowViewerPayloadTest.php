<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Presentation\Workflow\AutomationWorkflowViewerPayload;
use PHPUnit\Framework\TestCase;

final class AutomationWorkflowViewerPayloadTest extends TestCase
{
    public function test_serializes_projection_without_changing_identifiers(): void
    {
        $payload = (new AutomationWorkflowViewerPayload)->fromProjectedWorkflow([
            'id' => 'wf:publishing',
            'name' => 'Publishing',
            'description' => 'Queue to WordPress',
            'category' => 'publishing',
            'category_label' => 'Publishing',
            'mapping_status' => 'partial',
            'mapping_label' => 'Partially mapped',
            'step_count' => 2,
            'component_count' => 1,
            'queued_transitions' => 1,
            'last_status' => null,
            'status_label' => 'Never executed',
            'last_run_at' => null,
            'last_error' => null,
            'definition_sources' => ['ProcessScheduledProjectItemPublishHandler'],
            'nodes' => [
                [
                    'id' => 'pb.publish_now',
                    'canonical' => 'content_project.publish_now',
                    'type' => 'capability',
                    'label' => 'Publish now',
                    'evidence' => 'PublishProjectItemsNowHandler',
                    'run_mode' => 'command_bus',
                    'optional' => false,
                    'registered' => true,
                    'matched_components' => [
                        ['id' => 'capability:content_project.publish_now', 'code' => 'content_project.publish_now', 'last_status' => null],
                    ],
                ],
                [
                    'id' => 'pb.sync_failed',
                    'canonical' => 'wordpress.sync_failed',
                    'type' => 'event',
                    'label' => 'Sync failed',
                    'evidence' => 'SyncArticleToWordPressHookAction',
                    'run_mode' => 'event',
                    'optional' => false,
                    'registered' => true,
                    'matched_components' => [],
                ],
            ],
            'edges' => [
                [
                    'from' => 'pb.publish_now',
                    'to' => 'pb.sync_failed',
                    'type' => 'failure',
                    'type_label' => 'Failure',
                    'evidence' => 'failure path',
                ],
            ],
        ]);

        self::assertSame('wf:publishing', $payload['id']);
        self::assertSame('never_executed', $payload['status']);
        self::assertCount(2, $payload['nodes']);
        self::assertSame('content_project.publish_now', $payload['nodes'][0]['technical_id']);
        self::assertSame('command', $payload['nodes'][0]['type']);
        self::assertSame('event', $payload['nodes'][1]['type']);
        self::assertSame('failure', $payload['edges'][0]['type']);
        self::assertSame('pb.publish_now', $payload['edges'][0]['source']);
        self::assertSame(['PublishProjectItemsNowHandler'], $payload['nodes'][0]['evidence']);
    }
}

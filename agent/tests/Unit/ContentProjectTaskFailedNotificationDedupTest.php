<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\NotificationSendHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\ContentProjectTaskFailedDedupKey;
use Omnichannel\Addons\Seo\Enums\OperationalNotificationEventCode;
use PHPUnit\Framework\TestCase;

final class ContentProjectTaskFailedNotificationDedupTest extends TestCase
{
    public function test_same_project_task_run_error_share_one_dedup_key(): void
    {
        $payload = [
            'project_id' => 12,
            'task_id' => 8838,
            'run_id' => 91,
            'run_item_id' => 4401,
            'error_code' => 'external_workflow_failed',
        ];
        $first = ContentProjectTaskFailedDedupKey::fromPayload($payload);
        $second = ContentProjectTaskFailedDedupKey::fromPayload($payload);

        self::assertSame($first, $second);
        self::assertSame(
            'content_project.task.failed:project:12:task:8838:run:4401:error:external_workflow_failed',
            $first,
        );
    }

    public function test_same_task_different_run_creates_distinct_incident(): void
    {
        $base = [
            'project_id' => 12,
            'task_id' => 8838,
            'error_code' => 'external_workflow_failed',
        ];
        $a = ContentProjectTaskFailedDedupKey::fromPayload($base + ['run_item_id' => 1]);
        $b = ContentProjectTaskFailedDedupKey::fromPayload($base + ['run_item_id' => 2]);

        self::assertNotSame($a, $b);
    }

    public function test_same_run_different_error_code_is_separate_incident(): void
    {
        $base = [
            'project_id' => 12,
            'task_id' => 8838,
            'run_item_id' => 4401,
        ];
        $a = ContentProjectTaskFailedDedupKey::fromPayload($base + ['error_code' => 'external_workflow_failed']);
        $b = ContentProjectTaskFailedDedupKey::fromPayload($base + ['error_code' => 'article_relation_conflict']);

        self::assertNotSame($a, $b);
    }

    public function test_event_uuid_is_not_part_of_dedup_identity(): void
    {
        $payload = [
            'project_id' => 1,
            'task_id' => 2,
            'run_item_id' => 3,
            'error_code' => 'x',
            'event_uuid' => 'aaaa-bbbb',
        ];
        $key = ContentProjectTaskFailedDedupKey::fromPayload($payload);
        self::assertStringNotContainsString('aaaa-bbbb', $key);
    }

    public function test_notification_action_routes_content_project_failure_through_operational_service(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(NotificationSendHookAction::class))->getFileName() ?: '',
        );
        self::assertStringContainsString('OperationalNotificationService', $src);
        self::assertStringContainsString('ContentProjectTaskFailedDedupKey', $src);
        self::assertStringContainsString('ContentProjectTaskFailed', $src);
        self::assertStringContainsString('sendRaw', $src);
        self::assertStringContainsString('BusinessEventName::ContentProjectTaskFailed', $src);
        self::assertSame('content_project.task_failed', OperationalNotificationEventCode::ContentProjectTaskFailed->value);
    }

    public function test_generic_notification_send_still_has_raw_filament_path(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(NotificationSendHookAction::class))->getFileName() ?: '',
        );
        self::assertStringContainsString('function sendRaw', $src);
        self::assertStringContainsString('sendToDatabase', $src);
        self::assertStringContainsString('shouldUseOperationalDedup', $src);
    }

    public function test_producer_skips_duplicate_emit_when_already_failed(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/content-projects/src/Services/SeoProjectWorkflowRunService.php',
        );
        self::assertStringContainsString('$wasAlreadyFailed', $src);
        self::assertStringContainsString('run_item_id', $src);
        self::assertStringContainsString('error_code', $src);
    }
}

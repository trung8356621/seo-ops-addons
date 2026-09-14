<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ArticlePromptRunHistorySplitProgressPresentationTest extends TestCase
{
    public function test_failed_parent_assemble_is_pending_not_success(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'expandSectionedFreeChildSteps');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke($service, [
            'type' => 'prompt',
            'title' => 'Viết bài',
            'status' => 'failed',
            'message' => 'Section intro_04 failed. Assemble was not called.',
            'result_id' => 100,
            'hook_key' => 'article.content.generate',
            'execution_source' => 'sectioned_orchestrator',
            'generation_strategy' => 'sectioned_free',
            'child_prompt_result_ids' => [201, 202, 203, 204],
        ]);

        self::assertCount(1, $rows);
        $children = $rows[0]['child_steps'] ?? [];
        self::assertNotEmpty($children);
        $assemble = end($children);
        self::assertSame('assemble', $assemble['history_role'] ?? null);
        self::assertSame('pending', $assemble['status'] ?? null);
        self::assertStringContainsString('chưa chạy', (string) ($assemble['message'] ?? ''));
    }

    public function test_apply_orchestrator_presentation_uses_partial_failed_label(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'applyOrchestratorSplitPresentation');
        $method->setAccessible(true);

        $item = [
            'status' => 'failed',
            'status_label' => 'Lỗi',
            'message' => 'Section intro_04 failed. Assemble was not called.',
            'history_role' => 'orchestrator',
            'children' => [
                [
                    'status' => 'completed',
                    'section_id' => 's1',
                    'section_order' => 1,
                    'prompt_name' => 'Section 1',
                    'history_role' => 'provider_call',
                ],
                [
                    'status' => 'completed',
                    'section_id' => 's2',
                    'section_order' => 2,
                    'prompt_name' => 'Section 2',
                    'history_role' => 'provider_call',
                ],
                [
                    'status' => 'completed',
                    'section_id' => 's3',
                    'section_order' => 3,
                    'prompt_name' => 'Section 3',
                    'history_role' => 'provider_call',
                ],
                [
                    'status' => 'failed',
                    'section_id' => 's4',
                    'section_order' => 4,
                    'prompt_name' => 'Section 4',
                    'history_role' => 'provider_call',
                ],
                [
                    'status' => 'pending',
                    'history_role' => 'assemble',
                    'prompt_name' => 'Assemble',
                ],
            ],
        ];

        /** @var array<string, mixed> $out */
        $out = $method->invoke($service, $item, null);

        self::assertSame('partial_failed', $out['presentation_state']);
        self::assertSame('INCOMPLETE', $out['status_label']);
        self::assertStringContainsString('3/', (string) $out['message']);
        self::assertStringContainsString('Giữ lại 3 bước', (string) $out['resume_hint']);
        self::assertSame('Tiếp tục từ bước lỗi', $out['cta_label']);
        self::assertStringContainsString('Assemble was not called', (string) ($out['technical_message'] ?? ''));

        $assemble = null;
        foreach ($out['children'] as $child) {
            if (($child['history_role'] ?? '') === 'assemble') {
                $assemble = $child;
                break;
            }
        }
        self::assertNotNull($assemble);
        self::assertSame('Chưa chạy', $assemble['status_label'] ?? null);
    }
}

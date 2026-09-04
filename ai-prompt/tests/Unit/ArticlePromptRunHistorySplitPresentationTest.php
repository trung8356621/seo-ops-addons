<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ArticlePromptRunHistorySplitPresentationTest extends TestCase
{
    public function test_expand_split_keeps_outline_card_free_of_vocabulary_error(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'expandSplitChildSteps');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $children */
        $children = $method->invoke($service, [
            'type' => 'prompt',
            'title' => 'Dàn ý bài viết',
            'status' => 'failed',
            'message' => 'Vocabulary generation failed: AI_ROUTES_EXHAUSTED',
            'result_id' => 22,
            'outline_result_id' => 11,
            'vocabulary_result_id' => 22,
            'outline_status' => 'completed',
            'vocabulary_status' => 'failed',
            'execution_source' => 'split_outline_vocabulary',
            'hook_key' => 'article.outline.structure.generate',
            'prompt_result_ids' => [11, 22],
        ]);

        self::assertCount(2, $children);
        self::assertSame(11, (int) $children[0]['result_id']);
        self::assertStringContainsString('Outline', (string) $children[0]['prompt_name']);
        self::assertSame('completed', (string) $children[0]['status']);
        self::assertSame('', trim((string) ($children[0]['message'] ?? '')));
        self::assertSame(22, (int) $children[1]['result_id']);
        self::assertStringContainsString('Vocabulary', (string) $children[1]['prompt_name']);
        self::assertSame('failed', (string) $children[1]['status']);
        self::assertStringContainsString('Vocabulary generation failed', (string) $children[1]['message']);
    }

    public function test_expand_legacy_vocabulary_failed_without_vocab_result_id(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'expandSplitChildSteps');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $children */
        $children = $method->invoke($service, [
            'type' => 'prompt',
            'title' => 'Dàn ý bài viết — Outline',
            'status' => 'failed',
            'message' => 'Vocabulary generation failed: AI_ROUTES_EXHAUSTED: 1 AI attempts failed.',
            'result_id' => 1220,
            'outline_result_id' => 1220,
            'vocabulary_result_id' => null,
            'outline_status' => 'completed',
            'vocabulary_status' => 'failed',
            'outline_subtask' => 'vocabulary_failed',
            'execution_source' => 'split_outline_vocabulary',
            'hook_key' => 'article.outline.structure.generate',
        ]);

        self::assertCount(2, $children);
        self::assertSame(1220, (int) $children[0]['result_id']);
        self::assertSame('completed', (string) $children[0]['status']);
        self::assertSame('', trim((string) ($children[0]['message'] ?? '')));
        self::assertStringContainsString('Vocabulary', (string) $children[1]['prompt_name']);
        self::assertSame('failed', (string) $children[1]['status']);
        self::assertStringContainsString('Vocabulary generation failed', (string) $children[1]['message']);
    }

    public function test_finalize_prompt_list_orders_outline_before_vocabulary(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'finalizePromptList');
        $method->setAccessible(true);

        $newer = new \DateTimeImmutable('2026-09-04 12:00:00');
        $older = new \DateTimeImmutable('2026-09-04 11:00:00');

        /** @var list<array<string, mixed>> $ordered */
        $ordered = $method->invoke($service, [
            [
                'type' => 'prompt',
                'prompt_name' => 'Vocabulary',
                'outline_subtask' => 'vocabulary',
                'execution_sequence' => 2,
                'attempt' => 2,
                'step_index' => 1,
                'ran_at' => $newer,
            ],
            [
                'type' => 'prompt',
                'prompt_name' => 'Outline',
                'outline_subtask' => 'outline',
                'execution_sequence' => 1,
                'attempt' => 2,
                'step_index' => 0,
                'ran_at' => $older,
            ],
            [
                'type' => 'prompt',
                'prompt_name' => 'Writer',
                'execution_sequence' => 3,
                'attempt' => 2,
                'step_index' => 2,
                'ran_at' => $newer,
            ],
        ]);

        self::assertSame(['Outline', 'Vocabulary', 'Writer'], array_column($ordered, 'prompt_name'));
    }

    public function test_link_service_covers_child_prompt_result_ids(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/PromptResultLinkService.php',
        );
        self::assertStringContainsString('outline_result_id', $src);
        self::assertStringContainsString('vocabulary_result_id', $src);
        self::assertStringContainsString('prompt_result_ids', $src);
    }
}

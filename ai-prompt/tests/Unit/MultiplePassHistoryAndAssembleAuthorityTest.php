<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\ArticlePromptRunHistoryService;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionPersistence;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeAssembleArticle;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeRunState;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MultiplePassHistoryAndAssembleAuthorityTest extends TestCase
{
    public function test_sectioned_history_keeps_parent_children_ordered_with_assemble(): void
    {
        $service = new ArticlePromptRunHistoryService;
        $method = new ReflectionMethod($service, 'expandSectionedFreeChildSteps');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke($service, [
            'type' => 'prompt',
            'title' => 'Viết bài',
            'status' => 'completed',
            'result_id' => 100,
            'hook_key' => 'article.content.generate',
            'child_prompt_result_ids' => [101, 102, 103],
            'prompt_result_ids' => [100, 101, 102, 103],
            'generation_strategy' => 'sectioned',
            'execution_source' => 'sectioned_orchestrator',
            'output' => 'AAA...BBB...CCC',
        ]);

        self::assertCount(1, $rows);
        $parent = $rows[0];
        self::assertSame('orchestrator', $parent['history_role'] ?? null);
        self::assertFalse((bool) ($parent['ai_call'] ?? true));
        self::assertTrue((bool) ($parent['final_output_authority'] ?? false));
        self::assertSame([101, 102, 103], $parent['child_prompt_result_ids'] ?? null);

        $children = is_array($parent['child_steps'] ?? null) ? $parent['child_steps'] : [];
        self::assertCount(4, $children);
        self::assertSame(101, (int) ($children[0]['result_id'] ?? 0));
        self::assertSame(102, (int) ($children[1]['result_id'] ?? 0));
        self::assertSame(103, (int) ($children[2]['result_id'] ?? 0));
        self::assertSame('provider_call', $children[0]['history_role'] ?? null);
        self::assertTrue((bool) ($children[0]['ai_call'] ?? false));

        $assemble = $children[3];
        self::assertSame('assemble', $assemble['history_role'] ?? null);
        self::assertFalse((bool) ($assemble['ai_call'] ?? true));
        self::assertSame('deterministic_concat', $assemble['mode'] ?? null);
        self::assertArrayHasKey('result_id', $assemble);
        self::assertNull($assemble['result_id']);
        self::assertSame('AAA...BBB...CCC', $assemble['output'] ?? null);
    }

    public function test_final_output_authority_is_assembled_not_last_child(): void
    {
        $assembler = new SectionedFreeAssembleArticle;
        $units = [
            new SectionedFreeSectionUnit('s1', 0, 'S1', SectionedFreeSectionUnit::ROLE_INTRO, [], [], 80, 120, 100),
            new SectionedFreeSectionUnit('s2', 1, 'S2', SectionedFreeSectionUnit::ROLE_BODY, [], [], 80, 120, 100),
            new SectionedFreeSectionUnit('s3', 2, 'S3', SectionedFreeSectionUnit::ROLE_CONCLUSION, [], [], 80, 120, 100),
        ];
        $sections = [
            [
                'section_id' => 's1',
                'section_order' => 0,
                'output' => 'AAA',
                'status' => SectionedFreeRunState::STATUS_COMPLETED,
            ],
            [
                'section_id' => 's2',
                'section_order' => 1,
                'output' => 'BBB',
                'status' => SectionedFreeRunState::STATUS_COMPLETED,
            ],
            [
                'section_id' => 's3',
                'section_order' => 2,
                'output' => 'CCC',
                'status' => SectionedFreeRunState::STATUS_COMPLETED,
            ],
        ];

        $assembled = $assembler->assemble($sections, $units);

        self::assertStringContainsString('AAA', $assembled);
        self::assertStringContainsString('BBB', $assembled);
        self::assertStringContainsString('CCC', $assembled);
        self::assertNotSame('CCC', $assembled);
    }

    public function test_persistence_retains_exact_section_compiled_prompt_and_hash(): void
    {
        $persistence = (new \ReflectionClass(PromptExecutionPersistence::class))
            ->newInstanceWithoutConstructor();

        $sectionPrompt = 'EXACT SECTION PROMPT BOUNDARY';
        $snapshot = [
            'compiled_prompt' => $sectionPrompt,
            'manual_compiled' => true,
            'sectioned_free_section' => true,
            'section_order' => 10,
            'section_count' => 11,
            'hook_key' => 'article.content.section.generate',
        ];

        self::assertTrue($persistence->shouldRetainExactCompiledPrompt($snapshot));
        $slim = $persistence->slimSnapshot($snapshot, true);
        self::assertSame($sectionPrompt, $slim['compiled_prompt'] ?? null);
        self::assertSame(hash('sha256', $sectionPrompt), hash('sha256', (string) $slim['compiled_prompt']));
    }

    public function test_persistence_still_strips_non_manual_compiled_prompt(): void
    {
        $persistence = (new \ReflectionClass(PromptExecutionPersistence::class))
            ->newInstanceWithoutConstructor();
        $slim = $persistence->slimSnapshot([
            'compiled_prompt' => 'FULL PROMPT TEXT',
            'hook_key' => 'article.outline.generate',
        ]);
        self::assertArrayNotHasKey('compiled_prompt', $slim);
    }
}

<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeAssembleArticle;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeRunState;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use PHPUnit\Framework\TestCase;

final class SectionedFreeAssemblerOwnsHeadingsTest extends TestCase
{
    public function test_assembler_adds_planned_h2_when_ai_omits_heading(): void
    {
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'h2_01',
            order: 0,
            label: 'Parent',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [[
                'kind' => 'h2',
                'heading' => 'Parent',
                'level' => 2,
                'body' => '',
                'emit_heading' => true,
                'parent_h2' => null,
            ]],
            requiredPoints: [],
            targetMinWords: 100,
            targetMaxWords: 200,
            preferredTargetWords: 150,
            parentH2: null,
            emitParentHeading: true,
        );

        $assembled = (new SectionedFreeAssembleArticle())->assemble([
            [
                'section_id' => 'h2_01',
                'section_order' => 0,
                'status' => SectionedFreeRunState::STATUS_COMPLETED,
                'output' => 'Body without any heading from the model.',
            ],
        ], [$unit]);

        self::assertMatchesRegularExpression('/^##\s+Parent\s*$/mu', $assembled);
        self::assertStringContainsString('Body without any heading', $assembled);
        self::assertSame(1, preg_match_all('/^##\s+Parent\s*$/mu', $assembled));
    }

    public function test_h2_with_three_h3_emits_parent_once(): void
    {
        $units = [];
        foreach (['A', 'B', 'C'] as $i => $title) {
            $units[] = new SectionedFreeSectionUnit(
                sectionId: 'h3_0'.($i + 1),
                order: $i,
                label: $title,
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [
                    [
                        'kind' => 'h2_context',
                        'heading' => 'Parent',
                        'level' => 2,
                        'body' => '',
                        'emit_heading' => false,
                        'parent_h2' => 'Parent',
                    ],
                    [
                        'kind' => 'h3',
                        'heading' => $title,
                        'level' => 3,
                        'body' => '',
                        'emit_heading' => true,
                        'parent_h2' => 'Parent',
                    ],
                ],
                requiredPoints: [],
                targetMinWords: 50,
                targetMaxWords: 120,
                preferredTargetWords: 80,
                parentH2: 'Parent',
                emitParentHeading: true,
                includedH3s: [$title],
            );
        }

        $assembled = (new SectionedFreeAssembleArticle())->assemble([
            ['section_id' => 'h3_01', 'section_order' => 0, 'status' => 'completed', 'output' => 'content A'],
            ['section_id' => 'h3_02', 'section_order' => 1, 'status' => 'completed', 'output' => 'content B'],
            ['section_id' => 'h3_03', 'section_order' => 2, 'status' => 'completed', 'output' => 'content C'],
        ], $units);

        self::assertSame(1, preg_match_all('/^##\s+Parent\s*$/mu', $assembled));
        self::assertSame(1, preg_match_all('/^###\s+A\s*$/mu', $assembled));
        self::assertSame(1, preg_match_all('/^###\s+B\s*$/mu', $assembled));
        self::assertSame(1, preg_match_all('/^###\s+C\s*$/mu', $assembled));
        self::assertStringContainsString('content A', $assembled);
        self::assertStringContainsString('content B', $assembled);
        self::assertStringContainsString('content C', $assembled);
    }

    public function test_child_repeated_heading_is_deduped(): void
    {
        $unit = new SectionedFreeSectionUnit(
            sectionId: 'h3_01',
            order: 0,
            label: 'A',
            role: SectionedFreeSectionUnit::ROLE_BODY,
            outlineNodes: [[
                'kind' => 'h3',
                'heading' => 'A',
                'level' => 3,
                'body' => '',
                'emit_heading' => true,
            ]],
            requiredPoints: [],
            targetMinWords: 20,
            targetMaxWords: 80,
            preferredTargetWords: 40,
            parentH2: null,
            emitParentHeading: true,
        );

        $assembled = (new SectionedFreeAssembleArticle())->assemble([
            [
                'section_id' => 'h3_01',
                'section_order' => 0,
                'status' => 'completed',
                'output' => "### A\ncontent",
            ],
        ], [$unit]);

        self::assertSame(1, preg_match_all('/^###\s+A\s*$/mu', $assembled));
        self::assertStringContainsString('content', $assembled);
    }

    public function test_missing_successful_section_refuses_assemble(): void
    {
        $units = [
            new SectionedFreeSectionUnit('s1', 0, 'S1', SectionedFreeSectionUnit::ROLE_BODY, [], [], 10, 50, 20),
            new SectionedFreeSectionUnit('s2', 1, 'S2', SectionedFreeSectionUnit::ROLE_BODY, [], [], 10, 50, 20),
        ];

        $this->expectException(PromptRunException::class);
        $this->expectExceptionMessage('SECTIONED_FREE_SECTION_FAILED');
        (new SectionedFreeAssembleArticle())->assemble([
            ['section_id' => 's1', 'section_order' => 0, 'status' => 'completed', 'output' => 'only one'],
        ], $units);
    }

    public function test_structure_mismatch_when_heading_missing_from_assembled(): void
    {
        $assembler = new SectionedFreeAssembleArticle();
        $units = [
            new SectionedFreeSectionUnit(
                sectionId: 'h2_01',
                order: 0,
                label: 'Parent',
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [[
                    'kind' => 'h2',
                    'heading' => 'Parent',
                    'level' => 2,
                    'body' => '',
                    'emit_heading' => true,
                ]],
                requiredPoints: [],
                targetMinWords: 10,
                targetMaxWords: 40,
                preferredTargetWords: 20,
            ),
        ];

        try {
            $assembler->assertStructure("Just body without heading.\n", $units);
            $this->fail('expected SECTIONED_FREE_STRUCTURE_MISMATCH');
        } catch (PromptRunException $e) {
            self::assertSame('SECTIONED_FREE_STRUCTURE_MISMATCH', $e->context['failure_code'] ?? null);
            self::assertContains('## Parent', $e->context['missing_headings'] ?? []);
        }
    }

    public function test_fixture_eleven_units_body_without_headings_keeps_hierarchy(): void
    {
        $units = [];
        $sections = [];
        // 2 intros + 1 h2 group with 3 h3 + 1 bare-ish + conclusion-like pattern simplified to 11
        $units[] = new SectionedFreeSectionUnit(
            'intro_01', 0, 'Introduction', SectionedFreeSectionUnit::ROLE_INTRO,
            [], [], 50, 120, 80, null, false,
        );
        $sections[] = ['section_id' => 'intro_01', 'section_order' => 0, 'status' => 'completed', 'output' => str_repeat('intro1 ', 40)];

        $units[] = new SectionedFreeSectionUnit(
            'intro_02', 1, 'Introduction', SectionedFreeSectionUnit::ROLE_INTRO,
            [], [], 50, 120, 80, null, false,
        );
        $sections[] = ['section_id' => 'intro_02', 'section_order' => 1, 'status' => 'completed', 'output' => str_repeat('intro2 ', 40)];

        $h3s = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        foreach ($h3s as $i => $title) {
            $order = $i + 2;
            $id = 'h3_'.str_pad((string) ($order + 1), 2, '0', STR_PAD_LEFT);
            $parent = $i < 4 ? 'Group One' : 'Group Two';
            $units[] = new SectionedFreeSectionUnit(
                sectionId: $id,
                order: $order,
                label: $title,
                role: SectionedFreeSectionUnit::ROLE_BODY,
                outlineNodes: [
                    ['kind' => 'h2_context', 'heading' => $parent, 'level' => 2, 'body' => '', 'emit_heading' => false, 'parent_h2' => $parent],
                    ['kind' => 'h3', 'heading' => $title, 'level' => 3, 'body' => '', 'emit_heading' => true, 'parent_h2' => $parent],
                ],
                requiredPoints: [],
                targetMinWords: 40,
                targetMaxWords: 100,
                preferredTargetWords: 60,
                parentH2: $parent,
                emitParentHeading: true,
                includedH3s: [$title],
            );
            $sections[] = [
                'section_id' => $id,
                'section_order' => $order,
                'status' => 'completed',
                'output' => 'body '.$title.' '.str_repeat('word ', 50),
            ];
        }

        $units[] = new SectionedFreeSectionUnit(
            'conclusion_03', 10, 'Conclusion', SectionedFreeSectionUnit::ROLE_CONCLUSION,
            [['kind' => 'conclusion', 'heading' => 'Kết luận', 'level' => 2, 'body' => '', 'emit_heading' => true]],
            [], 40, 100, 60, null, true,
        );
        $sections[] = [
            'section_id' => 'conclusion_03',
            'section_order' => 10,
            'status' => 'completed',
            'output' => str_repeat('end ', 40),
        ];

        self::assertCount(11, $units);
        $assembled = (new SectionedFreeAssembleArticle())->assemble($sections, $units);

        self::assertSame(2, preg_match_all('/^##\s+Group (One|Two)\s*$/mu', $assembled));
        self::assertSame(8, preg_match_all('/^###\s+[A-H]\s*$/mu', $assembled));
        self::assertMatchesRegularExpression('/^##\s+Kết luận\s*$/mu', $assembled);
        foreach ($h3s as $title) {
            self::assertStringContainsString('body '.$title, $assembled);
        }
        self::assertStringContainsString('intro1', $assembled);
        self::assertStringContainsString('intro2', $assembled);
        self::assertStringContainsString('end', $assembled);
    }
}

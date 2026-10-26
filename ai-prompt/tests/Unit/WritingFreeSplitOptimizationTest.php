<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeRunState;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionValidator;
use Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;
use PHPUnit\Framework\TestCase;

final class WritingFreeSplitOptimizationTest extends TestCase
{
    public function test_dense_4323_shape_is_six_ai_calls_with_complete_ordered_coverage(): void
    {
        $rows = $this->rows($this->denseOutline());
        $plan = (new WritingMultiplePassStepPlanner)->planFromRows($rows);
        $aiUnits = array_values(array_filter($plan->units, fn (SectionedFreeSectionUnit $u): bool => $u->role !== SectionedFreeSectionUnit::ROLE_FAQ));

        self::assertCount(6, $aiUnits);
        self::assertCount(7, $plan->units);
        self::assertSame(2000, array_sum(array_column(array_map(fn ($u) => $u->toArray(), $aiUnits), 'preferred_target_words')));

        $expected = array_values(array_filter(array_column($rows, 'id'), fn (string $id): bool => $id !== ''));
        $actual = [];
        foreach ($plan->units as $unit) {
            array_push($actual, ...$unit->sourceRowIds);
        }
        self::assertSame($expected, $actual);
        self::assertSame($actual, array_values(array_unique($actual)), 'No outline row may occur in two units.');
    }

    public function test_short_outline_does_not_create_unnecessary_calls(): void
    {
        $plan = (new WritingMultiplePassStepPlanner)->planFromRows($this->rows("## Tổng quan\nGợi ý ngắn.\n\n## Kết luận\nTóm tắt."));
        $aiUnits = array_filter($plan->units, fn (SectionedFreeSectionUnit $u): bool => $u->role !== SectionedFreeSectionUnit::ROLE_FAQ);
        self::assertCount(1, $aiUnits);
    }

    public function test_planner_has_no_post_type_branch_and_uses_one_2000_word_budget(): void
    {
        $planner = new WritingMultiplePassStepPlanner;
        $article = $planner->planFromRows($this->rows($this->denseOutline()));
        $product = $planner->planFromRows($this->rows($this->denseOutline()));
        self::assertSame($article->meta, $product->meta);
        self::assertSame(2000, $article->articleTargetWords());
        $source = file_get_contents((new \ReflectionClass($planner))->getFileName());
        self::assertStringNotContainsString('post_type', (string) $source);
    }

    public function test_short_complete_conclusion_is_not_held_to_120_words(): void
    {
        $unit = new SectionedFreeSectionUnit('c', 0, 'Kết luận', SectionedFreeSectionUnit::ROLE_CONCLUSION, [], [], 90, 120, 100, sourceRowIds: ['conclusion']);
        $output = implode(' ', array_fill(0, 55, 'word'));
        self::assertSame(55, (new SectionedFreeSectionValidator)->assertAcceptable($output, $unit));
    }

    public function test_checkpoint_reuse_requires_same_row_ids_scope_and_budget(): void
    {
        $base = new SectionedFreeSectionUnit('stable', 0, 'A', SectionedFreeSectionUnit::ROLE_BODY, [], [], 200, 300, 250, sourceRowIds: ['r1', 'r2']);
        $state = new SectionedFreeRunState;
        $state->planSection($base);
        $state->markCompleted('stable', 'completed body', 2);
        $state->planSection($base);
        self::assertTrue($state->isCompleted('stable'));

        $changed = new SectionedFreeSectionUnit('stable', 0, 'A', SectionedFreeSectionUnit::ROLE_BODY, [], [], 200, 400, 350, sourceRowIds: ['r1', 'r2']);
        $state->planSection($changed);
        self::assertFalse($state->isCompleted('stable'));
    }

    public function test_plan_change_removes_obsolete_checkpoint(): void
    {
        $state = new SectionedFreeRunState;
        $old = new SectionedFreeSectionUnit('old', 0, 'Old', SectionedFreeSectionUnit::ROLE_BODY, [], [], 100, 200, 150, sourceRowIds: ['r1']);
        $state->planSection($old);
        $state->markCompleted('old', 'old output', 2);
        $state->retainPlannedSections(['new']);
        self::assertSame([], $state->sectionsSorted());
    }

    public function test_dynamic_validator_rejects_materially_incomplete_group(): void
    {
        $unit = new SectionedFreeSectionUnit('g', 0, 'Group', SectionedFreeSectionUnit::ROLE_BODY, [], [], 360, 440, 400);
        $this->expectException(PromptRunException::class);
        (new SectionedFreeSectionValidator)->assertAcceptable(implode(' ', array_fill(0, 100, 'word')), $unit);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $outline): array
    {
        return (new OutlineStructuredRowsNormalizer)->normalize($outline);
    }

    private function denseOutline(): string
    {
        $parts = ["[MỞ BÀI]\nGiới thiệu."];
        $counts = [3, 6, 5, 3, 3, 0];
        foreach ($counts as $h2 => $children) {
            $parts[] = '## Phần '.($h2 + 1)."\nGợi ý phần ".($h2 + 1).'.';
            for ($h3 = 1; $h3 <= $children; $h3++) {
                $parts[] = '### '.($h2 + 1).'.'.$h3." Chủ đề\nChi tiết cần viết.";
            }
        }
        $parts[] = "## Câu hỏi thường gặp\n- Câu hỏi?";
        $parts[] = "## Kết luận\nTóm tắt hoàn chỉnh.";

        return implode("\n\n", $parts);
    }
}

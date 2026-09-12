<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Services\PromptTestPublishService;
use Omnichannel\Addons\AiPrompt\Services\WritingMultiplePassStepPlanner;
use Omnichannel\Addons\AiPrompt\Support\PromptTextMetrics;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;

/**
 * Rebuild MULTIPLE_PASS article body from selected successful section outputs
 * using deterministic heading assembly — no AI re-call.
 */
final class SectionedFreeArticleRepairService
{
    public function __construct(
        private readonly SectionedFreeAssembleArticle $assembler = new SectionedFreeAssembleArticle(),
        private readonly OutlineStructuredRowsNormalizer $rowsNormalizer = new OutlineStructuredRowsNormalizer(),
        private readonly WritingMultiplePassStepPlanner $planner = new WritingMultiplePassStepPlanner(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dryRun(int $articleId, int $parentPromptResultId): array
    {
        return $this->build($articleId, $parentPromptResultId, persist: false);
    }

    /**
     * @return array<string, mixed>
     */
    public function repair(int $articleId, int $parentPromptResultId): array
    {
        return $this->build($articleId, $parentPromptResultId, persist: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(int $articleId, int $parentPromptResultId, bool $persist): array
    {
        $parent = PromptResult::query()->find($parentPromptResultId);
        if (! $parent instanceof PromptResult) {
            return ['success' => false, 'message' => 'Parent PromptResult not found: '.$parentPromptResultId];
        }

        $snapshot = is_array($parent->input_snapshot) ? $parent->input_snapshot : [];
        if (empty($snapshot['sectioned_free_orchestrator']) && empty($snapshot['child_prompt_result_ids'])) {
            return ['success' => false, 'message' => 'PromptResult is not a MULTIPLE_PASS orchestrator parent.'];
        }

        $selectedChildIds = array_values(array_filter(array_map(
            'intval',
            is_array($snapshot['child_prompt_result_ids'] ?? null) ? $snapshot['child_prompt_result_ids'] : [],
        )));

        $outlineMarkdown = $this->resolveOutlineMarkdown($articleId, $snapshot, $parent);
        if ($outlineMarkdown === '') {
            return ['success' => false, 'message' => 'Could not resolve execution outline markdown for plan reconstruction.'];
        }

        $rows = $this->rowsNormalizer->normalize($outlineMarkdown);
        $plan = $this->planner->planFromRows($rows);
        $plannedIds = array_map(static fn (SectionedFreeSectionUnit $u): string => $u->sectionId, $plan->units);

        $breadcrumbIds = [];
        foreach (is_array($snapshot['breadcrumbs'] ?? null) ? $snapshot['breadcrumbs'] : [] as $crumb) {
            if (! is_array($crumb) || ($crumb['event'] ?? '') !== 'sections_planned') {
                continue;
            }
            $data = is_array($crumb['data'] ?? null) ? $crumb['data'] : [];
            foreach (is_array($data['section_ids'] ?? null) ? $data['section_ids'] : [] as $sid) {
                $breadcrumbIds[] = (string) $sid;
            }
        }

        if ($breadcrumbIds !== [] && $breadcrumbIds !== $plannedIds) {
            return [
                'success' => false,
                'message' => 'STOP: reconstructed plan section IDs do not match parent breadcrumbs.',
                'planned_ids' => $plannedIds,
                'breadcrumb_ids' => $breadcrumbIds,
            ];
        }

        if (count($plannedIds) !== 11 && $breadcrumbIds !== [] && count($breadcrumbIds) === 11) {
            // keep breadcrumb authority if count known
        }

        $children = PromptResult::query()
            ->whereIn('id', $selectedChildIds ?: [0])
            ->get()
            ->keyBy('id');

        $sectionRows = [];
        $failedAttempts = 0;
        $retryRecovered = [];
        $selectedMap = [];

        foreach ($selectedChildIds as $cid) {
            $child = $children->get($cid);
            if (! $child instanceof PromptResult) {
                return ['success' => false, 'message' => 'Missing selected child PromptResult #'.$cid];
            }
            $cs = is_array($child->input_snapshot) ? $child->input_snapshot : [];
            $sid = trim((string) ($cs['section_id'] ?? ''));
            if ($sid === '') {
                return ['success' => false, 'message' => 'Child #'.$cid.' missing section_id'];
            }
            if (! in_array(strtolower((string) $child->status), ['completed', 'success'], true)) {
                return ['success' => false, 'message' => 'Selected child #'.$cid.' is not completed (status='.$child->status.')'];
            }
            $selectedMap[$sid] = $child;
            $sectionRows[] = [
                'section_id' => $sid,
                'section_order' => (int) ($cs['section_order'] ?? 0),
                'status' => SectionedFreeRunState::STATUS_COMPLETED,
                'output' => (string) ($child->output_text ?? ''),
                'word_count' => PromptTextMetrics::wordCount((string) ($child->output_text ?? '')),
                'emit_parent_heading' => (bool) ($cs['emit_parent_heading'] ?? true),
                'parent_h2' => $cs['parent_h2'] ?? null,
            ];
        }

        // Count failed attempts for same article/run via sibling PRs keyed by section.
        $projectRunId = (int) ($snapshot['project_run_id'] ?? $parent->run_id ?? 0);
        if ($projectRunId > 0) {
            $siblings = PromptResult::query()
                ->where('run_id', $projectRunId)
                ->where('id', '!=', $parentPromptResultId)
                ->get();
            foreach ($siblings as $sib) {
                $ss = is_array($sib->input_snapshot) ? $sib->input_snapshot : [];
                if (empty($ss['sectioned_free_section'])) {
                    continue;
                }
                if (in_array(strtolower((string) $sib->status), ['failed', 'error'], true)) {
                    $failedAttempts++;
                    $sid = (string) ($ss['section_id'] ?? '');
                    if ($sid !== '' && isset($selectedMap[$sid])) {
                        $retryRecovered[$sid] = true;
                    }
                }
            }
        }

        $completedIds = array_column($sectionRows, 'section_id');
        sort($completedIds);
        $plannedSorted = $plannedIds;
        sort($plannedSorted);
        if ($completedIds !== $plannedSorted) {
            return [
                'success' => false,
                'message' => 'STOP: selected completed section_ids do not match planned unit ids.',
                'planned_ids' => $plannedIds,
                'completed_ids' => array_column($sectionRows, 'section_id'),
            ];
        }

        $oldParent = (string) ($parent->output_text ?? '');
        $normalizer = new SectionedFreeChildOutputNormalizer();
        $metaBefore = $normalizer->countMetadataOccurrences($oldParent);
        $newAssembled = $this->assembler->assemble($sectionRows, $plan->units);
        $this->assembler->assertWordParity($sectionRows, $newAssembled, 40, $plan->units);
        $metaAfter = $normalizer->countMetadataOccurrences($newAssembled);

        $article = SeoArticle::query()->find($articleId);
        $oldBody = $article instanceof SeoArticle ? (string) ($article->body ?? '') : '';

        $oldHeadings = $this->assembler->parseHeadingSequence($oldParent);
        $newHeadings = $this->assembler->parseHeadingSequence($newAssembled);
        $expected = $this->assembler->computeExpectedHeadingSequence($plan->units);

        $report = [
            'success' => true,
            'parent_result_id' => $parentPromptResultId,
            'article_id' => $articleId,
            'planned_sections' => count($plan->units),
            'completed_sections' => count($sectionRows),
            'failed_attempts' => $failedAttempts,
            'retry_recovered_sections' => array_keys($retryRecovered),
            'selected_child_ids' => $selectedChildIds,
            'old' => [
                'markdown_chars' => mb_strlen($oldParent),
                'heading_count' => count($oldHeadings),
                'heading_sequence' => $oldHeadings,
                'body_chars' => mb_strlen($oldBody),
            ],
            'new' => [
                'markdown_chars' => mb_strlen($newAssembled),
                'heading_count' => count($newHeadings),
                'heading_sequence' => $newHeadings,
            ],
            'expected_heading_sequence' => $expected,
            'missing_headings' => array_values(array_diff($expected, $newHeadings)),
            'extra_headings' => array_values(array_filter(
                $newHeadings,
                static fn (string $h): bool => ! in_array($h, $expected, true),
            )),
            'meta_occurrences' => [
                'old' => $metaBefore,
                'new' => $metaAfter,
            ],
            'body_write_required' => true,
            'persisted' => false,
            'parent_mutated' => false,
            'sections_retained_11_of_11' => count($sectionRows) === 11 && count($plan->units) === 11,
            'repaired_markdown' => $newAssembled,
        ];

        if (! $persist) {
            return $report;
        }

        if (! $article instanceof SeoArticle) {
            $report['success'] = false;
            $report['message'] = 'Article not found: '.$articleId;

            return $report;
        }

        $publish = app(PromptTestPublishService::class)->publishArticle($article, $newAssembled, [
            'article_id' => $articleId,
            'repair_source' => 'sectioned_free_heading_repair',
            'parent_prompt_result_id' => $parentPromptResultId,
            // Repair must not invent title from first H2 after metadata strip.
            '_protect_article_title' => '1',
            'post_title' => trim((string) ($article->title ?? '')),
            'title' => trim((string) ($article->title ?? '')),
            'article_title' => trim((string) ($article->title ?? '')),
        ]);
        if (! ($publish['success'] ?? false)) {
            $report['success'] = false;
            $report['message'] = (string) ($publish['message'] ?? 'publishArticle failed');

            return $report;
        }

        // Preserve historical parent output; stamp repair audit on snapshot only.
        $snapshot['heading_repair'] = [
            'at' => now()->toIso8601String(),
            'previous_output_sha256' => hash('sha256', $oldParent),
            'previous_heading_count' => count($oldHeadings),
            'new_heading_count' => count($newHeadings),
            'repaired_output_sha256' => hash('sha256', $newAssembled),
            'source' => 'deterministic_assemble_repair',
            'selected_child_ids' => $selectedChildIds,
            'parent_output_updated' => true,
            'meta_occurrences_before' => $metaBefore,
            'meta_occurrences_after' => $metaAfter,
        ];
        // Keep final authority aligned with article: update parent output_text after snapshot audit.
        $parent->update([
            'output_text' => $newAssembled,
            'input_snapshot' => $snapshot,
        ]);

        $fresh = $article->fresh() ?? $article;
        if (app()->bound(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class)) {
            app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class)
                ->invalidateForLegacyBodyWrite($fresh, 'sectioned_free_meta_heading_repair');
            $fresh = $fresh->fresh() ?? $fresh;
        }
        $report['persisted'] = true;
        $report['parent_mutated'] = true;
        $report['new']['body_chars'] = mb_strlen((string) ($fresh->body ?? ''));
        $report['publish'] = [
            'success' => true,
            'message' => $publish['message'] ?? null,
        ];
        unset($report['repaired_markdown']);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function resolveOutlineMarkdown(int $articleId, array $snapshot, PromptResult $parent): string
    {
        $projectRunId = (int) ($snapshot['project_run_id'] ?? $parent->run_id ?? 0);
        if ($projectRunId > 0) {
            $item = SeoProjectRunItem::query()
                ->where('run_id', $projectRunId)
                ->where('article_id', $articleId)
                ->orderByDesc('id')
                ->first();
            if ($item instanceof SeoProjectRunItem) {
                $out = is_array($item->output_snapshot) ? $item->output_snapshot : (
                    is_string($item->output_snapshot) ? (json_decode($item->output_snapshot, true) ?: []) : []
                );
                foreach (is_array($out['steps'] ?? null) ? $out['steps'] : [] as $step) {
                    if (! is_array($step)) {
                        continue;
                    }
                    $md = trim((string) ($step['outline_markdown'] ?? ''));
                    if ($md !== '') {
                        return $md;
                    }
                    $hook = strtolower((string) ($step['hook_key'] ?? ''));
                    if (str_contains($hook, 'outline') && ! str_contains($hook, 'vocab')) {
                        $md = trim((string) ($step['output'] ?? ''));
                        if ($md !== '') {
                            return $md;
                        }
                    }
                }
            }
        }

        // Fallback: earliest outline-looking sibling on same run.
        if ($projectRunId > 0) {
            $outline = PromptResult::query()
                ->where('run_id', $projectRunId)
                ->where('canonical_prompt_key', 'like', '%outline%')
                ->orderBy('id')
                ->first();
            if ($outline instanceof PromptResult) {
                $md = trim((string) ($outline->output_text ?? ''));
                if ($md !== '' && ! str_contains(strtolower((string) $outline->canonical_prompt_key), 'vocab')) {
                    return $md;
                }
            }
        }

        return '';
    }
}

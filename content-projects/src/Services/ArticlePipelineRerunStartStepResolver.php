<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\Seo\Contracts\SeoProjectWorkflowStepCatalogContract;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use App\Support\RuntimeLogger;

/**
 * Resolve điểm bắt đầu rerun theo semantic kind, không phụ thuộc raw node ID lịch sử.
 *
 * @phpstan-type ResolvedRerunStartStep array{
 *     ok: bool,
 *     seo_task: SeoTask|null,
 *     semantic_key: string,
 *     resolved_node_id: string|null,
 *     source_node_id: string|null,
 *     strategy: string,
 *     warnings: list<string>,
 *     message: string|null,
 *     available_kinds: list<string>
 * }
 */
final class ArticlePipelineRerunStartStepResolver
{
    public const STRATEGY_DIRECT_NODE = 'direct_node';

    public const STRATEGY_SEMANTIC_KIND = 'semantic_kind';

    public const STRATEGY_UNRESOLVED = 'unresolved';

    public function __construct(
        private readonly SeoProjectWorkflowStepCatalogContract $catalog,
    ) {}

    /**
     * @return ResolvedRerunStartStep
     */
    public function resolve(
        SeoProjectTask $projectTask,
        string $fromStep,
        ?string $sourceNodeId = null,
    ): array {
        $semanticKey = $fromStep === ArticlePipelineRerunService::FROM_ARTICLE
            ? 'content'
            : 'outline';
        $sourceNodeId = trim((string) $sourceNodeId);
        $sourceNodeId = $sourceNodeId !== '' ? $sourceNodeId : null;

        $seoTask = $this->catalog->resolveSeoTaskForStepRetry($projectTask);
        $available = $this->availableKinds($projectTask);

        if (! $seoTask instanceof SeoTask) {
            return $this->unresolved(
                $semanticKey,
                $sourceNodeId,
                'Chưa cấu hình quy trình đăng bài / viết lại.',
                $available,
            );
        }

        $nodeIds = $this->promptNodeIds($seoTask);

        if ($sourceNodeId !== null && isset($nodeIds[$sourceNodeId])) {
            $kindAtSource = $this->catalog->findStep($projectTask, $sourceNodeId)['kind'] ?? null;
            if ($kindAtSource === $semanticKey || $kindAtSource === null) {
                return [
                    'ok' => true,
                    'seo_task' => $seoTask,
                    'semantic_key' => $semanticKey,
                    'resolved_node_id' => $sourceNodeId,
                    'source_node_id' => $sourceNodeId,
                    'strategy' => self::STRATEGY_DIRECT_NODE,
                    'warnings' => [],
                    'message' => null,
                    'available_kinds' => $available,
                ];
            }

            // Source node còn nhưng khác semantic — map lại theo kind.
            $warnings = [
                'Source node '.$sourceNodeId.' exists but kind='.(string) $kindAtSource.'; remapped to '.$semanticKey.'.',
            ];
        } else {
            $warnings = $sourceNodeId !== null
                ? ['Source node '.$sourceNodeId.' missing from current workflow; resolving by semantic kind '.$semanticKey.'.']
                : [];
        }

        $resolved = $this->catalog->firstPromptNodeIdForKind($projectTask, $semanticKey);
        if ($resolved === null || $resolved === '') {
            return $this->unresolved(
                $semanticKey,
                $sourceNodeId,
                'Workflow của bài viết đã thay đổi và không còn bước tương ứng. Vui lòng chọn lại bước bắt đầu.',
                $available,
                $warnings,
            );
        }

        // Bảo đảm node resolve thuộc đúng graph ForStepRetry (cùng seoTask).
        if (! isset($nodeIds[$resolved])) {
            return $this->unresolved(
                $semanticKey,
                $sourceNodeId,
                'Workflow của bài viết đã thay đổi và không còn bước tương ứng. Vui lòng chọn lại bước bắt đầu.',
                $available,
                array_merge($warnings, ['Resolved node '.$resolved.' not in step-retry SeoTask graph.']),
            );
        }

        return [
            'ok' => true,
            'seo_task' => $seoTask,
            'semantic_key' => $semanticKey,
            'resolved_node_id' => $resolved,
            'source_node_id' => $sourceNodeId,
            'strategy' => self::STRATEGY_SEMANTIC_KIND,
            'warnings' => $warnings,
            'message' => null,
            'available_kinds' => $available,
        ];
    }

    /**
     * @param  ResolvedRerunStartStep  $resolved
     * @param  array<string, mixed>  $context
     */
    public function logResolution(array $resolved, array $context = []): void
    {
        $payload = array_merge($context, [
            'semantic_key' => $resolved['semantic_key'],
            'resolved_node_id' => $resolved['resolved_node_id'],
            'source_node_id' => $resolved['source_node_id'],
            'strategy' => $resolved['strategy'],
            'warnings' => $resolved['warnings'],
            'available_kinds' => $resolved['available_kinds'],
        ]);

        if ($resolved['ok']) {
            RuntimeLogger::info('seo.article_rerun.start_step_resolved', $payload);

            return;
        }

        RuntimeLogger::warning('seo.article_rerun.start_step_unresolved', $payload);
    }

    /**
     * @return list<string>
     */
    private function availableKinds(SeoProjectTask $projectTask): array
    {
        $kinds = [];
        foreach ($this->catalog->listRerunnableSteps($projectTask) as $step) {
            $kind = trim((string) ($step['kind'] ?? ''));
            if ($kind !== '' && ! in_array($kind, $kinds, true)) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * @return array<string, true>
     */
    private function promptNodeIds(SeoTask $seoTask): array
    {
        $flow = is_array($seoTask->flow_data) ? $seoTask->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $map = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if ((string) ($node['type'] ?? '') !== 'prompt') {
                continue;
            }
            $id = trim((string) ($node['id'] ?? ''));
            if ($id !== '') {
                $map[$id] = true;
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $available
     * @param  list<string>  $warnings
     * @return ResolvedRerunStartStep
     */
    private function unresolved(
        string $semanticKey,
        ?string $sourceNodeId,
        string $message,
        array $available,
        array $warnings = [],
    ): array {
        return [
            'ok' => false,
            'seo_task' => null,
            'semantic_key' => $semanticKey,
            'resolved_node_id' => null,
            'source_node_id' => $sourceNodeId,
            'strategy' => self::STRATEGY_UNRESOLVED,
            'warnings' => $warnings,
            'message' => $message,
            'available_kinds' => $available,
        ];
    }
}
